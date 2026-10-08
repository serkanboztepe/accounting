<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractPayment;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\HubRouter;
use App\Support\CheckReminders;
use App\Support\ContractStatus;
use App\Support\Money;
use App\Support\PartyBalances;
use App\Support\PartyStatement;
use App\Support\Phone;
use App\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
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

        // Hub (APP_ROLE=hub) veya tek panel: önce telefonun firmasını bul. Tek panelde router
        // firmayı açıp buraya geri çağırır (via_hub) — o zaman aşağıdaki firma akışı çalışır.
        if ((config('app.role') === 'hub' || Tenancy::enabled()) && ! $request->attributes->get('via_hub')) {
            return app(HubRouter::class)->forward($request);
        }

        // 0) Sadece kayıtlı telefonlar — yabancı numara AI'ya da veriye de ulaşmasın.
        if (! $this->isAllowedPhone($request, $phone)) {
            return HubRouter::unknownPhoneResponse($phone);
        }

        // "çekler" — vadesi yaklaşan çek listesi (hatırlatmanın devamı). AI'sız, doğrudan veriden.
        if ($numMedia === 0 && config('modules.checks')
            && in_array($this->word($body), ['çekler', 'cekler', 'çeklerim', 'ceklerim', 'çek', 'cek'], true)) {
            return $this->twiml(CheckReminders::listText());
        }

        // 1) Onay / iptal — bekleyen taslağa cevap mı? Yalnızca SON 30 DK içindeki taslak
        // "önceki" sayılır; terk edilmiş eski taslaklar yeni mesaja bağlam olup proje/cari
        // sızdırmasın (updated_at: aktif gidip gelme penceresi taze tutar).
        $pending = WhatsappPendingExpense::where('phone', $phone)
            ->where('status', 'awaiting_confirmation')
            ->where('updated_at', '>=', now()->subMinutes(30))
            ->latest('id')
            ->first();

        // Ses / video / belge: şimdilik okuyamıyoruz — sessizce yardım metni yerine açıkça söyle.
        // (Sesi yazıya çevirme planlı; bkz. hafıza: ses kaydı desteği.)
        $mediaType = (string) $request->input('MediaContentType0', '');
        if ($numMedia > 0 && $mediaType !== '' && ! Str::startsWith($mediaType, 'image/')) {
            return $this->twiml(Str::startsWith($mediaType, 'audio/')
                ? "🎤 Sesli mesajları henüz dinleyemiyorum. Yazıyla gönderir misin? 🙏\nÖrnek: \"Ali'ye 15 bin verdim\""
                : "Şu an yazı ve fotoğraf okuyabiliyorum. Fişin/faturanın fotoğrafını çekip gönderir misin? 📷");
        }

        // Hızlı cevaplar (evet/iptal/numara) dışında AI çağrılacak → "yazıyor…" göster.
        $quickReply = $numMedia === 0 && ($this->isConfirm($body) || $this->isCancel($body) || ctype_digit($body));
        if (! $quickReply && ($body !== '' || $numMedia > 0)) {
            $this->sendTypingIndicator((string) $request->input('MessageSid', ''));
        }

        $image = $numMedia > 0 ? $this->downloadMedia($request) : null;

        // Bekleyen taslak yokken gelen "evet"/"iptal"/numara: yeni kayıt SANMA (0 ₺'lik gider açılıyordu).
        if (! $pending && $image === null && ($this->isConfirm($body) || $this->isCancel($body) || ctype_digit($body))) {
            return $this->twiml('Onay bekleyen bir kayıt yok (taslaklar 30 dk geçerli). Yeni bir işlem yazabilirsin.');
        }

        if ($pending) {
            if ($this->isConfirm($body)) {
                return $this->twiml($this->commit($pending));
            }
            if ($this->isCancel($body)) {
                $pending->update(['status' => 'cancelled']);

                return $this->twiml('İptal edildi. ✖️');
            }

            // Sözleşmeli cariye ödeme, birden fazla aktif sözleşme → hangisi?
            if ($this->needsContractChoice($pending->extracted) && ctype_digit($body)) {
                $contract = $this->activeContracts($this->existingParty($pending->extracted))->get((int) $body - 1);
                if (! $contract) {
                    return $this->twiml('Listede o numara yok. Sözleşme numarasını yaz.');
                }
                $data = $pending->extracted;
                $data['contract_id'] = $contract->id;

                return $this->twiml($this->saveDraft($pending, $phone, $data));
            }

            // Ödeme ama carinin açık borcu yok → "1) yeni masraf 2) avans" seçimi bekleniyor.
            if ($this->needsPaymentChoice($pending->extracted)) {
                if ($body === '1') {
                    // Yeni iş/masraf → ödenmiş gider taslağı. Önce "ne için?" sorulur: yoksa açıklama
                    // sadece "Ali'ye ödeme" kalıyor, işin ne olduğu/kategorisi görünmüyordu. Cevap
                    // düzeltme (refine) olarak işlenir → açıklama + kategori dolar, sonra özet.
                    $data = $pending->extracted;
                    $data['kind'] = ExpenseExtractor::KIND_EXPENSE;
                    $data['paid'] = true;
                    $pending->update(['extracted' => $data]);

                    return $this->twiml("Ne için ödedin? Kısaca yaz (ör. \"sıva işçiliği\", \"kaba inşaat\").\nAtlamak için *evet*.");
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
            if (config('modules.projects') && ctype_digit($body) && $this->kind($pending->extracted) === ExpenseExtractor::KIND_EXPENSE) {
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

                // Düzeltme/ek bilgi → mevcut taslağı güncelle. AI'ın bilmediği seçimler (sözleşme,
                // avans) aynı cari için korunur — "nakit" diye düzeltince sözleşme tekrar sorulmasın.
                if (($data['party_id'] ?? null) === ($pending->extracted['party_id'] ?? null)) {
                    $data += array_intersect_key($pending->extracted, ['contract_id' => true, 'advance' => true]);
                }

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
    private function saveDraft(?WhatsappPendingExpense $pending, string $phone, array $data, ?string $mediaUrl = null): string|array
    {
        if ($this->kind($data) === ExpenseExtractor::KIND_BALANCE_QUERY) {
            $pending?->update(['status' => 'superseded']);

            return $this->balanceAnswer($data);
        }
        if ($this->kind($data) === ExpenseExtractor::KIND_TOTALS_QUERY) {
            $pending?->update(['status' => 'superseded']);

            return $this->totalsAnswer($data);
        }
        if ($this->kind($data) === ExpenseExtractor::KIND_STATEMENT) {
            $pending?->update(['status' => 'superseded']);

            return $this->statementAnswer($data);
        }

        // Tutarsız kayıt taslağı açma (anlaşılmayan mesaj 0 ₺'lik gider oluyordu). Bekleyen taslağa dokunma.
        if ((float) ($data['amount'] ?? 0) <= 0) {
            return '❓ ' . ($data['question']
                ?: "Tutarı anlayamadım. Örnek: \"Kuşak Beton'dan 50 bin beton aldım\"");
        }

        $summary = $this->buildSummary($data);
        $footer = match (true) {
            $this->needsContractChoice($data) => "\n\nSözleşme numarasını yaz, vazgeçmek için *iptal*.",
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
            if ($this->needsContractChoice($d)) {
                return 'Önce hangi sözleşmeye ödendiğini seç (numara yaz).';
            }
            if ($this->needsPaymentChoice($d)) {
                return 'Önce seç: *1* yeni masraf, *2* avans.';
            }
            if ($kind === ExpenseExtractor::KIND_PAYMENT && $this->paymentContract($d)) {
                return $this->commitContractPayment($pending, $d, $this->paymentContract($d));
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
            'payment_status' => $this->isPaid($d) ? 'paid' : 'unpaid',
            'description' => $d['description'] ?? null,
            'notes' => 'WhatsApp üzerinden girildi.',
        ]);

        $pending->update(['status' => 'confirmed']);

        return '✅ Kaydedildi: ' . Money::format((float) ($d['amount'] ?? 0)) . ' ₺ — ' . ($d['description'] ?? 'gider');
    }

    /**
     * Sözleşmeli cariye ödeme → sözleşme ödemesi (contract_payments). Böylece sözleşme raporundaki
     * "Kalan Bakiye", dashboard ve cari ekstre AYNI rakamı gösterir (cari hareketine yazılsaydı
     * sadece ekstre düşerdi). Sözleşme oluşturma panelden; ödeme WhatsApp'tan.
     */
    private function commitContractPayment(WhatsappPendingExpense $pending, array $d, Contract $contract): string
    {
        if (($d['payment_type'] ?? null) === 'other') {
            return 'Çekle/senetle sözleşme ödemesini şimdilik panelden gir (Sözleşme → Ödemeler): vade ve çek no gerekiyor. Nakit/havale ise *nakit* ya da *havale* yaz.';
        }

        ContractPayment::create([
            'contract_id' => $contract->id,
            'payment_date' => $d['date'] ?? now()->format('Y-m-d'),
            'payment_type' => $this->contractPaymentType($d),
            'status' => 'paid',
            'amount' => Money::store((float) ($d['amount'] ?? 0)),
            'notes' => trim(($d['description'] ?? '') . ' (WhatsApp üzerinden girildi.)'),
        ]);

        $pending->update(['status' => 'confirmed']);

        return '✅ Kaydedildi. ' . $contract->title . ' — kalan: '
            . Money::format($contract->remainingPaymentAmount()) . ' ₺';
    }

    /** WhatsApp ödeme şekli → sözleşme ödeme tipi (Nakit / Havale-EFT). Söylenmediyse havale. */
    private function contractPaymentType(array $d): string
    {
        return ($d['payment_type'] ?? null) === 'cash' ? 'cash' : 'bank_transfer';
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

        // Projesiz kurulumda (alacak-verecek) proje satırı ve proje menüsü yok.
        $usesProjects = (bool) config('modules.projects');
        $project = $d['project_id'] ? Project::find($d['project_id']) : null;
        if ($usesProjects) {
            $projectText = $project?->name
                ?? (! empty($d['project_name']) ? $d['project_name'] . ' (yeni)' : '—');
            $lines[] = '• Proje: ' . $projectText;
        }

        $party = $d['party_id'] ? Party::find($d['party_id']) : null;
        $partyText = $party?->name
            ?? (! empty($d['party_name']) ? $d['party_name'] . ' (yeni)' : '—');
        $lines[] = '• Cari: ' . $partyText;

        $category = $d['category_id'] ? ExpenseCategory::find($d['category_id']) : null;
        $categoryText = $category?->name
            ?? (! empty($d['category_name']) ? $d['category_name'] . ' (yeni)' : '—');
        $lines[] = '• Kategori: ' . $categoryText;

        $lines[] = '• Durum: ' . ($this->isPaid($d) ? 'Ödendi' : 'Ödenmedi (borç)')
            . (($d['paid'] ?? null) === null ? '  (değilse *ödendi* / *ödenmedi* yaz)' : '');

        if (! empty($d['due_date'])) {
            $lines[] = '• Vade: ' . $d['due_date'];
        }

        // Sözleşmeli cariye gider: işin maliyeti zaten sözleşmede → çift maliyet riski.
        if ($party && $this->activeContracts($party)->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '⚠️ ' . $party->name . ' ile sözleşmen var. Bu, sözleşmedeki işin parasıysa *iptal* yaz ve '
                . 'ödeme olarak gönder (ör. "… ödedim"), yoksa maliyet iki kez sayılır. Sözleşme dışı ek işse devam et.';
        }

        // Proje boşsa numaralı menü — müteahhit sadece "2" yazsın.
        if ($usesProjects && ! $project && empty($d['project_name'])) {
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
            if ($this->needsContractChoice($d)) {
                $lines[] = $name . ' ile birden fazla sözleşmen var. Hangisine ödedin?';
                foreach ($this->activeContracts($party) as $i => $c) {
                    $lines[] = '   ' . ($i + 1) . ') ' . $c->title . ' — kalan ' . Money::format($c->remainingPaymentAmount());
                }

                return implode("\n", $lines);
            }
            if ($contract = $this->paymentContract($d)) {
                $remaining = $contract->remainingPaymentAmount();
                $lines[] = '📄 Sözleşme: ' . $contract->title . ' (kalan ' . Money::format($remaining)
                    . ' → ' . Money::format(max(0, $remaining - $amount)) . ')';
                $lines[] = '• Şekli: ' . ContractPayment::PAYMENT_TYPES[$this->contractPaymentType($d)]
                    . (empty($d['payment_type']) ? '  (nakitse *nakit* yaz)' : '');
                if ($amount > $remaining + 0.01) {
                    $lines[] = '⚠️ Ödeme sözleşmenin kalanından fazla.';
                }
            }
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
        if (! empty($d['payment_type']) && ! $this->paymentContract($d)) {
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

        $cari = $this->balanceLine($party->name, PartyStatement::build($party)['balance']);
        $contracts = config('modules.contracts') ? ContractStatus::lines($party) : [];

        if ($contracts === []) {
            return '📊 ' . $cari;
        }

        // Sözleşmeli cari: cari ekstresi sözleşme ödeme/teslimatını içermez — ikisini ayrı göster.
        return '📊 *' . $party->name . "*\n"
            . implode("\n", $contracts)
            . "\n\n💼 Cari hesap (sözleşme dışı): " . lcfirst(Str::after($cari, $party->name . ': '));
    }

    /**
     * "Ali'nin ekstresini at" → kısa özet + PDF eki. PDF linki imzalı ve 10 dk geçerli
     * (Twilio indirebilsin, başkası tahmin edemesin / sonra açamasın).
     *
     * @return array{0:string,1:string}|string  [metin, medya URL] ya da sadece metin
     */
    private function statementAnswer(array $d): array|string
    {
        $party = $this->existingParty($d);
        if (! $party) {
            $name = $d['party_name'] ?? null;

            return $name
                ? '"' . $name . '" adında bir cari bulamadım.'
                : 'Hangi carinin ekstresini istiyorsun?';
        }

        $filters = array_filter([
            'date_from' => $d['date_from'] ?? null,
            'date_to' => $d['date_to'] ?? null,
            'project_id' => $d['project_id'] ?? null,
        ]);
        $statement = PartyStatement::build($party, $filters);

        $lines = ['📄 ' . $party->name . ' — Cari Ekstresi'];
        if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
            $lines[] = 'Dönem: ' . ($filters['date_from'] ?? '…') . ' – ' . ($filters['date_to'] ?? '…');
        }
        if (! empty($filters['project_id'])) {
            $lines[] = 'Proje: ' . (Project::find($filters['project_id'])?->name ?? '—');
            $lines[] = 'Bu projedeki fark: ' . Money::format(abs($statement['balance'])) . ' ₺';
        } else {
            $lines[] = $this->balanceLine($party->name, $statement['balance']);
        }

        $url = URL::temporarySignedRoute('whatsapp.statement.pdf', now()->addMinutes(10), [
            'party' => $party->id,
            'file' => 'Ekstre-' . (Str::slug($party->name) ?: $party->id) . '.pdf',
        ] + $filters + array_filter(['firm' => Tenancy::current()?->id])); // tek panel: Twilio oturumsuz indirir, firma imzalı linkte

        return [implode("\n", $lines), $url];
    }

    /**
     * "Toplam alacağım ne kadar?" → tüm carilerin ekstre bakiyesi. Alacak ve borç AYRI toplanır,
     * netleştirilmez (Ali'den alacak ile Kuşak Beton'a borç farklı hesaplar).
     */
    private function totalsAnswer(array $d): string
    {
        $filters = array_filter(['project_id' => $d['project_id'] ?? null]);
        $side = $d['totals_side'] ?? 'both';
        $project = ! empty($filters['project_id']) ? Project::find($filters['project_id']) : null;

        $sections = [];
        if ($side !== 'payable') {
            $sections[] = $this->totalsSection('Toplam alacağın', PartyBalances::receivables($filters));
        }
        if ($side !== 'receivable') {
            $sections[] = $this->totalsSection('Toplam borcun', PartyBalances::payables($filters));
        }

        $head = '📊' . ($project ? ' ' . $project->name . ' projesi — ' : ' ');

        return $head . implode("\n\n", $sections)
            . ($project ? "\n\n(Sadece bu projeye etiketli hareketler.)" : '');
    }

    private function totalsSection(string $title, \Illuminate\Support\Collection $rows): string
    {
        if ($rows->isEmpty()) {
            return $title . ': 0 ₺';
        }

        $lines = [$title . ': ' . Money::format($rows->sum('balance')) . ' ₺ (' . $rows->count() . ' cari)'];
        foreach ($rows->take(5) as $row) {
            $lines[] = '• ' . $row['party']->name . ': ' . Money::format($row['balance']);
        }
        if ($rows->count() > 5) {
            $lines[] = '… ve ' . ($rows->count() - 5) . ' cari daha';
        }

        return implode("\n", $lines);
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
            ExpenseExtractor::KIND_TOTALS_QUERY =>"• Toplam: \"Toplam alacağım ne kadar?\"",
            ExpenseExtractor::KIND_STATEMENT => "• Ekstre (PDF): \"Ahmet Bey'in ekstresini at\"",
        ];

        $lines = ['Şunları yazabilirsin:'];
        foreach (ExpenseExtractor::allowedKinds() as $kind) {
            $lines[] = $examples[$kind];
        }

        return implode("\n", $lines);
    }

    /**
     * Gider ödendi mi? Mesaj açıkça söylediyse o; söylemediyse: carili → ödenmedi (veresiye, borç
     * cari ekstresinde, "ödedim" ile kapanır), carisiz → ödendi (kime borçlu olunduğu belli değil,
     * WhatsApp'tan kapatılamaz). Kullanıcı kararı.
     */
    private function isPaid(array $d): bool
    {
        if (($d['paid'] ?? null) !== null) {
            return (bool) $d['paid'];
        }

        return ! ($this->existingParty($d) || ! empty($d['party_name']));
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
     * Carinin aktif ALIM sözleşmeleri (satış sözleşmeleri global scope ile hariç), sabit sıra.
     *
     * @return \Illuminate\Support\Collection<int, Contract>
     */
    private function activeContracts(?Party $party): \Illuminate\Support\Collection
    {
        if (! $party) {
            return collect();
        }

        return Contract::where('party_id', $party->id)->where('status', 'active')->orderBy('id')->get()->values();
    }

    /** Ödemenin yazılacağı sözleşme: seçildiyse o, tek aktif sözleşme varsa o, yoksa null. */
    private function paymentContract(array $d): ?Contract
    {
        if ($this->kind($d) !== ExpenseExtractor::KIND_PAYMENT) {
            return null;
        }
        $contracts = $this->activeContracts($this->existingParty($d));
        if (! empty($d['contract_id'])) {
            return $contracts->firstWhere('id', $d['contract_id']);
        }

        return $contracts->count() === 1 ? $contracts->first() : null;
    }

    private function needsContractChoice(array $d): bool
    {
        return $this->kind($d) === ExpenseExtractor::KIND_PAYMENT
            && empty($d['contract_id'])
            && $this->activeContracts($this->existingParty($d))->count() > 1;
    }

    /**
     * Ödeme ama cariye açık borcumuz yok (yeni cari dahil) → bu para yeni bir masraf mı,
     * avans mı? Sormadan yazarsak ya maliyet kaçar ya da çift sayılır.
     */
    private function needsPaymentChoice(array $d): bool
    {
        if ($this->kind($d) !== ExpenseExtractor::KIND_PAYMENT || ! empty($d['advance']) || $this->missingParty($d)
            || $this->activeContracts($this->existingParty($d))->isNotEmpty()) {
            return false; // sözleşmeli cari: ödeme sözleşmeye gider
        }
        $party = $this->existingParty($d);

        return ! $party || PartyStatement::build($party)['balance'] > -0.01;
    }

    /**
     * WhatsApp'ta "yazıyor…" + mavi tik (Twilio Typing Indicator, Public Beta). 25 sn ya da cevap
     * gidene kadar görünür. Fotoğraf okuma 10-15 sn sürebiliyor; müteahhit "gitmedi mi" diye
     * tekrar atmasın. Başarısızsa sessizce geçilir — asıl akışı asla durdurmaz.
     */
    private function sendTypingIndicator(string $messageSid): void
    {
        $sid = (string) config('services.twilio.sid');
        $token = (string) config('services.twilio.token');
        if ($messageSid === '' || $sid === '' || $token === '') {
            return;
        }

        try {
            $response = Http::withBasicAuth($sid, $token)
                ->asJson()
                ->timeout(2)
                ->post('https://messaging.twilio.com/v3/Indicators/Typing.json', [
                    'messageId' => $messageSid,
                    'channel' => 'WHATSAPP', // büyük harf şart: 'whatsapp' → 400 (doküman metni yanıltıcı)
                ]);

            if ($response->failed()) {
                Log::warning('WhatsApp yazıyor göstergesi gönderilemedi', ['status' => $response->status(), 'body' => $response->body()]);
            }
        } catch (\Throwable $e) {
            Log::warning('WhatsApp yazıyor göstergesi hatası', ['msg' => $e->getMessage()]);
        }
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
        return in_array($this->word($body), ['evet', 'onayla', 'tamam', 'ok', 'e', 'onay'], true);
    }

    private function isCancel(string $body): bool
    {
        return in_array($this->word($body), ['iptal', 'hayır', 'hayir', 'vazgeç', 'vazgec', 'h'], true);
    }

    /**
     * Tek kelimelik cevabı karşılaştırmaya hazırla. Türkçe büyük harf: mb_strtolower('İ') "i̇"
     * (i + birleşik nokta) üretir → "İptal" eşleşmiyordu. Önce İ→i, I→ı; uçtaki noktalama/boşluk atılır.
     */
    private function word(string $body): string
    {
        $lower = mb_strtolower(strtr($body, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');

        return preg_replace('/^[\s\p{P}]+|[\s\p{P}]+$/u', '', $lower) ?? $lower;
    }

    /**
     * @param  string|array{0:string,1:string}  $message  metin ya da [metin, medya URL] (PDF eki)
     */
    private function isAllowedPhone(Request $request, string $from): bool
    {
        // Hub'dan imzalı geldiyse telefon hub'da zaten kontrol edildi (telefon → firma tablosu).
        if ($request->attributes->get('via_hub')) {
            return true;
        }

        $mine = Phone::normalize($from);
        if ($mine === '') {
            return false;
        }

        foreach (config('services.twilio.allowed_phones', []) as $allowed) {
            if (Phone::normalize($allowed) === $mine) {
                return true;
            }
        }

        return false;
    }

    private function twiml(string|array $message)
    {
        [$text, $media] = is_array($message) ? $message : [$message, null];
        $esc = fn (string $v) => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $inner = $media === null
            ? $esc($text)
            : '<Body>' . $esc($text) . '</Body><Media>' . $esc($media) . '</Media>';

        return response(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response><Message>{$inner}</Message></Response>",
            200,
            ['Content-Type' => 'text/xml']
        );
    }
}
