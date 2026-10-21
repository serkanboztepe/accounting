<?php

namespace App\Services\Whatsapp;

use App\Models\Check;
use App\Support\Money;
use Carbon\Carbon;

/**
 * Çek bölme hesabı kodda (firma 7, 20.10: "1.187.000 — 11 çek aya böl" → AI 107.909,09'luk çekler yazdı; "100'er, kalanı sona"
 * deyince 10 × 100.000 + 87.000 = 1.087.000 hesapladı, toplam 100.000 eksik kaldı; düzeltmeyi anlatmak üç tur sürdü).
 *
 *  - AI yalnız planı verir (`check_plan`: kaç çek, ilk vade ayı, ay sonu mu, "100'er" tutarı); tutarları ve vadeleri kod çıkarır.
 *  - Tutar söylenmemiş ve eşit bölüm küsuratlıysa numaralı seçenek: "*1* 10 × 100.000 + son çek 187.000 · *2* … · *3* Eşit".
 *  - Hangi yoldan gelirse gelsin çeklerin toplamı ödemeyle tutmazsa kaydedilmez: "*1* Son çek X olsun · *2* Ödeme Y olsun".
 */
final class CheckSplit
{
    /** Şema alanı (Understanding::schema). */
    public static function schema(): array
    {
        return ['check_plan' => ['type' => ['object', 'null'], 'description' => 'NEVER for a cheque photo/document (those go to `checks`, one row per cheque). Cheques described in TEXT as a rule ("11 çek aya böl", "100\'er, kalanı sona"): '
            . 'do NOT compute amounts or list cheques — fill this, code computes. null when each cheque is written one by one.',
            'properties' => [
                'count' => ['type' => ['integer', 'null'], 'description' => 'Number of cheques'],
                'first_month' => ['type' => ['string', 'null'], 'description' => 'Month of the first due date, YYYY-MM ("seneye şubattan" → next year-02)'],
                'due_day' => ['type' => ['integer', 'null'], 'description' => 'Day of month for every due date ("ayın ortası" → 15, "ayın 10\'u" → 10); null = month end ("ay sonu") or not said'],
                'interval_months' => ['type' => ['integer', 'null'], 'description' => 'Months between due dates ("2 ay arayla" → 2, "3 ayda bir" → 3); null = every month'],
                'months' => ['type' => ['array', 'null'], 'items' => ['type' => 'string'], 'description' => 'Irregular due months, one per cheque, YYYY-MM ("biri şubat diğeri mayıs" → [next Feb, next May]); null if regular'],
                'each_amount' => ['type' => ['number', 'null'], 'description' => 'Amount of each cheque if said ("100\'er" → 100000); the rest goes to the last cheque. null if not said'],
            ]]];
    }

    public static function normalize(mixed $plan): ?array
    {
        if (! is_array($plan)) {
            return null;
        }
        // Düzensiz aylar ("biri şubat diğeri mayıs") → ay listesi; adet listeden.
        $months = array_values(array_map(fn ($m) => self::future($m), array_filter((array) ($plan['months'] ?? []),
            fn ($m) => is_string($m) && preg_match('/^\d{4}-\d{2}$/', $m))));
        $count = $months !== [] ? count($months) : (int) ($plan['count'] ?? 0);
        if ($count < 1 || $count > 60) {
            return null;
        }

        return [
            'count' => $count,
            'months' => $months ?: null,
            'first_month' => is_string($plan['first_month'] ?? null) && preg_match('/^\d{4}-\d{2}$/', $plan['first_month'])
                ? self::future($plan['first_month']) : ($months[0] ?? null),
            'due_day' => (int) ($plan['due_day'] ?? 0) >= 1 && (int) $plan['due_day'] <= 31 ? (int) $plan['due_day'] : null,
            'interval_months' => (int) ($plan['interval_months'] ?? 0) >= 2 && (int) $plan['interval_months'] <= 12 ? (int) $plan['interval_months'] : null,
            'each_amount' => (float) ($plan['each_amount'] ?? 0) > 0 ? round((float) $plan['each_amount'], 2) : null,
        ];
    }

