<?php

namespace App\Support;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Personelin maaş hesabı — kart, kayıt, hatırlatma, panel, aylık sonuç TEK yerden okur (maaş planı v4, 07.11; tablo yok, her seferinde
 * ekstreden hesaplanır). Kurallar (kullanıcı onaylı):
 *  - Verilen para en eski açık maaştan düşer; borç yoksa avanstır, sonraki maaştan düşer. Kesinti avans gibi düşer (para çıkmaz).
 *  - Ödemede ay söylendiyse ("Kasım maaşını verdim" → salary_period / eski satırda "Maaş ödemesi — Kasım 2026") o aya; fazlası sıradaki
 *    açık maaşa, o da yoksa avansa.
 *  - Sınır BOUNDARY (Kasım 2026): öncesinin avans fazlası ilk avans olarak taşınır; öncesinin AÇIK maaş borcu kendiliğinden kapatılmaz
 *    (yalnız gösterilir; o ay söylenerek ödenirse kapanır).
 * Yalnız maaş satırları sayılır: "Maaş — Ay Yıl" (alış), maaş/avans ödemesi, "Kesinti — …". Yevmiye, borç verme, adına ödeme,
 * anlaşma ödemesi ve diğer hareketler bu hesaba girmez.
 */
final class SalaryLedger
{
    public const BOUNDARY = '2026-11';

    private const MONTHS = ['ocak' => 1, 'şubat' => 2, 'mart' => 3, 'nisan' => 4, 'mayıs' => 5, 'haziran' => 6, 'temmuz' => 7,
        'ağustos' => 8, 'eylül' => 9, 'ekim' => 10, 'kasım' => 11, 'aralık' => 12];

    /** Yeni kural (en eski borçtan kapatma, kendiliğinden maaş) bu personelde geçerli mi? Değilse eski akış (yevmiye, otomatiği kapalı). */
    public static function eligible(?Party $party): bool
    {
        return $party !== null && $party->is_employee && (float) $party->salary_amount > 0 && (int) $party->salary_day >= 1
            && $party->salary_auto === true;
    }

    /** Türkçe harf duyarsız: "MAAŞ — KASIM  2026" = "maaş - kasım 2026". */
    public static function normalize(?string $text): string
    {
        $t = mb_strtolower(strtr((string) $text, ['I' => 'ı', 'İ' => 'i']), 'UTF-8');
        $t = preg_replace('/\s*[—–-]\s*/u', ' - ', $t);

        return trim(preg_replace('/\s+/u', ' ', $t));
    }

    /** "2026-11" → "Kasım 2026". */
    public static function label(string $period, bool $withYear = true): string
    {
        $c = Carbon::parse($period . '-01')->locale('tr');

        return $withYear ? $c->translatedFormat('F Y') : $c->translatedFormat('F');
    }

    /** Açıklamadaki ay ("maaş - kasım 2026" / "maaş ödemesi - kasım 2026") → "2026-11". */
    private static function monthIn(string $normalized, string $prefix): ?string
    {
        $names = implode('|', array_keys(self::MONTHS));
        if (! preg_match('/^' . $prefix . ' - (' . $names . ') (\d{4})(?!\d)/u', $normalized, $m)) {
            return null;
        }

        return sprintf('%04d-%02d', (int) $m[2], self::MONTHS[$m[1]]);
    }

    /** Maaş hak edişi (alış) satırının ayı; maaş satırı değilse null. */
    public static function accrualPeriod(PartyLedgerEntry $e): ?string
    {
        if ($e->type !== PartyLedgerEntry::TYPE_PURCHASE) {
            return null;
        }
        $n = self::normalize($e->description);
        if (str_starts_with($n, 'yevmiye')) {
            return null;
        }

        return $e->salary_period ?: self::monthIn($n, 'maa[şs]');
    }

    /** Maaş/avans ödemesi mi? (borç verme, adına ödeme, anlaşma, yevmiye hariç) */
    public static function isPayrollPayment(PartyLedgerEntry $e): bool
    {
        if ($e->type !== PartyLedgerEntry::TYPE_PAYMENT || $e->agreement_id) {
            return false;
        }
        if ($e->salary_period) {
            return true;
        }
        $n = self::normalize($e->description);
        if (str_contains($n, 'yevmiye') || str_starts_with($n, 'borç verildi') || str_starts_with($n, 'adına ödendi')) {
            return false;
        }

        return (bool) preg_match('/maa[şs]|avans/u', $n);
    }

    public static function isDeduction(PartyLedgerEntry $e): bool
    {
        return $e->type === PartyLedgerEntry::TYPE_PURCHASE_RETURN && str_starts_with(self::normalize($e->description), 'kesinti');
    }

