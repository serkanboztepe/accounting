<?php

namespace App\Services\Whatsapp;

use App\Models\Contract;
use App\Models\Party;
use App\Support\Money;
use App\Support\PartyStatement;
use Illuminate\Support\Collection;

/**
 * Bir WhatsApp taslağı neden henüz kaydedilemiyor? (tutar / kişi / seçim / yön / yalnız onay)
 * Onay anındaki "❓ önce …" cevabı ve yarım kalan kayıt hatırlatması aynı sebebi söyler —
 * kayıt sessizce kaybolmasın (Erkan, Doktor vakası 13 Ekim).
 */
final class DraftGaps
{
    public const AMOUNT = 'amount';

    public const PARTY = 'party';

    public const CHOICE = 'choice';

    public const DIRECTION = 'direction';

    public const DETAIL = 'detail';

    public const CONFIRM = 'confirm';

    private const LEDGER_KINDS = [ExpenseExtractor::KIND_PAYMENT, ExpenseExtractor::KIND_SALE,
        ExpenseExtractor::KIND_COLLECTION, ExpenseExtractor::KIND_DEBT_NOTE, ExpenseExtractor::KIND_PURCHASE];

    /**
     * Kaydı tutan ilk eksik. `ask`: kullanıcıdan istenen (onay anında), `why`: neden kaydedilemediği (takip mesajında).
     *
     * @return array{code:string,ask:string,why:string}
     */
    public static function reason(array $d): array
    {
        $kind = self::kind($d);

        if (MemoryDraft::handles($d)) {
            return self::confirm();
        }
        if (RecurringDraft::handles($d)) {
            return ($question = RecurringDraft::blocker($d))
                ? ['code' => self::DETAIL, 'ask' => $question, 'why' => 'Bir bilgi eksik olduğu için kaydedemedim: ' . $question]
                : self::confirm();
        }
        if (EmployeeDraft::handles($d)) {
            return ($question = EmployeeDraft::blocker($d))
                ? ['code' => self::DETAIL, 'ask' => $question, 'why' => 'Bir bilgi eksik olduğu için kaydedemedim: ' . $question]
                : self::confirm();
        }
        if (TradeDraft::handles($d) && ($question = TradeDraft::blocker($d) ?? ProjectChoice::blocker($d))) {
            return ['code' => self::DETAIL, 'ask' => $question, 'why' => 'Bir bilgi eksik olduğu için kaydedemedim: ' . $question];
        }
        if ($kind === ExpenseExtractor::KIND_REMINDER) {
            return empty($d['event_date'])
                ? ['code' => self::DETAIL, 'ask' => 'Ne zaman hatırlatayım?', 'why' => 'Ne zaman hatırlatacağımı yazmadığın için kuramadım.']
                : self::confirm();
        }
        if (! TradeDraft::handles($d) && (float) ($d['amount'] ?? 0) <= 0) {
            return ['code' => self::AMOUNT, 'ask' => 'Toplam ne kadar? Sadece rakamı yazman yeterli (ör. 20000).',
                'why' => 'Tutar eksik olduğu için kaydedemedim.'];
        }
        // Önce anla (16.10): kaydı değiştirecek tahmin varken onaya hazır değil — AI'ın sorusu.
        if (($ask = Understanding::gap($d)) !== null) {
            return ['code' => self::DETAIL, 'ask' => $ask, 'why' => 'Kaydetmeden önce sormak istedim: ' . $ask, 'party' => Understanding::partyUnclear($d)];
        }
        if (self::missingParty($d)) {
            return ['code' => self::PARTY, 'ask' => 'Kime/kimden olduğunu yazar mısın? (cari adı)',
                'why' => 'Kime/kimden olduğunu yazmadığın için kaydedemedim.'];
        }
        if (self::needsContractChoice($d)) {
            return ['code' => self::CHOICE, 'ask' => 'Hangi sözleşmeye ödendiğini seç (numara yaz).',
                'why' => 'Hangi sözleşmeye ödediğini seçmediğin için kaydedemedim.'];
        }
        if (self::needsSaleOrPurchase($d)) {
            return ['code' => self::DIRECTION, 'ask' => 'Yönü yaz: *sattım* (ona götürdün, o sana borçlanır) ya da *aldım* (ondan aldın, sen ona borçlanırsın).',
                'why' => 'Malı ona mı götürdüğünü, ondan mı aldığını yazmadığın için kaydedemedim.'];
        }
        if (self::needsDebtSide($d)) {
            return ['code' => self::DIRECTION, 'ask' => 'Yönü yaz: *o bana* (o sana borçlu) ya da *ben ona* (sen ona borçlusun).',
                'why' => 'Kimin kime borçlu olduğunu yazmadığın için kaydedemedim.'];
        }
        if (self::needsPaidAnswer($d)) {
            return ['code' => self::CHOICE, 'ask' => 'Yaz: *ödedim* ya da *veresiye*.',
                'why' => 'Ödeyip ödemediğini yazmadığın için kaydedemedim.'];
        }
        if (self::needsPaymentChoice($d)) {
            return ['code' => self::CHOICE, 'ask' => 'Seç: *1* iş/mal aldım, *2* önceden verdim (iş/mal sonra).',
                'why' => 'Ne için verdiğini seçmediğin için kaydedemedim.'];
        }

        return self::confirm();
    }