    /**
     * Plan → çek listesi. Tutar söylenmemiş ve eşit bölüm yuvarlak değilse seçenek sorusu hazırlanır (`check_choice` = split).
     * Plan yoksa yazılan çeklerin toplamı denetlenir (`check_choice` = sum).
     */
    public static function prepare(array $d, ?array $previous = null, string $body = ''): array
    {
        $d['check_choice'] = null;
        $amount = (float) ($d['amount'] ?? 0);
        $plan = self::normalize($d['check_plan'] ?? null);
        $d['check_plan'] = $plan;
        if ($plan !== null && $amount > 0) {
            // Aynı plan önceki turda seçenekle çözüldüyse (AI planı "tutarsız" tekrar gönderdi) seçilen çekler kalır.
            $prevPlan = $previous['check_plan'] ?? null;
            if ($plan['each_amount'] === null && is_array($prevPlan) && ! empty($previous['checks'])
                && array_diff_key($prevPlan + ['months' => null], ['each_amount' => 1]) == array_diff_key($plan, ['each_amount' => 1])
                && abs((float) ($previous['amount'] ?? 0) - $amount) < 0.01) {
                $plan['each_amount'] = $prevPlan['each_amount'];
                $d['check_plan'] = $plan;
            }
            if ($plan['each_amount'] === null && count(self::options($amount, $plan['count'])) > 1) {
                $d['checks'] = null;
                $d['check_choice'] = 'split';

                return $d;
            }
            $d['checks'] = self::build($amount, $plan);
        }
        if (self::sumGap($d) !== null) {
            $d['check_choice'] = 'sum';

            return $d;
        }
        // Fotoğraf çek ama hiçbir çek okunmadıysa çeksiz ödeme yazılmaz (firma 7, 21.10 09:15: 4 çeklik sayfa → ödeme kaydedildi, çek yok).
        // Soru bir kez sorulduysa açıklamada "çek" kalmasa da çek gelene kadar sürer (21.10: düzeltme turunda açıklama değişti).
        if (! empty($d['from_document']) && in_array($d['kind'] ?? null, [ExpenseExtractor::KIND_PAYMENT, ExpenseExtractor::KIND_PURCHASE], true)
            && empty($d['checks'])
            && (($d['payment_type'] ?? null) === 'check' || preg_match('/\bçek/iu', (string) ($d['description'] ?? ''))
                || ($previous['check_choice'] ?? null) === 'unread')) {
            $d['check_choice'] = 'unread';

            return $d;
        }
        // Kayıtlı numara ikinci kez yazılmaz (09:18: aynı sayfa tekrar → AI önceki sayfanın çek numaralarını yazdı).
        if (($dup = self::duplicateNumber($d)) !== null) {
            $d['check_choice'] = 'dup';
            $d['check_duplicate'] = $dup;

            return $d;
        }
        // Vade yazıyla geldiyse ("şubat ortası", "15 mart") tarihe çevrilir; okunamayan vadeyle kayıt yapılmaz (çek vadesiz olamaz —
        // Sonnet 21.10 "şubat ortası" yazdı, kayıt veritabanında düştü).
        $checks = array_values(array_filter((array) ($d['checks'] ?? []), 'is_array'));
        foreach ($checks as $i => $c) {
            if (($text = self::dueText((string) ($c['due_date_text'] ?? ''))) !== null) {
                $checks[$i]['due_date_text'] = $text;
            }
        }
        if ($checks !== []) {
            $d['checks'] = $checks;
            if (array_filter($checks, fn ($c) => IssuedChecks::dueDate((string) ($c['due_date_text'] ?? ''))[0] === null) !== []) {
                $d['check_choice'] = 'due';

                return $d;
            }
        }
        // Vadesi geçmiş çek: yeni başlayan eski çekini girebilir → engel yok, ama yıl yazılmadıysa sorulur (AI yanlış yıl seçebilir).
        $dues = array_column(array_values(array_filter((array) ($d['checks'] ?? []), 'is_array')), 'due_date_text');
        if (! empty($previous['past_ok']) && array_column((array) ($previous['checks'] ?? []), 'due_date_text') === $dues) {
            $d['past_ok'] = true;
        }
        if (empty($d['past_ok']) && self::pastDues($d, $body) !== []) {
            $d['check_choice'] = 'past';
        }

        return $d;
    }