    /** Ödemenin/kesintinin söylenen ayı (yoksa null = en eski borçtan). */
    private static function target(PartyLedgerEntry $e): ?string
    {
        return $e->salary_period ?: ($e->type === PartyLedgerEntry::TYPE_PAYMENT
            ? self::monthIn(self::normalize($e->description), 'maa[şs] ödemesi') : null);
    }

    /** O ayın maaşı yazılmış mı (harf duyarsız; çift yazma koruması). */
    public static function accrualRow(Party $party, string $period): ?PartyLedgerEntry
    {
        return PartyLedgerEntry::where('party_id', $party->id)->where('type', PartyLedgerEntry::TYPE_PURCHASE)->orderBy('id')->get()
            ->first(fn (PartyLedgerEntry $e) => self::accrualPeriod($e) === $period);
    }

    /**
     * Maaş durumu. $extra = henüz yazılmamış ödeme (önizleme): ['amount' => float, 'target' => ?string, 'date' => 'Y-m-d'].
     *
     * @return array{periods: array<string, array{accrued: float, paid: float, remaining: float}>, owed: float, advance: float,
     *   reserved: array<string, float>, legacy_open: float, legacy_periods: list<string>, deductions_left: bool, other: bool}
     */
    public static function state(Party $party, ?array $extra = null): array
    {
        $rows = PartyLedgerEntry::where('party_id', $party->id)->orderBy('entry_date')->orderBy('id')->get();
        $boundaryDate = self::BOUNDARY . '-01';
        $periods = [];
        $legacyDebt = 0.0;
        $legacyPeriods = [];
        $credits = [];
        $other = false;
        $lastAccrual = null;
        foreach ($rows as $e) {
            $amount = (float) $e->amount;
            if (($p = self::accrualPeriod($e)) !== null) {
                if ($p < self::BOUNDARY) {
                    $legacyDebt += $amount;
                    $legacyPeriods[$p] = true;
                } else {
                    $periods[$p] ??= ['accrued' => 0.0, 'paid' => 0.0, 'remaining' => 0.0];
                    $periods[$p]['accrued'] += $amount;
                }
                $lastAccrual = max($lastAccrual ?? '', $e->entry_date?->toDateString() ?? '');

                continue;
            }
            if (self::isPayrollPayment($e) || self::isDeduction($e)) {
                $credits[] = ['amount' => $amount, 'target' => self::target($e), 'date' => $e->entry_date?->toDateString() ?? '',
                    'deduction' => self::isDeduction($e)];

                continue;
            }
            if (! str_contains(self::normalize($e->description), 'yevmiye')) {
                $other = true;
            }
        }
        if ($extra !== null && (float) $extra['amount'] > 0) {
            $credits[] = ['amount' => (float) $extra['amount'], 'target' => $extra['target'] ?? null, 'date' => $extra['date'], 'deduction' => false];
        }
        ksort($periods);
        foreach ($periods as $p => $v) {
            $periods[$p]['remaining'] = $v['accrued'];
        }

        $pool = 0.0;
        $legacyCredit = 0.0;
        $reserved = [];
        $deductionsAfterAccrual = false;
        foreach ($credits as $c) {
            $t = $c['target'];
            if ($c['deduction'] && $c['date'] >= ($lastAccrual ?? '')) {
                $deductionsAfterAccrual = true;
            }
            if ($t !== null && $t < self::BOUNDARY) {
                $legacyCredit += $c['amount'];
            } elseif ($t !== null && isset($periods[$t])) {
                $use = min($c['amount'], $periods[$t]['remaining']);
                $periods[$t]['remaining'] -= $use;
                $periods[$t]['paid'] += $use;
                $pool += $c['amount'] - $use;
            } elseif ($t !== null) {
                $reserved[$t] = ($reserved[$t] ?? 0.0) + $c['amount']; // henüz yazılmamış ayın peşin ödemesi — o ay yazılınca düşer
            } elseif ($c['date'] < $boundaryDate) {
                $legacyCredit += $c['amount'];
            } else {
                $pool += $c['amount'];
            }
        }
        $legacyNet = $legacyCredit - $legacyDebt;
        $legacyOpen = $legacyNet < -0.009 ? round(-$legacyNet, 2) : 0.0;
        if ($legacyNet > 0.009) {
            $pool += $legacyNet; // Kasım öncesi avans fazlası taşınır
        }
        foreach ($periods as $p => $v) {
            if ($pool < 0.009) {
                break;
            }
            $use = min($pool, $v['remaining']);
            $periods[$p]['remaining'] -= $use;
            $periods[$p]['paid'] += $use;
            $pool -= $use;
        }
        $periods = array_map(fn ($v) => array_map(fn ($x) => round($x, 2), $v), $periods);

        return [
            'periods' => $periods,
            'owed' => round(array_sum(array_column($periods, 'remaining')), 2),
            'advance' => round($pool + array_sum($reserved), 2),
            'reserved' => array_map(fn ($x) => round($x, 2), $reserved),
            'legacy_open' => $legacyOpen,
            'legacy_periods' => array_keys($legacyPeriods),
            'deductions_left' => $deductionsAfterAccrual,
            'other' => $other,
        ];
    }

