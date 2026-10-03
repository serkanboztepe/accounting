<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\Money;
use App\Support\PartyStatement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Twilio WhatsApp Sandbox webhook'u.
 *
 * Akış: müteahhit fotoğraf/metin atar → AI çıkarır → teyit metni döner →
 * müteahhit "evet" der → kayıt yazılır. Tür (kind) AI'dan gelir:
 *   expense → Expense; payment/sale/collection → cari hareketi (party_ledger_entries);
 *   balance_query → sadece cevap, kayıt yok.
 * Ödeme (payment) maliyet DEĞİLDİR — borcu düşürür. Carinin açık borcu yoksa
 * "yeni masraf mı, avans mı?" sorulur (çift sayımı önler). Cevap TwiML olarak döner
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

            // Ödeme ama carinin açık borcu yok → "1) yeni masraf 2) avans" seçimi bekleniyor.
            if ($this->needsPaymentChoice($pending->extracted)) {
                if ($body === '1') {
                    // Yeni iş/masraf → ödenmiş gider taslağına çevir; proje/kategori için özet yeniden.
                    $data = $pending->extracted;
                    $data['kind'] = ExpenseExtractor::KIND_EXPENSE;
                    $data['paid'] = true;

                    return $this->twiml($this->saveDraft($pending, $phone, $data));
                }
                if ($body === '2') {
                    $data = $pending->extracted;
                    $data['advance'] = true;
                    $pending->update(['extracted' => $data]);

                    return $this->twiml($this->commit($pending));
                }
                if ($this->isConfirm($body)) {
                    return $this->twiml('Önce seç: *1* yeni masraf, *2* avans.');
                }
            }

            // Sadece sayı → proje menüsünden seçim (yalnız gider taslağında menü gösterilir). Kullanıcı tüm özeti ilk mesajda
            // zaten gördü; numara = "her şey doğru + proje bu" → direkt kaydet (tek adım).
            if (ctype_digit($body) && $this->kind($pending->extracted) === ExpenseExtractor::KIND_EXPENSE) {
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

            // Metin (fotoğrafsız): önceki taslağa düzeltme mi, yoksa tamamen yeni bir kayıt mı?
            // Kararı AI verir (is_new_entry) — kurallar kırılgan. Yeni giderse eski taslağın
            // proje/cari/kategorisi SIZMASIN diye taze taslak açılır.
            if ($image === null && $body !== '') {
                try {
                    $data = $extractor->extract($body, null, $pending->extracted);
                } catch (\Throwable $e) {
                    Log::error('WhatsApp gider düzeltme hatası', ['msg' => $e->getMessage()]);

                    return $this->twiml('Şu an okuyamadım, birazdan tekrar dener misin? 🙏');
                }

                if (! empty($data['is_new_entry'])) {
                    // Yeni gider → eski taslağı kapat ve SIFIRDAN çıkar: previous verilince model
                    // önceki proje/cari'yi sızdırabiliyor; temiz çıkarım için previous=null ile yeniden.
                    $pending->update(['status' => 'superseded']);
                    try {
                        $data = $extractor->extract($body, null, null);
                    } catch (\Throwable $e) {
                        Log::error('WhatsApp yeni gider çıkarma hatası', ['msg' => $e->getMessage()]);

                        return $this->twiml('Şu an okuyamadım, birazdan tekrar dener misin? 🙏');
                    }

                    return $this->twiml($this->saveDraft(null, $phone, $data));
                }

                // Düzeltme/ek bilgi → mevcut taslağı güncelle.
                return $this->twiml($this->saveDraft($pending, $phone, $data));
            }

            // Yeni fotoğraf → yeni gider (düzeltme değil). Eski taslağı geç, aşağıda taze başla.
            if ($image !== null) {
                $pending->update(['status' => 'superseded']);
            }
        }

        // 2) Yeni gider girişi (metin ve/veya fotoğraf)
        if ($image === null && $body === '') {
            return $this->twiml($this->helpText());
        }

        try {
            $data = $extractor->extract($body ?: null, $image);
        } catch (\Throwable $e) {
            Log::error('WhatsApp gider çıkarma hatası', ['msg' => $e->getMessage()]);

            return $this->twiml('Şu an okuyamadım, birazdan tekrar dener misin? 🙏');
        }

        return $this->twiml($this->saveDraft(null, $phone, $data, $numMedia > 0 ? (string) $request->input('MediaUrl0') : null));
    }

    /**
     * Taslağı kaydet/güncelle ve müteahhide gidecek metni döndür.
     * Bakiye sorusu taslak açmaz — direkt cevaplanır.
     */
    private function saveDraft(?WhatsappPendingExpense $pending, string $phone, array $data, ?string $mediaUrl = null): string
    {
        if ($this->kind($data) === ExpenseExtractor::KIND_BALANCE_QUERY) {
            $pending?->update(['status' => 'superseded']);

            return $this->balanceAnswer($data);
        }

        $summary = $this->buildSummary($data);
        $footer = match (true) {
            $this->needsPaymentChoice($data) => "\n\n*1* veya *2* yaz, vazgeçmek için *iptal*.",
            $this->missingParty($data) => "\n\nCari adını yaz, vazgeçmek için *iptal*.",
            default => "\n\n✅ Onaylamak için *evet*, vazgeçmek için *iptal* yaz.",
        };

        if ($pending) {
            $pending->update(['extracted' => $data, 'summary' => $summary]);
        } else {
            WhatsappPendingExpense::create([
                'phone' => $phone,
                'extracted' => $data,
                'summary' => $summary,
                'media_url' => $mediaUrl,
                'status' => 'awaiting_confirmation',
            ]);
        }

        return $summary . $footer;
    }

    /**
     * Onaylanan taslaktan kaydı oluştur (türe göre Expense veya cari hareketi).
     */
    private function commit(WhatsappPendingExpense $pending): string
    {
        $d = $pending->extracted;
        $kind = $this->kind($d);

        if ($kind !== ExpenseExtractor::KIND_EXPENSE) {
            if ($this->missingParty($d)) {
                return '❓ Kime/kimden olduğunu yazar mısın? (cari adı)';
            }
            if ($this->needsPaymentChoice($d)) {
                return 'Önce seç: *1* yeni masraf, *2* avans.';
            }

            return $this->commitLedger($pending, $d, $kind);
        }

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
            'due_date' => $d['due_date'] ?? null,
            'amount' => Money::store((float) ($d['amount'] ?? 0)),
            'payment_status' => ($d['paid'] ?? true) ? 'paid' : 'unpaid',
            'description' => $d['description'] ?? null,
            'notes' => 'WhatsApp üzerinden girildi.',
        ]);

        $pending->update(['status' => 'confirmed']);

        return '✅ Kaydedildi: ' . Money::format((float) ($d['amount'] ?? 0)) . ' ₺ — ' . ($d['description'] ?? 'gider');
    }

    /**
     * Ödeme / satış / tahsilat → cari hareketi. Maliyete GİRMEZ; sadece cari bakiyesini değiştirir.
     */
    private function commitLedger(WhatsappPendingExpense $pending, array $d, string $kind): string
    {
        $party = $this->resolveParty($d);
        $projectId = $d['project_id'] ?? null;
        if ($projectId === null && ! empty($d['project_name'])) {
            $projectId = Project::firstOrCreate(['name' => $d['project_name']], ['status' => 'active'])->id;
        }

        $type = self::LEDGER_TYPES[$kind];
        $description = $d['description'] ?? null;
        if ($kind === ExpenseExtractor::KIND_PAYMENT && ! empty($d['advance'])) {
            $description = 'Avans' . ($description ? ' — ' . $description : '');
        }

        // Satışta birden fazla iş sayıldıysa her biri ayrı satır; yoksa tek satır.
        $rows = ($kind === ExpenseExtractor::KIND_SALE && ! empty($d['items']))
            ? $d['items']
            : [['description' => $description, 'amount' => (float) ($d['amount'] ?? 0)]];

        foreach ($rows as $row) {
            PartyLedgerEntry::create([
                'party_id' => $party->id,
                'project_id' => $projectId,
                'entry_date' => $d['date'] ?? now()->format('Y-m-d'),
                'type' => $type,
                'payment_type' => $kind === ExpenseExtractor::KIND_SALE ? null : ($d['payment_type'] ?? null),
                'description' => $row['description'] ?: null,
                'amount' => Money::store((float) $row['amount']),
                'notes' => 'WhatsApp üzerinden girildi.',
            ]);
        }

        $pending->update(['status' => 'confirmed']);

        return '✅ Kaydedildi. ' . $this->balanceLine($party->name, PartyStatement::build($party)['balance']);
    }

    private const LEDGER_TYPES = [
        ExpenseExtractor::KIND_PAYMENT => PartyLedgerEntry::TYPE_PAYMENT,
        ExpenseExtractor::KIND_SALE => PartyLedgerEntry::TYPE_SALE,
        ExpenseExtractor::KIND_COLLECTION => PartyLedgerEntry::TYPE_COLLECTION,
    ];

    /**
     * Teyit metni — tür gider değilse cari hareketi özeti (yön + bakiye öncesi/sonrası).
     */
    private function buildSummary(array $d): string
    {
        $kind = $this->kind($d);
        if ($kind !== ExpenseExtractor::KIND_EXPENSE) {
            return $this->buildLedgerSummary($d, $kind);
        }

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

        if (! empty($d['due_date'])) {
            $lines[] = '• Vade: ' . $d['due_date'];
        }

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
     * Ödeme/satış/tahsilat teyidi. Yön açıkça yazılır ("Sen → Ahmet" / "Ahmet → sana") —
     * "ödedim" ile "ödedi" tek harf; AI yanlış anlarsa müteahhit burada görür.
     */
    private function buildLedgerSummary(array $d, string $kind): string
    {
        $amount = (float) ($d['amount'] ?? 0);
        $party = $this->existingParty($d);
        $name = $party?->name ?? ($d['party_name'] ?? null);

        if ($name === null) {
            return '❓ ' . ($d['question'] ?: 'Kime/kimden olduğunu yazar mısın? (cari adı)');
        }

        $label = $party ? $name : $name . ' (yeni cari)';
        $before = $party ? PartyStatement::build($party)['balance'] : 0.0;
        // Ekstre konvansiyonu: bakiye > 0 → cari bize borçlu, < 0 → biz cariye borçluyuz.
        $after = match ($kind) {
            ExpenseExtractor::KIND_PAYMENT => $before + $amount,
            ExpenseExtractor::KIND_SALE => $before + $amount,
            ExpenseExtractor::KIND_COLLECTION => $before - $amount,
        };

        $lines = [];
        if ($kind === ExpenseExtractor::KIND_PAYMENT) {
            $lines[] = '💸 *Sen* → ' . $label . ': ' . Money::format($amount) . ' ₺ ödedin';
            if ($this->needsPaymentChoice($d)) {
                $lines[] = 'Bu cariye açık borcun görünmüyor. Bu ne için?';
                $lines[] = '   1) Yeni iş / malzeme (masraf olarak yazılır)';
                $lines[] = '   2) Avans (iş sonra yapılacak)';

                return implode("\n", $lines);
            }
        } elseif ($kind === ExpenseExtractor::KIND_SALE) {
            $lines[] = '🧾 *Satış* → ' . $label . ': ' . Money::format($amount) . ' ₺ iş yaptın';
            foreach ($d['items'] ?? [] as $item) {
                $lines[] = '   • ' . ($item['description'] ?: '—') . ': ' . Money::format((float) $item['amount']) . ' ₺';
            }
        } else {
            $lines[] = '💰 ' . $label . ' → *sana*: ' . Money::format($amount) . ' ₺ ödedi';
        }

        if (! empty($d['description']) && $kind !== ExpenseExtractor::KIND_SALE) {
            $lines[] = '• Açıklama: ' . $d['description'];
        }
        $lines[] = '• Tarih: ' . ($d['date'] ?? now()->format('Y-m-d'));
        if (! empty($d['payment_type'])) {
            $lines[] = '• Şekli: ' . (self::PAYMENT_TYPE_LABELS[$d['payment_type']] ?? $d['payment_type']);
        }
        $project = ! empty($d['project_id']) ? Project::find($d['project_id']) : null;
        $projectText = $project?->name ?? (! empty($d['project_name']) ? $d['project_name'] . ' (yeni)' : null);
        if ($projectText) {
            $lines[] = '• Proje: ' . $projectText;
        }
        $lines[] = '• Bakiye: ' . $this->balancePhrase($before) . ' → ' . $this->balancePhrase($after);

        return implode("\n", $lines);
    }

    private const PAYMENT_TYPE_LABELS = [
        'cash' => 'Nakit',
        'bank_transfer' => 'Havale',
        'eft' => 'EFT',
        'other' => 'Diğer',
    ];

    /** "Ahmet'e ne kadar borcum var?" → kayıt açmadan cevap. */
    private function balanceAnswer(array $d): string
    {
        $party = $this->existingParty($d);
        if (! $party) {
            $name = $d['party_name'] ?? null;

            return $name
                ? '"' . $name . '" adında bir cari bulamadım.'
                : 'Hangi carinin bakiyesini soruyorsun?';
        }

        return '📊 ' . $this->balanceLine($party->name, PartyStatement::build($party)['balance']);
    }

    private function balanceLine(string $name, float $balance): string
    {
        if (abs($balance) < 0.01) {
            return $name . ': hesap kapalı (bakiye 0).';
        }

        return $balance < 0
            ? $name . ': borcun ' . Money::format(abs($balance)) . ' ₺'
            : $name . ': sana borcu ' . Money::format($balance) . ' ₺';
    }

    private function balancePhrase(float $balance): string
    {
        if (abs($balance) < 0.01) {
            return '0';
        }

        return $balance < 0
            ? 'borcun ' . Money::format(abs($balance))
            : 'alacağın ' . Money::format($balance);
    }

    /** Boş mesaja cevap — yalnız bu kurulumda açık işlemleri örnekler (mimar'a "gider" denmesin). */
    private function helpText(): string
    {
        $examples = [
            ExpenseExtractor::KIND_EXPENSE => "• Gider: \"Kuşak Beton'dan 50 bin beton aldım\" (ya da fiş/dekont fotoğrafı)",
            ExpenseExtractor::KIND_PAYMENT => "• Ödeme: \"Ahmet ustaya 100 bin ödedim\"",
            ExpenseExtractor::KIND_SALE => "• Satış: \"Ahmet Bey'e 80 bine proje yaptım\"",
            ExpenseExtractor::KIND_COLLECTION => "• Tahsilat: \"Ahmet Bey 50 bin ödedi\"",
            ExpenseExtractor::KIND_BALANCE_QUERY => "• Bakiye: \"Ahmet Bey'in borcu ne?\"",
        ];

        $lines = ['Şunları yazabilirsin:'];
        foreach (ExpenseExtractor::allowedKinds() as $kind) {
            $lines[] = $examples[$kind];
        }

        return implode("\n", $lines);
    }

    private function kind(array $d): string
    {
        return $d['kind'] ?? ExpenseExtractor::KIND_EXPENSE;
    }

    private function existingParty(array $d): ?Party
    {
        return ! empty($d['party_id']) ? Party::find($d['party_id']) : null;
    }

    private function resolveParty(array $d): Party
    {
        return $this->existingParty($d) ?? Party::firstOrCreate(['name' => $d['party_name']]);
    }

    private function missingParty(array $d): bool
    {
        return $this->kind($d) !== ExpenseExtractor::KIND_EXPENSE
            && ! $this->existingParty($d)
            && empty($d['party_name']);
    }

    /**
     * Ödeme ama cariye açık borcumuz yok (yeni cari dahil) → bu para yeni bir masraf mı,
     * avans mı? Sormadan yazarsak ya maliyet kaçar ya da çift sayılır.
     */
    private function needsPaymentChoice(array $d): bool
    {
        if ($this->kind($d) !== ExpenseExtractor::KIND_PAYMENT || ! empty($d['advance']) || $this->missingParty($d)) {
            return false;
        }
        $party = $this->existingParty($d);

        return ! $party || PartyStatement::build($party)['balance'] > -0.01;
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