    /**
     * Bugünden önceki vadeler — yılı kullanıcı yazdıysa ("15.02.2026", "15/02/26") sayılmaz: bilerek eski çek giriyor.
     *
     * @return array<int, string> çek sırası => vade (Y-m-d)
     */
    public static function pastDues(array $d, string $body = ''): array
    {
        $out = [];
        foreach (array_values(array_filter((array) ($d['checks'] ?? []), 'is_array')) as $i => $c) {
            [$due] = IssuedChecks::dueDate((string) ($c['due_date_text'] ?? ''));
            if ($due === null || $due >= now()->toDateString()) {
                continue;
            }
            $year = substr($due, 0, 4);
            if (preg_match('/\b' . $year . '\b|\d{1,2}\s*[.\/-]\s*\d{1,2}\s*[.\/-]\s*' . substr($year, 2) . '\b/u', $body)) {
                continue;
            }
            $out[$i] = $due;
        }

        return $out;
    }

    /** Taslaktaki çeklerden zaten kayıtlı olan ilki: "2023471 (28.02.2027 · Seramikpark)". */
    private static function duplicateNumber(array $d): ?string
    {
        $numbers = array_filter(array_map(fn ($c) => is_array($c) ? trim((string) ($c['number'] ?? '')) : '', (array) ($d['checks'] ?? [])));
        if ($numbers === []) {
            return null;
        }
        $check = Check::with('party')->whereIn('check_number', $numbers)->first();

        return $check ? $check->check_number . ' (' . $check->due_date?->format('d.m.Y') . ($check->party ? ' · ' . $check->party->name : '') . ')' : null;
    }

    private const MONTHS = ['ocak' => 1, 'şubat' => 2, 'subat' => 2, 'mart' => 3, 'nisan' => 4, 'mayıs' => 5, 'mayis' => 5, 'haziran' => 6,
        'temmuz' => 7, 'ağustos' => 8, 'agustos' => 8, 'eylül' => 9, 'eylul' => 9, 'ekim' => 10, 'kasım' => 11, 'kasim' => 11, 'aralık' => 12, 'aralik' => 12];

