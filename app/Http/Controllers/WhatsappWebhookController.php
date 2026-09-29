<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Party;
use App\Models\Project;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Twilio WhatsApp Sandbox webhook'u (Faz 0 — sadece giderler).
 *
 * Akış: müteahhit fotoğraf/metin atar → AI çıkarır → teyit metni döner →
 * müteahhit "evet" der → Expense kaydı yazılır. Cevap TwiML olarak döner
 * (aynı sohbette görünür, REST gönderime gerek yok).
 */
class WhatsappWebhookController extends Controller
{
    public function __invoke(Request $request, ExpenseExtractor $extractor)
    {
        $phone = (string) $request->input('From', '');
        $body = trim((string) $request->input('Body', ''));
        $numMedia = (int) $request->input('NumMedia', 0);

        // 1) Onay / iptal — bekleyen taslağa cevap mı? Yalnızca SON 30 DK içindeki taslak
        // "önceki" sayılır; terk edilmiş eski taslaklar yeni mesaja bağlam olup proje/cari
        // sızdırmasın (updated_at: aktif gidip gelme penceresi taze tutar).
        $pending = WhatsappPendingExpense::where('phone', $phone)
            ->where('status', 'awaiting_confirmation')
            ->where('updated_at', '>=', now()->subMinutes(30))
            ->latest('id')
            ->first();

        $image = $numMedia > 0 ? $this->downloadMedia($request) : null;

        if ($pending) {
            if ($this->isConfirm($body)) {
                return $this->twiml($this->commit($pending));
            }
            if ($this->isCancel($body)) {
                $pending->update(['status' => 'cancelled']);

                return $this->twiml('İptal edildi. ✖️');
            }

            // Sadece sayı → proje menüsünden seçim. Kullanıcı tüm özeti ilk mesajda
            // zaten gördü; numara = "her şey doğru + proje bu" → direkt kaydet (tek adım).
            if (ctype_digit($body)) {
                $projects = $this->activeProjects();
                $chosen = $projects->get((int) $body - 1);
                if ($chosen) {
                    $data = $pending->extracted;
                    $data['project_id'] = $chosen->id;
                    $data['project_name'] = null;
                    $pending->update(['extracted' => $data]);

                    return $this->twiml($this->commit($pending));
                }

                return $this->twiml('Listede o numara yok. Tekrar bir numara yaz ya da proje adını yaz.');
            }

            // Metin (fotoğrafsız): önceki taslağa düzeltme mi, yoksa tamamen yeni bir gider mi?
            // Kararı AI verir (is_new_expense) — kurallar kırılgan. Yeni giderse eski taslağın
            // proje/cari/kategorisi SIZMASIN diye taze taslak açılır.
            if ($image === null && $body !== '') {
                try {
                    $data = $extractor->extract($body, null, $pending->extracted);
                } catch (\Throwable $e) {
                    Log::error('WhatsApp gider düzeltme hatası', ['msg' => $e->getMessage()]);

                    return $this->twiml('Şu an okuyamadım, birazdan tekrar dener misin? 🙏');
                }

                if (! empty($data['is_new_expense'])) {
                    // Yeni gider → eski taslağı kapat ve SIFIRDAN çıkar: previous verilince model
                    // önceki proje/cari'yi sızdırabiliyor; temiz çıkarım için previous=null ile yeniden.
                    $pending->update(['status' => 'superseded']);
                    try {
                        $data = $extractor->extract($body, null, null);
                    } catch (\Throwable $e) {
                        Log::error('WhatsApp yeni gider çıkarma hatası', ['msg' => $e->getMessage()]);

                        return $this->twiml('Şu an okuyamadım, birazdan tekrar dener misin? 🙏');
                    }

                    $summary = $this->buildSummary($data);
                    WhatsappPendingExpense::create([
                        'phone' => $phone,
                        'extracted' => $data,
                        'summary' => $summary,
                        'status' => 'awaiting_confirmation',
                    ]);

                    return $this->twiml($summary . "\n\n✅ Onaylamak için *evet*, vazgeçmek için *iptal* yaz.");
                }

                // Düzeltme/ek bilgi → mevcut taslağı güncelle.
                $summary = $this->buildSummary($data);
                $pending->update(['extracted' => $data, 'summary' => $summary]);

                return $this->twiml($summary . "\n\n✅ Onaylamak için *evet*, vazgeçmek için *iptal* yaz.");
            }

            // Yeni fotoğraf → yeni gider (düzeltme değil). Eski taslağı geç, aşağıda taze başla.
            if ($image !== null) {
                $pending->update(['status' => 'superseded']);
            }
        }

        // 2) Yeni gider girişi (metin ve/veya fotoğraf)
        if ($image === null && $body === '') {
            return $this->twiml('Bir gider yazabilir veya fiş/dekont fotoğrafı gönderebilirsin. 📄');
        }

        try {
            $data = $extractor->extract($body ?: null, $image);
        } catch (\Throwable $e) {
            Log::error('WhatsApp gider çıkarma hatası', ['msg' => $e->getMessage()]);

            return $this->twiml('Şu an okuyamadım, birazdan tekrar dener misin? 🙏');
        }

        $summary = $this->buildSummary($data);

        WhatsappPendingExpense::create([
            'phone' => $phone,
            'extracted' => $data,
            'summary' => $summary,
            'media_url' => $numMedia > 0 ? (string) $request->input('MediaUrl0') : null,
            'status' => 'awaiting_confirmation',
        ]);

        return $this->twiml($summary . "\n\n✅ Onaylamak için *evet*, vazgeçmek için *iptal* yaz.");
    }