    /** Taslağın tek satırlık adı: "Satış — Doktor, 10 kg peynir" / "Gider 4.500 ₺, Yem". */
    public static function label(array $d): string
    {
        $kind = self::kind($d);
        $word = match ($kind) {
            ExpenseExtractor::KIND_EXPENSE => 'Gider',
            ExpenseExtractor::KIND_SALE, ExpenseExtractor::KIND_TRADE_SALE => 'Satış',
            ExpenseExtractor::KIND_PURCHASE, ExpenseExtractor::KIND_TRADE_PURCHASE => 'Alış',
            ExpenseExtractor::KIND_PAYMENT => 'Ödeme',
            ExpenseExtractor::KIND_COLLECTION => ($d['payment_purpose'] ?? null) === 'advance' ? 'Peşin para (avans)' : 'Tahsilat',
            ExpenseExtractor::KIND_DEBT_NOTE => 'Borç/alacak kaydı',
            ExpenseExtractor::KIND_REMINDER => 'Hatırlatma',
            ExpenseExtractor::KIND_SALARY => 'Maaş',
            ExpenseExtractor::KIND_REMEMBER => 'Hatırlanacak bilgi',
            ExpenseExtractor::KIND_FORGET => 'Unutulacak bilgi',
            ExpenseExtractor::KIND_EMPLOYEE_SETTING => 'Personel bilgisi',
            ExpenseExtractor::KIND_RECURRING_EXPENSE => 'Düzenli gider',
            ExpenseExtractor::KIND_RECURRING_STOP => 'Düzenli gideri durdurma',
            BundleDraft::KIND => 'Toplu düzeltme',
            ExpenseExtractor::KIND_STOCK_UPDATE, ExpenseExtractor::KIND_STOCK_COUNT, ExpenseExtractor::KIND_STOCK_SETTING => 'Stok',
            default => 'Kayıt',
        };
        $party = self::party($d)?->name ?? ($d['party_name'] ?? null);
        $amount = (float) ($d['amount'] ?? 0);
        $head = $word . ($party ? ' — ' . $party : '') . ($amount > 0 ? ' ' . Money::format($amount) . ' ₺' : '');
        $desc = trim((string) ($d['description'] ?? ($d['text'] ?? '')));

        return $desc !== '' ? $head . ', ' . $desc : $head;
    }

    public static function kind(array $d): string
    {
        return $d['kind'] ?? ExpenseExtractor::KIND_EXPENSE;
    }

    public static function party(array $d): ?Party
    {
        return ! empty($d['party_id']) ? Party::find($d['party_id']) : null;
    }