    /**
     * Yazıyla vade → "15.02.2027": "15 şubat (2027)", "şubat ortası" (15), "şubat başı" (1), "şubat sonu" / yalnız "şubat" (ay sonu).
     * Yıl yoksa en yakın gelecek. Rakamla yazılmışsa (31-02-2027) ya da ay adı yoksa null — olduğu gibi kalır.
     */
    public static function dueText(string $text): ?string
    {
        $t = mb_strtolower(strtr(trim($text), ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
        if ($t === '' || preg_match('/\d{1,2}\s*[.\/-]\s*\d{1,2}\s*[.\/-]\s*\d{2,4}/', $t)
            || ! preg_match('/(' . implode('|', array_keys(self::MONTHS)) . ')/u', $t, $m)) {
            return null;
        }
        $month = self::MONTHS[$m[1]];
        $year = preg_match('/\b(20\d{2})\b/', $t, $y) ? (int) $y[1] : null;
        $base = Carbon::create($year ?? now()->year, $month, 1)->startOfDay();
        $day = match (true) {
            (bool) preg_match('/(?<![\d.])(\d{1,2})\s*(?:' . $m[1] . ')/u', $t, $dm) => min((int) $dm[1], $base->daysInMonth),
            str_contains($t, 'orta') => 15,
            (bool) preg_match('/\bba[şs]/u', $t) => 1,
            default => $base->daysInMonth,
        };
        $date = $base->setDay(max(1, $day));
        while ($year === null && $date->lt(now()->startOfDay())) {
            $date = $date->addYearNoOverflow();
        }

        return $date->format('d.m.Y');
    }

    /** Geçmiş vadeyi geleceğe taşı (gün/ay aynı, yıl ileri). */
    private static function nextYear(string $due): string
    {
        $date = Carbon::parse($due);
        while ($date->lt(now()->startOfDay())) {
            $date->addYearNoOverflow();
        }

        return $date->format('d.m.Y');
    }

    /**
     * Bölme seçenekleri: [her çek, son çek]. Eşit bölüm tam yuvarlaksa tek seçenek (sorulmaz).
     *
     * @return list<array{0: float, 1: float}>
     */
    public static function options(float $amount, int $count): array
    {
        $equal = round($amount / $count, 2);
        $equalLast = round($amount - $equal * ($count - 1), 2);
        $unit = 10 ** (int) floor(log10(max(1, $equal)));          // 107.909 → 100.000
        // Eşit bölüm yuvarlaksa (300.000, 2.500) sorulmaz; küsuratlıysa (107.909,09) seçenek.
        if ($count === 1 || (abs($equal - $equalLast) < 0.01 && fmod($equal, max(1, $unit / 10)) < 0.01)) {
            return [[$equal, $equalLast]];
        }
        $out = [];
        foreach ([$unit, $unit / 10, $unit / 100] as $u) {
            $each = floor($equal / $u) * $u;
            $last = round($amount - $each * ($count - 1), 2);
            if ($u >= 1000 && $each > 0 && $last > 0 && ! in_array([$each, $last], $out, false) && count($out) < 2) {
                $out[] = [(float) $each, $last];
            }
        }
        $out[] = [$equal, $equalLast];

        return $out;
    }

    public static function question(array $d): ?string
    {
        if (($d['check_choice'] ?? null) === 'sum' && ($gap = self::sumGap($d)) !== null) {
            [$sum, $last] = $gap;
            $diff = (float) $d['amount'] - $sum;
            $opts = [];
            if ($last > 0) {
                $opts[] = '*1* Son çek ' . Money::format($last) . ' ₺ olsun';
            }
            $opts[] = '*' . (count($opts) + 1) . '* Ödeme ' . Money::format($sum) . ' ₺ olsun';

            return 'Çeklerin toplamı ' . Money::format($sum) . ' ₺, ödeme ' . Money::format((float) $d['amount']) . ' ₺ — '
                . Money::format(abs($diff)) . ' ₺ ' . ($diff > 0 ? 'eksik' : 'fazla') . ".\n" . implode(' · ', $opts);
        }
        if (($d['check_choice'] ?? null) === 'unread') {
            return 'Fotoğraftaki çekleri okuyamadım. Fotoğrafı tekrar gönderir misin? (ya da çekleri yaz: numara, vade, tutar)';
        }
        if (($d['check_choice'] ?? null) === 'dup') {
            return ($d['check_duplicate'] ?? 'Bu') . ' numaralı çek zaten kayıtlı — aynı çek iki kez yazılmasın. '
                . 'Fotoğraf yanlış okunduysa tekrar gönder ya da çekleri yaz; gerek yoksa *iptal*.';
        }
        if (($d['check_choice'] ?? null) === 'due') {
            return 'Çekin vadesini gün.ay.yıl olarak yazar mısın? (ör. 15.02.2027)';
        }
        if (($d['check_choice'] ?? null) === 'past') {
            $past = self::pastDues($d, '');
            if ($past === []) {
                return null;
            }
            $first = Carbon::parse(reset($past))->format('d.m.Y');
            $head = count($past) === 1 ? $first . ' vadesi geçmiş görünüyor.'
                : count($past) . ' çekin vadesi geçmiş görünüyor (' . $first . ' …).';

            return $head . "\n*1* Eski çek, öyle kalsın · *2* " . self::nextYear(reset($past)) . (count($past) > 1 ? ' … ' : ' ') . 'olsun';
        }
        if (($d['check_choice'] ?? null) !== 'split' || ! is_array($plan = $d['check_plan'] ?? null)) {
            return null;
        }
        $count = $plan['count'];
        $lines = [];
        foreach (self::options((float) $d['amount'], $count) as $i => [$each, $last]) {
            $lines[] = '*' . ($i + 1) . '* ' . (abs($each - $last) < 0.01
                ? 'Eşit: ' . $count . ' × ' . Money::format($each)
                : (abs($last - $each) < 1 ? 'Eşit: ' : '') . ($count - 1) . ' × ' . Money::format($each) . ' + son çek ' . Money::format($last));
        }
        $dues = self::dues($plan);

        return $count . ' çeke nasıl böleyim?' . "\n" . implode("\n", $lines)
            . ($dues ? "\nVadeler: " . implode(', ', $count <= 4 ? $dues : [$dues[0] . ' → ' . end($dues)])
                . ($plan['due_day'] === null ? ' (ay sonu)' : '') . (($plan['interval_months'] ?? null) ? ' · ' . $plan['interval_months'] . ' ay arayla' : '') : '');
    }

    /** Numara → çekler; geçersizse null. */
    public static function choose(array $d, int $n): ?array
    {
        $amount = (float) ($d['amount'] ?? 0);
        if (($d['check_choice'] ?? null) === 'split' && is_array($plan = $d['check_plan'] ?? null)) {
            $opt = self::options($amount, $plan['count'])[$n - 1] ?? null;
            if ($opt === null) {
                return null;
            }
            $plan['each_amount'] = $opt[0];

            return array_merge($d, ['check_plan' => $plan, 'checks' => self::build($amount, $plan), 'check_choice' => null, 'question' => null]);
        }
        if (($d['check_choice'] ?? null) === 'past' && in_array($n, [1, 2], true)) {
            $checks = $d['checks'];
            if ($n === 2) {
                foreach (self::pastDues($d, '') as $i => $due) {
                    $checks[$i]['due_date_text'] = self::nextYear($due);
                }
            }

            return array_merge($d, ['checks' => $checks, 'check_choice' => null, 'question' => null, 'past_ok' => true]);
        }
        if (($d['check_choice'] ?? null) === 'sum' && ($gap = self::sumGap($d)) !== null) {
            [$sum, $last] = $gap;
            $hasLast = $last > 0;
            if ($hasLast && $n === 1) {
                $checks = $d['checks'];
                $checks[count($checks) - 1]['amount'] = $last;

                return array_merge($d, ['checks' => $checks, 'check_choice' => null, 'question' => null]);
            }
            if ($n === ($hasLast ? 2 : 1)) {
                return array_merge($d, ['amount' => $sum, 'check_choice' => null, 'question' => null]);
            }
        }

        return null;
    }

    public static function shown(array $d): bool
    {
        return in_array($d['check_choice'] ?? null, ['split', 'sum', 'past'], true);
    }

    /**
     * Tüm çeklerin tutarı yazılı ve toplam ödemeyle tutmuyorsa [toplam, son çekin olması gereken tutarı]; tutuyorsa null.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function sumGap(array $d): ?array
    {
        $checks = array_values(array_filter((array) ($d['checks'] ?? []), 'is_array'));
        $amount = (float) ($d['amount'] ?? 0);
        if ($checks === [] || $amount <= 0 || array_filter($checks, fn ($c) => (float) ($c['amount'] ?? 0) <= 0) !== []) {
            return null;
        }
        $sum = round(array_sum(array_map(fn ($c) => (float) $c['amount'], $checks)), 2);
        if (abs($sum - $amount) < 0.01) {
            return null;
        }

        return [$sum, round($amount - ($sum - (float) end($checks)['amount']), 2)];
    }

    /** @return list<array{number: null, due_date_text: ?string, amount: float}> */
    private static function build(float $amount, array $plan): array
    {
        $count = $plan['count'];
        $each = $plan['each_amount'] ?? round($amount / $count, 2);
        $dues = self::dues($plan);
        $checks = [];
        for ($i = 0; $i < $count; $i++) {
            $checks[] = ['number' => null, 'due_date_text' => $dues[$i] ?? null,
                'amount' => $i === $count - 1 ? round($amount - $each * ($count - 1), 2) : (float) $each];
        }

        return $checks;
    }

    /** Çek vadesi geçmişte olmaz: "şubattan" (yılsız) geçmiş bir aya düştüyse sonraki yıl (Sonnet 21.10: Şubat 2026 yazdı). */
    private static function future(string $month): string
    {
        $m = Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfDay();
        while ($m->lt(now()->startOfMonth())) {
            $m->addYear();
        }

        return $m->format('Y-m');
    }

    /** @return list<string> vade metinleri "28.02.2027" (ilk ay bilinmiyorsa boş) */
    private static function dues(array $plan): array
    {
        if ($plan['first_month'] === null) {
            return [];
        }
        $start = Carbon::createFromFormat('Y-m-d', $plan['first_month'] . '-01')->startOfDay();
        $out = [];
        for ($i = 0; $i < $plan['count']; $i++) {
            $month = isset($plan['months'][$i])
                ? Carbon::createFromFormat('Y-m-d', $plan['months'][$i] . '-01')->startOfDay()
                : $start->copy()->addMonthsNoOverflow($i * ($plan['interval_months'] ?? 1));
            $day = $plan['due_day'] === null ? $month->daysInMonth : min($plan['due_day'], $month->daysInMonth);
            $out[] = $month->setDay($day)->format('d.m.Y');
        }

        return $out;
    }
}
