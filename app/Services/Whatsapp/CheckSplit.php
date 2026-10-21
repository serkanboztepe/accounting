<?php

namespace App\Services\Whatsapp;

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
        return ['check_plan' => ['type' => ['object', 'null'], 'description' => 'Cheques described as a rule ("11 çek aya böl", "100\'er, kalanı sona"): '
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
    public static function prepare(array $d, ?array $previous = null): array
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
        }

        return $d;
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
        return in_array($d['check_choice'] ?? null, ['split', 'sum'], true);
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
