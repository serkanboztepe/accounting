<?php

namespace App\Support;

use App\Models\DevUsage;
use App\Models\HubFirm;
use App\Models\HubLedgerEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Hub Rapor (29.10): ay ay firma kârlılığı, TL. Gelir = o aya düşen ödenmiş abonelik (yıllık 12'ye bölünür), ayrıca bekleyen.
 * Gider = yapay zekâ + WhatsApp mesajı + şablon (firma kendi) + sabit giderlerin eşit payı (o ay aktif firmalara bölünür).
 * Geliştirme (yerel denemeler) firmalara yazılmaz, ayrı satır. Kredi yüklemesi gider DEĞİL — yalnız bilgi satırı.
 * Kullanıcı kararı: rapor Kasım 2026'dan (önceki aylarda geliştirme masrafı karışık). Kapanmış ay önbellekte (para satırı / plan
 * değişince sürüm artar, yeniden hesaplanır).
 */
final class HubReport
{
    public const START = '2026-11';

    /** @return list<string> "2026-11", … en yeni başta (başlangıç gelmediyse yalnız başlangıç ayı) */
    public static function months(): array
    {
        $last = max(now('Europe/Istanbul')->format('Y-m'), self::START);
        $out = [];
        for ($m = Carbon::parse($last . '-01'); $m->format('Y-m') >= self::START; $m->subMonthNoOverflow()) {
            $out[] = $m->format('Y-m');
        }

        return $out;
    }

    public static function isCurrent(string $month): bool
    {
        return $month >= now('Europe/Istanbul')->format('Y-m');
    }

    /** Ayın tam raporu (önbellekli; içinde bulunulan ay 5 dk). */
    public static function month(string $month): array
    {
        $key = 'hub-report:' . $month . ':v' . Cache::get('hub-report-version', 0);

        return self::isCurrent($month)
            ? Cache::remember($key, 300, fn () => self::compute($month))
            : Cache::rememberForever($key, fn () => self::compute($month));
    }

    public static function compute(string $month): array
    {
        $m = Carbon::parse($month . '-01', 'Europe/Istanbul')->startOfMonth();
        $from = $m->copy()->setTimezone(config('app.timezone'));
        $to = $m->copy()->endOfMonth()->setTimezone(config('app.timezone'));
        $rates = ExchangeRates::forMonth($m);
        $tl = fn (float $v, string $cur) => ExchangeRates::toTry($v, $cur, $rates);
        $perMessage = (float) config('costs.twilio_per_message');

        $fixed = HubLedgerEntry::fixedIn($m)->orderBy('title')->get()->map(fn (HubLedgerEntry $e) => [
            'title' => $e->title, 'amount' => $e->amount, 'currency' => $e->currency, 'period' => $e->period,
            'monthly_try' => $tl($e->monthlyAmount(), $e->currency),
        ])->all();
        $fixedTry = array_sum(array_map(fn ($f) => (float) $f['monthly_try'], $fixed));

        $firms = HubFirm::orderBy('name')->get()->filter(fn (HubFirm $f) => $f->created_at === null || $f->created_at->lte($to))
            ->filter(fn (HubFirm $f) => $f->is_active || $f->messageLogs()->whereBetween('created_at', [$from, $to])->exists())->values();
        $share = $firms->isEmpty() ? 0.0 : $fixedTry / $firms->count();

        $rows = $firms->map(function (HubFirm $f) use ($m, $from, $to, $tl, $perMessage, $share, $month) {
            $logs = $f->messageLogs()->whereBetween('created_at', [$from, $to]);
            $msgs = (clone $logs)->count();
            $usage = $f->fetchUsage($month) ?? [];
            $aiTry = $tl((float) ($usage['ai_cost_usd'] ?? 0), 'USD');
            $waTry = $tl($msgs * $perMessage + (float) ($usage['template_cost_usd'] ?? 0), 'USD');
            [$paid, $pending] = FirmPlan::monthRevenue($f, $m);
            $cost = ($aiTry ?? 0) + ($waTry ?? 0) + $share;

            return ['id' => $f->id, 'name' => $f->name, 'plan' => FirmPlan::label($f), 'paid' => $paid, 'pending' => $pending,
                'ai' => $aiTry, 'wa' => $waTry, 'msgs' => $msgs, 'fixed' => $share, 'cost' => $cost, 'profit' => $paid - $cost];
        })->all();

        $devUsd = (float) DevUsage::where('source', '!=', HubCosts::ANALYSIS_SOURCE)->whereBetween('created_at', [$from, $to])->sum('cost_usd');
        $dev = $tl($devUsd, 'USD') ?? 0.0;

        $topups = HubLedgerEntry::where('kind', HubLedgerEntry::TOPUP)
            ->whereBetween('starts_on', [$m->toDateString(), $m->copy()->endOfMonth()->toDateString()])->orderBy('starts_on')->get()
            ->map(fn (HubLedgerEntry $e) => ['provider' => $e->provider, 'amount' => $e->amount, 'currency' => $e->currency,
                'date' => $e->starts_on->format('d.m'), 'note' => $e->note])->all();

        $sum = fn (string $k) => array_sum(array_map(fn ($r) => (float) $r[$k], $rows));
        $income = $sum('paid');
        $cost = $sum('cost') + $dev;

        return [
            'month' => $month,
            'label' => $m->locale('tr')->translatedFormat('F Y'),
            'current' => self::isCurrent($month),
            'rates' => $rates,
            'rows' => $rows,
            'dev' => $dev,
            'fixed' => $fixed,
            'topups' => $topups,
            'total' => ['paid' => $income, 'pending' => $sum('pending'), 'ai' => $sum('ai'), 'wa' => $sum('wa'), 'msgs' => (int) $sum('msgs'),
                'fixed' => $sum('fixed'), 'cost' => $cost, 'profit' => $income - $cost],
        ];
    }

    /** Ay listesi satırları: gelir, bekleyen, gider, kâr. */
    public static function summary(): array
    {
        return array_map(function (string $month) {
            $r = self::month($month);

            return ['month' => $month, 'label' => $r['label'], 'current' => $r['current'], 'started' => $month <= now('Europe/Istanbul')->format('Y-m')]
                + $r['total'];
        }, self::months());
    }
}
