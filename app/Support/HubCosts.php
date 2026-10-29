<?php

namespace App\Support;

use App\Models\AiCredit;
use App\Models\DevUsage;
use App\Models\HubFirm;
use App\Models\HubLedgerEntry;
use Illuminate\Support\Facades\Cache;

/** Hub maliyet hesabı: firma sayfasında bu ayın kullanımı (WhatsApp + AI), Anthropic kalan tahmini, Twilio bakiyesi. Ay ay kâr: HubReport. */
class HubCosts
{
    /** hub_dev_usage.source: kaldırılan sabah analizinin (conversations:analyze) taşınan maliyeti. */
    public const ANALYSIS_SOURCE = 'morning_analysis';

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

        // Sabit giderler (numara, sunucu…) 29.10'dan beri Rapor'da (hub_ledger, TL) — burada yalnız firmanın kendi kullanımı.

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
            'total' => $waCost + (float) ($usage['ai_cost_usd'] ?? 0),
        ];
    }

    /** Toplu tablo: her firma + sabah analizi + yerel denemeler; AI ve genel toplam. */
    /**
     * Tahmini kalan Anthropic bakiyesi: son girilen bakiye + sonraki yüklemeler − o andan beri tüm AI harcaması
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
            + (float) DevUsage::where('created_at', '>=', $since)->sum('cost_usd'); // eski sabah analizleri de burada
        // "Kredi yükledim" (29.10): bakiye girildikten sonraki yüklemeler kalana eklenir — Billing sayfasına bakmaya gerek kalmasın.
        $topups = (float) HubLedgerEntry::where('kind', HubLedgerEntry::TOPUP)->where('provider', 'anthropic')
            ->where('created_at', '>', $since)->sum('amount');

        return ['entered' => $credit->balance_usd, 'at' => $since, 'spent' => $spent, 'topups' => $topups,
            'remaining' => $credit->balance_usd + $topups - $spent];
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