    /** Sıradaki maaş günü (bugün dahil, yazılmamışsa). */
    public static function nextDue(Party $party, ?Carbon $from = null): Carbon
    {
        $from = ($from ?? now(\App\Models\Reminder::TIMEZONE))->copy()->startOfDay();
        $day = max(1, (int) $party->salary_day);
        $due = $from->copy()->day(min($day, $from->daysInMonth));
        if ($due->lt($from) || self::accrualRow($party, $due->format('Y-m'))) {
            $next = $from->copy()->startOfMonth()->addMonthNoOverflow();
            $due = $next->day(min($day, $next->daysInMonth));
        }

        return $due;
    }

    /** O ayın yazılacak maaşı (ilk ay seçimi: 0 = yazılmaz, N günlük tutar; yoksa karttaki). */
    public static function salaryFor(Party $party, string $period): float
    {
        if ($party->salary_first_month === $period && $party->salary_first_amount !== null) {
            return (float) $party->salary_first_amount;
        }

        return (float) $party->salary_amount;
    }

    /**
     * Tutar söylenmediyse ödenecek (kullanıcı 07.11: maaş − avans − kesinti = kalan): en eski açık maaşın kalanı; açık maaş yoksa
     * sıradaki maaşın tahmini neti (peşin verilir, maaş yazılınca kapanır).
     */
    public static function defaultAmount(Party $party, ?array $state = null): float
    {
        $state ??= self::state($party);
        foreach ($state['periods'] as $v) {
            if ($v['remaining'] >= 0.01) {
                return $v['remaining'];
            }
        }

        return self::forecast($party, $state)['net'];
    }

    /** Henüz yazılmamış sıradaki maaş: maaş − avans/kesinti = net (hatırlatma "tahmini"). */
    public static function forecast(Party $party, ?array $state = null, ?Carbon $due = null): array
    {
        $state ??= self::state($party);
        $due ??= self::nextDue($party);
        $period = $due->format('Y-m');
        $salary = self::salaryFor($party, $period);
        $off = min($salary, $state['advance']);

        return ['period' => $period, 'due' => $due, 'salary' => $salary, 'off' => round($off, 2), 'net' => round($salary - $off, 2),
            'extra' => round(max(0.0, $state['advance'] - $salary), 2)];
    }

    /**
     * Yeni ödemenin dağılımı (kart = kayıt). Satırlar kartta aynen gösterilir; description/period kayda yazılır; fingerprint
     * onay anında yeniden hesaplanıp karşılaştırılır (arada başka kayıt girdiyse yazılmaz, yeni kart).
     *
     * @return array{lines: list<string>, description: string, advance: float, fingerprint: string, state: array}
     */
    public static function preview(Party $party, float $amount, ?string $target, string $date): array
    {
        $before = self::state($party);
        $after = self::state($party, ['amount' => $amount, 'target' => $target, 'date' => $date]);
        $lines = [];
        $paidLabels = [];
        foreach ($after['periods'] as $p => $v) {
            $paid = round($v['paid'] - ($before['periods'][$p]['paid'] ?? 0.0), 2);
            if ($paid < 0.01) {
                continue;
            }
            $paidLabels[] = $p;
            $lines[] = '• ' . self::label($p, false) . ' maaşı: ' . ($v['remaining'] < 0.01
                ? 'kapandı ✅' : Money::format($paid) . ' ₺ ödendi, kalan ' . Money::format($v['remaining']) . ' ₺');
        }
        $legacyPaid = round($before['legacy_open'] - $after['legacy_open'], 2);
        if ($legacyPaid >= 0.01) {
            $lines[] = '• ' . self::legacyLabel($before) . ' maaşı: ' . ($after['legacy_open'] < 0.01
                ? 'kapandı ✅' : Money::format($legacyPaid) . ' ₺ ödendi, kalan ' . Money::format($after['legacy_open']) . ' ₺');
            $paidLabels[] = $target;
        }
        $advance = round($after['advance'] - $before['advance'], 2);
        if ($advance >= 0.01) {
            $next = self::nextDue($party, Carbon::parse($date, \App\Models\Reminder::TIMEZONE));
            $lines[] = '• ' . Money::format($advance) . ' ₺ avans — ' . self::label($next->format('Y-m'), false) . ' maaşından düşülür';
        }
        if ($after['legacy_open'] >= 0.01) {
            $lines[] = 'ℹ️ ' . self::legacyFrom($after) . ' ' . Money::format($after['legacy_open'])
                . ' ₺ açık görünüyor (bu ödemeye katmadım)';
        }
        $paidLabels = array_values(array_filter(array_unique($paidLabels)));
        $description = $paidLabels === [] ? 'Avans' : 'Maaş ödemesi — ' . self::monthsText($paidLabels)
            . ($advance >= 0.01 && $paidLabels !== [] ? ' (' . Money::format($advance) . ' avans)' : '');

        return ['lines' => $lines, 'description' => $description, 'advance' => $advance,
            'fingerprint' => md5(json_encode([round($amount, 2), $target, $lines])), 'state' => $after];
    }