    /**
     * AI kimliği vermeyip yalnız adı yazdıysa adı BİREBİR tutan TEK cari (büyük/küçük harf, İ/ı farkı yok). Kayıt anında
     * resolveParty zaten aynı ada yazıyor; burada önceden bağlanınca açık avans / bakiye taslakta görünür
     * (firma 7, 20.10: az önce açılan "Asansörcü"ye 1 milyon avans → "500.000 iş yaptı" avansı göremedi, peşin alış sandı).
     */
    public static function exactParty(?string $name): ?Party
    {
        $key = fn (string $s) => mb_strtolower(strtr(trim($s), ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
        $want = $key((string) $name);
        if ($want === '') {
            return null;
        }
        $same = Party::get(['id', 'name'])->filter(fn (Party $p) => $key($p->name) === $want);

        return $same->count() === 1 ? Party::find($same->first()->id) : null;
    }

    public static function missingParty(array $d): bool
    {
        return in_array(self::kind($d), self::LEDGER_KINDS, true)
            && ! self::party($d)
            && empty($d['party_name']);
    }

    /** Borç ödemesi ("borcum ödendi"): kayıtlı borcun yetmeyen kısmı — eski borç olarak ödemeyle birlikte yazılır, bakiye 0'ın altına inmez. */
    public static function unrecordedDebt(array $d): float
    {
        if (self::kind($d) !== ExpenseExtractor::KIND_PAYMENT || empty($d['debt_repayment'])) {
            return 0.0;
        }
        $owed = ($party = self::party($d)) ? max(0.0, -PartyStatement::build($party)['balance']) : 0.0;

        return round(max(0.0, (float) ($d['amount'] ?? 0) - $owed), 2);
    }

    /**
     * Fişten alış, ödendi mi belli değil → sorulur ("Ödedin mi?"). Önceki alışlardan alışkanlık çıkmadıysa (ilk fiş).
     */
    public static function needsPaidAnswer(array $d): bool
    {
        return self::kind($d) === ExpenseExtractor::KIND_PURCHASE && ! empty($d['from_document'])
            && ($d['paid'] ?? null) === null && ! self::missingParty($d);
    }

    /**
     * Bu cariden son alış nasıl yapılmıştı: aynı gün aynı tutarda ödeme varsa peşin (true), yoksa veresiye (false);
     * hiç alış yoksa null (sorulur). "Borç kaydı" (devreden borç) alış sayılmaz.
     */
    public static function purchaseHabit(?Party $party): ?bool
    {
        if (! $party) {
            return null;
        }
        $last = \App\Models\PartyLedgerEntry::where('party_id', $party->id)->where('type', \App\Models\PartyLedgerEntry::TYPE_PURCHASE)
            ->where(fn ($q) => $q->whereNull('description')->orWhere('description', 'not like', 'Borç kaydı%'))
            ->orderByDesc('entry_date')->orderByDesc('id')->first();
        if (! $last) {
            return null;
        }

        return \App\Models\PartyLedgerEntry::where('party_id', $party->id)->where('type', \App\Models\PartyLedgerEntry::TYPE_PAYMENT)
            ->whereDate('entry_date', $last->entry_date)->whereBetween('amount', [(float) $last->amount - 0.01, (float) $last->amount + 0.01])
            ->exists();
    }

    /** Mal ona mı gitti, ondan mı geldi belli değil ("Vedat 445 sebze ekmek getirdim") — kullanıcıya sorulur. */
    public static function needsSaleOrPurchase(array $d): bool
    {
        return in_array(self::kind($d), [ExpenseExtractor::KIND_SALE, ExpenseExtractor::KIND_PURCHASE], true)
            && ! empty($d['direction_unclear'])
            && ! self::missingParty($d);
    }

    /** Alacak/borç kaydında yön belirsiz (AI tahmin etmedi) — kullanıcıya sorulur. */
    public static function needsDebtSide(array $d): bool
    {
        return self::kind($d) === ExpenseExtractor::KIND_DEBT_NOTE
            && ($d['debt_side'] ?? null) === 'unclear'
            && ! self::missingParty($d);
    }

    /**
     * Carinin aktif ALIM sözleşmeleri (satış sözleşmeleri global scope ile hariç), sabit sıra.
     *
     * @return Collection<int, Contract>
     */
    public static function activeContracts(?Party $party): Collection
    {
        if (! $party) {
            return collect();
        }

        return Contract::where('party_id', $party->id)->where('status', 'active')->orderBy('id')->get()->values();
    }

    public static function needsContractChoice(array $d): bool
    {
        return self::kind($d) === ExpenseExtractor::KIND_PAYMENT
            && empty($d['contract_id'])
            && self::activeContracts(self::party($d))->count() > 1;
    }

    /**
     * Ödeme ama cariye açık borcumuz yok (yeni cari dahil) → bu para yeni bir masraf mı,
     * avans mı? Sormadan yazarsak ya maliyet kaçar ya da çift sayılır.
     */
    public static function needsPaymentChoice(array $d): bool
    {
        if (self::kind($d) !== ExpenseExtractor::KIND_PAYMENT || ! empty($d['advance']) || ! empty($d['debt_repayment']) || self::missingParty($d)
            || self::activeContracts(self::party($d))->isNotEmpty()) {
            return false; // sözleşmeli cari: ödeme sözleşmeye gider
        }
        $party = self::party($d);

        return ! $party || PartyStatement::build($party)['balance'] > -0.01;
    }

    /** @return array{code:string,ask:string,why:string} */
    private static function confirm(): array
    {
        return ['code' => self::CONFIRM, 'ask' => 'Kaydetmem için *evet* yaz.', 'why' => 'Onay vermediğin için kaydedemedim.'];
    }
}
