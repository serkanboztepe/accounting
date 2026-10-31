<?php

namespace App\Services\Whatsapp;

use App\Models\Agreement;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Support\AgreementBalances;
use App\Support\Money;

/**
 * "Hangisi için?" — anlaşması olan cariye ödeme / ondan tahsilat (kullanıcı 26.10: HER SEFERİNDE sorulur, otomatik bağlama yok):
 *
 *   Demirci Ali'ye 25.000 ₺ ödedin. Hangisi için?
 *   *1* Demir (A Blok): 80.000 borcun vardı, 55.000'e düşecek
 *   *2* Çimento (B Blok): 25.000 borcun vardı, borcun kalmayacak ✅
 *   *3* Diğer alışlar: borcun yok, 25.000 alacaklı olacaksın
 *
 * Anlaşma seçilince ödeme ona bağlanır (agreement_id) ve "ne için? (mal geldi / peşin / eski borç)" sorusu sorulmaz — cevabı
 * belli. "Diğer" seçilince bugünkü akış aynen sürer. Numara ya da yazıyla ("demir için", "diğer") seçilir.
 * Seçenekler ve cümleler panelle aynı yerden (AgreementBalances).
 */
final class PaymentAgreementChoice
{
    private const TYPES = [
        ExpenseExtractor::KIND_PAYMENT    => PartyLedgerEntry::TYPE_PAYMENT,
        ExpenseExtractor::KIND_COLLECTION => PartyLedgerEntry::TYPE_COLLECTION,
    ];

    public static function shown(array $d): bool
    {
        return ! empty($d['agreement_choice']) && ! empty($d['agreement_choices']);
    }

    public static function applies(array $d): bool
    {
        $type = self::TYPES[$d['kind'] ?? ''] ?? null;
        if ($type === null || ! empty($d['agreement_choice_done']) || ! empty($d['agreement_id']) || (float) ($d['amount'] ?? 0) <= 0
            || OnBehalfChoice::isOnBehalf($d) || ! empty($d['settles_expense_id']) || ! \App\Support\Features::agreements()) {
            return false;
        }
        $party = DraftGaps::party($d);

        return $party !== null && self::choices($party, $type) !== [];
    }

    /** Soru sorulurken seçenek sırası taslağa yazılır — cevaptaki numara aynı anlaşmayı bulsun. */
    public static function prepare(array $d): array
    {
        $party = DraftGaps::party($d);
        $type = self::TYPES[$d['kind'] ?? ''] ?? null;
        $d['agreement_choices'] = $party && $type ? array_map(fn ($c) => $c['id'], self::choices($party, $type)) : [];
        $d['agreement_choice'] = $d['agreement_choices'] !== [];

        return $d;
    }

    public static function question(array $d): string
    {
        $party = DraftGaps::party($d);
        $type = self::TYPES[$d['kind'] ?? ''] ?? PartyLedgerEntry::TYPE_PAYMENT;
        $amount = (float) ($d['amount'] ?? 0);
        $direction = AgreementBalances::directionFor($type);
        $name = $party?->name ?? ($d['party_name'] ?? 'Bu kişi');
        $head = $type === PartyLedgerEntry::TYPE_COLLECTION
            ? self::ablative($name) . ' ' . Money::format($amount) . ' ₺ aldın. Hangisi için?'
            : WorkChoice::dative($name) . ' ' . Money::format($amount) . ' ₺ ödedin. Hangisi için?';
        $byId = collect($party ? self::choices($party, $type) : [])->keyBy(fn ($c) => (string) $c['id']);
        $lines = [];
        foreach ($d['agreement_choices'] ?? [] as $i => $id) {
            $c = $byId[(string) $id] ?? null;
            if ($c) {
                $lines[] = '*' . ($i + 1) . '* ' . $c['label'] . ': ' . AgreementBalances::sentence($c['balance'], $amount, $direction);
            }
        }

        return $head . "\n" . implode("\n", $lines);
    }