    /** Türkçe ek: "15 Kasım" → "15 Kasım'da", "Aralık" → "Aralık'ta" (bulunma); ayrılma için 'dan'. */
    public static function suffix(string $word, string $case = 'da'): string
    {
        $w = mb_strtolower(strtr($word, ['I' => 'ı', 'İ' => 'i']), 'UTF-8');
        preg_match_all('/[aıoueiöü]/u', $w, $m);
        $vowel = end($m[0]) ?: 'a';
        $back = in_array($vowel, ['a', 'ı', 'o', 'u'], true);
        $hard = (bool) preg_match('/[fstkçşhp]$/u', $w);
        $d = $hard ? 't' : 'd';

        return $word . "'" . $d . ($back ? 'a' : 'e') . ($case === 'dan' ? 'n' : '');
    }

    /** "Ekim'den" / "Kasım öncesinden" — Kasım öncesinden açık kalan maaş. */
    public static function legacyFrom(array $state): string
    {
        $p = $state['legacy_periods'];

        return count($p) === 1 ? self::suffix(self::label($p[0], false), 'dan') : self::label(self::BOUNDARY, false) . ' öncesinden';
    }

    /** "Ekim" / "Kasım öncesi" — Kasım öncesinden açık kalan maaşın adı. */
    public static function legacyLabel(array $state): string
    {
        $p = $state['legacy_periods'];

        return count($p) === 1 ? self::label($p[0], false) : self::label(self::BOUNDARY, false) . ' öncesi';
    }

    /** ["2026-10","2026-11"] → "Ekim + Kasım 2026"; tek ay "Kasım 2026". */
    private static function monthsText(array $periods): string
    {
        sort($periods);
        $years = array_unique(array_map(fn ($p) => substr($p, 0, 4), $periods));
        if (count($periods) === 1) {
            return self::label($periods[0]);
        }

        return count($years) === 1
            ? implode(' + ', array_map(fn ($p) => self::label($p, false), $periods)) . ' ' . $years[0]
            : implode(' + ', array_map(fn ($p) => self::label($p), $periods));
    }

    /** Maaşı etkileyen yazımlar aynı kişi kilidiyle (aynı anda iki iş çift yazmasın). */
    public static function locked(Party $party, Closure $fn): mixed
    {
        return DB::transaction(function () use ($party, $fn) {
            Party::whereKey($party->id)->lockForUpdate()->first();

            return $fn();
        });
    }

    /**
     * Aylık sonuç için maaş satırının sayıldığı gün (kullanıcı 07.11: maaş kendi ayında görünür). Satır kendi ayında girildiyse o gün;
     * başka ayda girildiyse ("Kasım maaşı" 30 Ekim'de) o ayın maaş günü.
     */
    public static function effectiveDate(PartyLedgerEntry $e, ?Party $party = null): ?Carbon
    {
        $date = $e->entry_date ? Carbon::parse($e->entry_date->toDateString()) : null;
        $period = self::accrualPeriod($e);
        if ($period === null || $date === null || $date->format('Y-m') === $period) {
            return $date;
        }
        $first = Carbon::parse($period . '-01');

        return $first->day(min(max(1, (int) ($party?->salary_day ?: 1)), $first->daysInMonth));
    }

    /** @return Collection<int, Party> */
    public static function eligibleEmployees(): Collection
    {
        return Party::where('is_employee', true)->where('salary_auto', true)->where('salary_amount', '>', 0)->whereNotNull('salary_day')->get();
    }
}
