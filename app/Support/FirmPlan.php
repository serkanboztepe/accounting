<?php

namespace App\Support;

use App\Models\HubFirm;
use App\Models\HubLedgerEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Firma aboneliği (küçük versiyon, 29.10): deneme / aylık / yıllık / ücretsiz. Dönem = aylıkta takvim ayı (ödeme günü plan_day),
 * yıllıkta plan_started_on'dan 12 ay. Ödendi mi = o dönemi kapsayan "payment" satırı var mı. Kapatma ELLE (suspend).
 */
final class FirmPlan
{
    public const PLANS = ['trial' => 'Deneme', 'monthly' => 'Aylık', 'yearly' => 'Yıllık', 'free' => 'Ücretsiz'];

    /** Durdurulan firmaya WhatsApp'ta ve girişte gösterilen metin. */
    public const SUSPENDED_REPLY = "Merhaba! 👋 *Hesap Asistanım* hesabın ödeme beklediği için şu an durduruldu.\n"
        . "Kayıtların duruyor, hiçbiri silinmedi — ödeme yapılınca kaldığın yerden devam edersin.\n"
        . "✅ Ödeme ve sorular için: wa.me/905453606783";

    public static function label(HubFirm $firm): string
    {
        return self::PLANS[$firm->plan] ?? '—';
    }

    public static function isPaidPlan(HubFirm $firm): bool
    {
        return in_array($firm->plan, ['monthly', 'yearly'], true) && (float) $firm->plan_amount > 0 && $firm->plan_started_on;
    }

    /**
     * Başlangıçtan $until'e kadar ödeme günü gelmiş ya da gelecek dönemler: [başlangıç, bitiş, ödeme günü].
     *
     * @return list<array{0: Carbon, 1: Carbon, 2: Carbon}>
     */
    public static function periods(HubFirm $firm, CarbonInterface $until): array
    {
        if (! self::isPaidPlan($firm)) {
            return [];
        }
        $out = [];
        $start = Carbon::parse($firm->plan_started_on)->startOfDay();
        for ($i = 0; $i < 240; $i++) {
            if ($firm->plan === 'yearly') {
                $from = $start->copy()->addYearsNoOverflow($i);
                $to = $from->copy()->addYearNoOverflow()->subDay();
                $due = $from->copy();
            } else {
                $from = $start->copy()->startOfMonth()->addMonthsNoOverflow($i);
                $to = $from->copy()->endOfMonth()->startOfDay();
                $day = (int) ($firm->plan_day ?: $start->day);
                $due = $from->copy()->setDay(min(max(1, $day), $from->daysInMonth));
            }
            if ($from->gt($until)) {
                break;
            }
            $out[] = [$from, $to, $due];
        }

        return $out;
    }

    /** O dönemi kapsayan ödeme var mı. */
    public static function isPaid(HubFirm $firm, CarbonInterface $from): bool
    {
        return HubLedgerEntry::where('kind', HubLedgerEntry::PAYMENT)->where('hub_firm_id', $firm->id)
            ->whereDate('starts_on', '<=', $from->toDateString())
            ->whereDate('ends_on', '>=', $from->toDateString())->exists();
    }

    /** Sıradaki ödenmemiş dönem (gecikmiş olan önce) — "Ödedi" düğmesi bunu kapatır. */
    public static function nextUnpaid(HubFirm $firm): ?array
    {
        foreach (self::periods($firm, now()->addYear()) as $p) {
            if (! self::isPaid($firm, $p[0])) {
                return $p;
            }
        }

        return null;
    }

    /** "Kasım 2026" / "01.10.2026 – 30.09.2027". */
    public static function periodLabel(HubFirm $firm, array $p): string
    {
        return $firm->plan === 'yearly'
            ? $p[0]->format('d.m.Y') . ' – ' . $p[1]->format('d.m.Y')
            : $p[0]->locale('tr')->translatedFormat('F Y');
    }

    /**
     * Firmanın bugünkü ödeme durumu: [renk, metin, gecikme günü].
     *
     * @return array{0: string, 1: string, 2: int}
     */
    public static function status(HubFirm $firm): array
    {
        // İş günü İstanbul; tarih kolonları saatsiz — gün olarak karşılaştır (UTC/İstanbul kayması olmasın).
        $today = Carbon::parse(now('Europe/Istanbul')->toDateString());
        if ($firm->suspended_at) {
            return ['danger', '⛔ Ödeme yok — durduruldu', 0];
        }

        return match ($firm->plan) {
            'free' => ['gray', 'Ücretsiz', 0],
            'trial' => self::trialStatus($firm, $today),
            'monthly', 'yearly' => self::paidStatus($firm, $today),
            default => ['gray', 'Plan girilmedi', 0],
        };
    }

    private static function trialStatus(HubFirm $firm, Carbon $today): array
    {
        if (! $firm->trial_ends_on) {
            return ['info', '🧪 Deneme', 0];
        }
        $left = (int) $today->diffInDays(Carbon::parse($firm->trial_ends_on)->startOfDay(), false);

        return $left >= 0 ? ['info', '🧪 Deneme — ' . ($left === 0 ? 'bugün bitiyor' : $left . ' gün kaldı'), 0]
            : ['warning', '🧪 Deneme bitti (' . abs($left) . ' gün önce)', abs($left)];
    }

    private static function paidStatus(HubFirm $firm, Carbon $today): array
    {
        if (! self::isPaidPlan($firm)) {
            return ['gray', 'Ücret / başlangıç girilmedi', 0];
        }
        $current = null;
        foreach (self::periods($firm, $today) as $p) {
            if ($p[2]->lt($today) && ! self::isPaid($firm, $p[0])) {
                $late = (int) $p[2]->diffInDays($today);

                return ['danger', '🔴 ' . self::periodLabel($firm, $p) . ' gecikti (' . $late . ' gün)', $late];
            }
            $current = $p;
        }
        if (! $current) {
            return ['gray', 'Başlangıç ' . Carbon::parse($firm->plan_started_on)->format('d.m.Y'), 0];
        }

        return self::isPaid($firm, $current[0])
            ? ['success', '✅ ' . self::periodLabel($firm, $current) . ' ödendi', 0]
            : ['warning', '⏳ ' . self::periodLabel($firm, $current) . ' — ' . $current[2]->format('d.m') . "'de", 0];
    }

    /** Ayın gelirleri (TL): [ödenen (yıllık 12'ye bölünmüş), bekleyen (ödenmemiş dönem payı)]. */
    public static function monthRevenue(HubFirm $firm, CarbonInterface $month): array
    {
        $m = Carbon::parse($month)->startOfMonth();
        $paid = HubLedgerEntry::where('kind', HubLedgerEntry::PAYMENT)->where('hub_firm_id', $firm->id)
            ->whereDate('starts_on', '<=', $m->copy()->endOfMonth()->toDateString())
            ->whereDate('ends_on', '>=', $m->toDateString())->get()
            ->sum(fn (HubLedgerEntry $e) => $e->amount / $e->coveredMonths());

        $pending = 0.0;
        foreach (self::periods($firm, $m->copy()->endOfMonth()) as $p) {
            $inMonth = $p[0]->copy()->startOfMonth()->lte($m) && $p[1]->copy()->startOfMonth()->gte($m);
            if ($inMonth && ! self::isPaid($firm, $p[0])) {
                $pending += $firm->plan === 'yearly' ? (float) $firm->plan_amount / 12 : (float) $firm->plan_amount;
            }
        }

        return [round($paid, 2), round($pending, 2)];
    }
}