    /** Numara (1'den) → seçim. Listede yoksa null. */
    public static function choose(array $d, int $n): ?array
    {
        $ids = $d['agreement_choices'] ?? [];
        if ($n < 1 || $n > count($ids)) {
            return null;
        }
        $id = $ids[$n - 1];
        $base = ['agreement_choice' => null, 'agreement_choice_done' => true, 'question' => null, 'is_new_entry' => false];
        if ($id === null) {
            return array_merge($d, $base, ['agreement_id' => null]);
        }

        // Anlaşmanın parası: "ne için?" sorusu ve kayıtsız eski borç yazımı yok.
        return array_merge($d, $base, ['agreement_id' => (int) $id, 'work_choice' => null, 'work_chosen' => true,
            'work_status_known' => null, 'work_loan_option' => null, 'debt_repayment' => false]);
    }

    /** Yazıyla seçim: "diğer" / "anlaşma dışı" ya da anlaşma adı ("demir için"). Tek eşleşme yoksa null. */
    public static function match(array $d, string $body): ?int
    {
        $text = self::fold($body);
        $ids = $d['agreement_choices'] ?? [];
        if ($text === '' || $ids === []) {
            return null;
        }
        if (preg_match('/\b(diger|anlasma disi|disi)\b/u', $text)) {
            $i = array_search(null, $ids, true);

            return $i === false ? null : $i + 1;
        }
        // Tek anlaşma varken "anlaşmasından düş" / "anlaşmaya say" o anlaşmadır (30.10: yazıyla cevap anlaşılmayıp soru tekrarlandı).
        if (preg_match('/\banlasma/u', $text) && count(array_filter($ids)) === 1) {
            return array_key_first(array_filter($ids)) + 1;
        }
        $titles = Agreement::whereIn('id', array_filter($ids))->pluck('title', 'id');
        $hits = [];
        foreach ($ids as $i => $id) {
            $title = $id ? self::fold((string) ($titles[$id] ?? '')) : '';
            $first = explode(' ', $title)[0] ?? '';
            if ($title !== '' && (str_contains($text, $title) || (mb_strlen($first) >= 3 && preg_match('/\b' . preg_quote($first, '/') . '/u', $text)))) {
                $hits[] = $i + 1;
            }
        }

        return count($hits) === 1 ? $hits[0] : null;
    }

    /** Teyit kartında tek satır: "🤝 Demir (A Blok): 80.000 borcun vardı, 55.000'e düşecek". */
    public static function cardLine(array $d): ?string
    {
        $type = self::TYPES[$d['kind'] ?? ''] ?? null;
        $party = DraftGaps::party($d);
        if ($type === null || empty($d['agreement_id']) || ! $party) {
            return null;
        }
        foreach (self::choices($party, $type) as $c) {
            if ($c['id'] === (int) $d['agreement_id']) {
                return '🤝 ' . $c['label'] . ': ' . AgreementBalances::sentence($c['balance'], (float) ($d['amount'] ?? 0), AgreementBalances::directionFor($type));
            }
        }

        return null;
    }

    /** Kayıtta: taslaktaki anlaşma bu carinin mi (değilse bağlanmaz). */
    public static function agreementIdFor(array $d, Party $party): ?int
    {
        if (empty($d['agreement_id']) || ! isset(self::TYPES[$d['kind'] ?? ''])) {
            return null;
        }

        return Agreement::where('party_id', $party->id)->whereKey((int) $d['agreement_id'])->value('id');
    }

    /** @return list<array{id: int|null, label: string, balance: float}> */
    private static function choices(Party $party, string $type): array
    {
        return AgreementBalances::choices($party, $type,
            (float) TurnTrace::statement('PaymentAgreementChoice::choices', $party)['balance']);
    }

    /** Ayrılma eki: Ahmet → Ahmet'ten, Ali → Ali'den, Beton → Beton'dan. */
    private static function ablative(string $name): string
    {
        return WorkChoice::locative($name) . 'n';
    }

    private static function fold(string $s): string
    {
        $s = mb_strtolower(strtr(trim($s), ['İ' => 'i', 'I' => 'ı']), 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', strtr($s, ['ı' => 'i', 'ğ' => 'g', 'ü' => 'u', 'ş' => 's', 'ö' => 'o', 'ç' => 'c'])));
    }
}