    /**
     * Onaylanan taslaktan Expense oluştur.
     */
    private function commit(WhatsappPendingExpense $pending): string
    {
        $d = $pending->extracted;

        // Match-first; if no existing match but the AI proposed a name, create it on confirm
        // (firstOrCreate guards against duplicates).
        $projectId = $d['project_id'] ?? null;
        if ($projectId === null && ! empty($d['project_name'])) {
            $projectId = Project::firstOrCreate(['name' => $d['project_name']], ['status' => 'active'])->id;
        }

        $partyId = $d['party_id'] ?? null;
        if ($partyId === null && ! empty($d['party_name'])) {
            $partyId = Party::firstOrCreate(['name' => $d['party_name']])->id;
        }

        $categoryId = $d['category_id'] ?? null;
        if ($categoryId === null && ! empty($d['category_name'])) {
            $categoryId = ExpenseCategory::firstOrCreate(['name' => $d['category_name']])->id;
        }

        Expense::create([
            'project_id' => $projectId,
            'party_id' => $partyId,
            'expense_category_id' => $categoryId,
            'expense_date' => $d['date'] ?? now()->format('Y-m-d'),
            'amount' => Money::store((float) ($d['amount'] ?? 0)),
            'payment_status' => ($d['paid'] ?? true) ? 'paid' : 'unpaid',
            'description' => $d['description'] ?? null,
            'notes' => 'WhatsApp üzerinden girildi.',
        ]);

        $pending->update(['status' => 'confirmed']);

        return '✅ Kaydedildi: ' . Money::format((float) ($d['amount'] ?? 0)) . ' ₺ — ' . ($d['description'] ?? 'gider');
    }

    /**
     * Teyit metni — id'leri okunur isme çevirir.
     */
    private function buildSummary(array $d): string
    {
        $lines = [];
        $lines[] = '📝 *Gider — kontrol et:*';
        $lines[] = '• Tutar: ' . Money::format((float) ($d['amount'] ?? 0)) . ' ₺'
            . (($d['confidence'] ?? 'high') === 'low' ? '  ⚠️ (tutardan emin değilim, doğrular mısın?)' : '');
        $lines[] = '• Açıklama: ' . ($d['description'] ?: '—');
        $lines[] = '• Tarih: ' . ($d['date'] ?? now()->format('Y-m-d'));

        $project = $d['project_id'] ? Project::find($d['project_id']) : null;
        $projectText = $project?->name
            ?? (! empty($d['project_name']) ? $d['project_name'] . ' (yeni)' : '—');
        $lines[] = '• Proje: ' . $projectText;

        $party = $d['party_id'] ? Party::find($d['party_id']) : null;
        $partyText = $party?->name
            ?? (! empty($d['party_name']) ? $d['party_name'] . ' (yeni)' : '—');
        $lines[] = '• Cari: ' . $partyText;

        $category = $d['category_id'] ? ExpenseCategory::find($d['category_id']) : null;
        $categoryText = $category?->name
            ?? (! empty($d['category_name']) ? $d['category_name'] . ' (yeni)' : '—');
        $lines[] = '• Kategori: ' . $categoryText;

        $lines[] = '• Durum: ' . (($d['paid'] ?? true) ? 'Ödendi' : 'Ödenmedi (borç)');

        // Proje boşsa numaralı menü — müteahhit sadece "2" yazsın.
        if (! $project && empty($d['project_name'])) {
            $projects = $this->activeProjects();
            if ($projects->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '❓ Hangi proje? Numarayı yaz:';
                foreach ($projects as $i => $p) {
                    $lines[] = '   ' . ($i + 1) . ') ' . $p->name;
                }
                $lines[] = '(bu gider projesizse boş geç, direkt *evet* yaz)';
            }
        }

        if (! empty($d['question'])) {
            $lines[] = '';
            $lines[] = '❓ ' . $d['question'] . ' (cevabını yazıp tekrar gönderebilirsin)';
        }

        return implode("\n", $lines);
    }

    /**
     * Twilio medyasını indirip base64'e çevirir (medya erişimi basic auth ister).
     *
     * @return array{media_type:string,data:string}|null
     */
    private function downloadMedia(Request $request): ?array
    {
        $url = (string) $request->input('MediaUrl0', '');
        $type = (string) $request->input('MediaContentType0', 'image/jpeg');
        if ($url === '' || ! Str::startsWith($type, 'image/')) {
            return null;
        }

        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');

        $resp = Http::withBasicAuth((string) $sid, (string) $token)->timeout(30)->get($url);
        if ($resp->failed()) {
            Log::warning('Twilio medya indirilemedi', ['status' => $resp->status()]);

            return null;
        }

        return [
            'media_type' => $type,
            'data' => base64_encode($resp->body()),
        ];
    }

    /**
     * Menüde gösterilecek/eşleştirilecek projeler — sabit sıra (id) ki "2" hep aynı projeyi seçsin.
     */
    private function activeProjects(): \Illuminate\Support\Collection
    {
        return Project::whereNotIn('status', ['cancelled'])
            ->orderBy('id')
            ->take(30)
            ->get(['id', 'name'])
            ->values();
    }

    private function isConfirm(string $body): bool
    {
        return in_array(Str::lower($body), ['evet', 'onayla', 'tamam', 'ok', 'e', 'onay'], true);
    }

    private function isCancel(string $body): bool
    {
        return in_array(Str::lower($body), ['iptal', 'hayır', 'hayir', 'vazgeç', 'vazgec', 'h'], true);
    }

    private function twiml(string $message)
    {
        $escaped = htmlspecialchars($message, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return response(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response><Message>{$escaped}</Message></Response>",
            200,
            ['Content-Type' => 'text/xml']
        );
    }
}
