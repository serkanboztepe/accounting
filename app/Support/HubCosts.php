<?php

namespace App\Support;

use App\Models\AiCredit;
use App\Models\ConversationReport;
use App\Models\DevUsage;
use App\Models\HubFirm;
use Illuminate\Support\Facades\Cache;

/** Hub maliyet hesabı: firma başına bu ay (WhatsApp + AI + numara payı) ve toplu tablo (analiz + yerel denemeler dahil). */
class HubCosts
{
    /** Bir firmanın bu ayı: hub'daki mesaj sayıları + firmadan çekilen AI/şablon maliyeti (5 dk önbellek). */
    public static function firmMonth(HubFirm $firm): array
    {
        $month = now()->format('Y-m');
        $logs = $firm->messageLogs()->where('created_at', '>=', now()->startOfMonth());
        $in = (clone $logs)->where('direction', 'in')->count();
        $out = (clone $logs)->where('direction', 'out')->count();

        $usage = Cache::remember("hub-usage:{$firm->id}:{$month}", 300, fn () => $firm->fetchUsage($month));

        $templates = (int) ($usage['templates'] ?? 0);
        $msgCost = ($in + $out) * config('costs.twilio_per_message');
        $templateCost = (float) ($usage['template_cost_usd'] ?? 0);
        $waCost = $msgCost + $templateCost;

        // Numara kirası tüm firmalara ortak → aktif firma sayısına bölünür.
        $activeFirms = max(1, HubFirm::where('is_active', true)->count());
        $numberShare = $firm->is_active ? config('costs.twilio_number_monthly') / $activeFirms : 0.0;

        return [
            'monthLabel' => now()->locale('tr')->translatedFormat('F Y'),
            'in' => $in,
            'out' => $out,
            'templates' => $templates,
            'usage' => $usage,
            'aiCost' => (float) ($usage['ai_cost_usd'] ?? 0),
            'msgCost' => $msgCost,
            'templateCost' => $templateCost,
            'waCost' => $waCost,
            'numberShare' => $numberShare,
            'activeFirms' => $activeFirms,
            'total' => $waCost + $numberShare + (float) ($usage['ai_cost_usd'] ?? 0),
        ];
    }

    /** Toplu tablo: her firma + sabah analizi + yerel denemeler; AI ve genel toplam. */
    public static function overview(): array
    {
        $rows = HubFirm::orderBy('name')->get()
            ->map(fn (HubFirm $f) => ['name' => $f->name, 'active' => $f->is_active] + self::firmMonth($f))
            ->all();

        $start = now()->startOfMonth();
        $analysis = (float) ConversationReport::where('created_at', '>=', $start)->sum('cost_usd');
        $dev = DevUsage::where('created_at', '>=', $start);
        $devCost = (float) (clone $dev)->sum('cost_usd');
        $devCalls = (clone $dev)->count();

        $firmsAi = array_sum(array_column($rows, 'aiCost'));
        // Önbellek isabeti: talimatın ne kadarı %10 fiyatla önbellekten okundu (düşerse sabit kısma değişen bir şey girmiştir).
        $tok = fn (string $k) => array_sum(array_map(fn ($r) => (int) ($r['usage'][$k] ?? 0), $rows));
        $read = $tok('ai_cache_read_tokens');
        $allInput = $tok('ai_input_tokens') + $read + $tok('ai_cache_write_tokens');
        $firmsTotal = array_sum(array_column($rows, 'total'));

        return [
            'monthLabel' => now()->locale('tr')->translatedFormat('F Y'),
            'credit' => self::credit(),
            'twilio' => self::twilioBalance(),
            'rows' => $rows,
            'analysis' => $analysis,
            'devCost' => $devCost,
            'devCalls' => $devCalls,
            'cacheHit' => $allInput > 0 ? $read / $allInput : null,
            'aiTotal' => $firmsAi + $analysis + $devCost,
            'grandTotal' => $firmsTotal + $analysis + $devCost,
            'sum' => [
                'ai' => $firmsAi + $analysis + $devCost,
                'msgs' => array_sum(array_map(fn ($r) => $r['in'] + $r['out'], $rows)),
                'msgCost' => array_sum(array_column($rows, 'msgCost')),
                'templates' => array_sum(array_column($rows, 'templates')),
                'templateCost' => array_sum(array_column($rows, 'templateCost')),
                'numberShare' => array_sum(array_column($rows, 'numberShare')),
            ],
        ];
    }

    /**
     * Tahmini kalan Anthropic bakiyesi: son girilen bakiye − o andan beri tüm AI harcaması
     * (firmalar + sabah analizi + yerel denemeler). Girilmemişse null.
     *
     * @return array{entered:float,at:\Illuminate\Support\Carbon,spent:float,remaining:float}|null
     */
    public static function credit(): ?array
    {
        $credit = AiCredit::latest('id')->first();
        if (! $credit) {
            return null;
        }
        $since = $credit->created_at;
        $spent = HubFirm::all()->sum(fn (HubFirm $f) => (float) $f->aiCostSince($since))
            + (float) ConversationReport::where('created_at', '>=', $since)->sum('cost_usd')
            + (float) DevUsage::where('created_at', '>=', $since)->sum('cost_usd');

        return ['entered' => $credit->balance_usd, 'at' => $since, 'spent' => $spent, 'remaining' => $credit->balance_usd - $spent];
    }

    /** Twilio bakiyesi (API veriyor — gerçek tutar), 10 dk önbellek; ulaşılamazsa null. */
    public static function twilioBalance(): ?float
    {
        $sid = (string) config('services.twilio.sid');
        $token = (string) config('services.twilio.token');
        if ($sid === '' || $token === '') {
            return null;
        }

        return Cache::remember('hub-twilio-balance', 600, function () use ($sid, $token) {
            try {
                $r = \Illuminate\Support\Facades\Http::timeout(5)->withBasicAuth($sid, $token)
                    ->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Balance.json");

                return $r->successful() ? (float) $r->json('balance') : null;
            } catch (\Throwable) {
                return null;
            }
        });
    }
}
