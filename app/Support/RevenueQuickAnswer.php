<?php

namespace App\Support;

use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Models\Sale;
use Illuminate\Support\Carbon;

/**
 * "Dünkü ciro" / "bugünkü ciro" — AI'sız, anında cevap (kullanıcı kararı 23.11: Akkuş her gün soruyor, ajan turu ~0,30 $).
 * Yalnız mesajın TAMAMI bu sorulardan biriyse; başka bir şey eklenmişse ("dünkü ciro Araştırma", "geçen hafta") ajana gider.
 * Rakamlar MonthlyResult::forRange'den — panel, ajan ve haftalık özetle aynı.
 */
final class RevenueQuickAnswer
{
    private const PATTERN = '/^(dün|dun|bugün|bugun)\s?(kü|ku|ki|ün|un)?\s+(ciro|cirosu)$/u';

    /** Mesaj ciro sorusuysa gün, değilse null. */
    public static function day(string $body): ?Carbon
    {
        $text = mb_strtolower(strtr($body, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', preg_replace('/^[\s\p{P}]+|[\s\p{P}]+$/u', '', $text) ?? $text) ?? $text;
        if (preg_match(self::PATTERN, $text, $m) !== 1) {
            return null;
        }
        $today = now('Europe/Istanbul')->startOfDay();
        if (in_array($m[1], ['dün', 'dun'], true)) {
            return $today->subDay();
        }

        // Gece 00:00–05:00 "bugün" = biten iş günü (fırın gece kapanır; gece girilen kasa da o güne yazılır — TradeDraft ile aynı).
        return now('Europe/Istanbul')->hour < 5 ? $today->subDay() : $today;
    }

    public static function answer(string $body): ?string
    {
        $day = self::day($body);
        if (! $day) {
            return null;
        }
        $isToday = preg_match('/bug[üu]n/u', mb_strtolower($body, 'UTF-8')) === 1;
        $night = $isToday && ! $day->isSameDay(now('Europe/Istanbul'));

        return self::text($day, $isToday) . ($night
            ? "\n_Gece yarısını geçtiği için dünü (" . $day->copy()->locale('tr')->translatedFormat('j F') . ') gösterdim._' : '');
    }

    public static function text(Carbon $day, ?bool $isToday = null): string
    {
        $isToday ??= $day->isSameDay(now('Europe/Istanbul'));
        $title = ($isToday ? 'Bugünkü' : 'Dünkü') . ' ciro (' . $day->copy()->locale('tr')->translatedFormat('j F') . ')';
        $all = MonthlyResult::forRange($day, $day);
        if (abs($all['revenue']) < 0.01) {
            return '📊 ' . $title . ': satış yazılmamış.';
        }

        $lines = ['📊 *' . $title . ': ' . Money::format($all['revenue'], 0) . ' ₺*'];
        $listed = 0.0;
        if (Features::places()) {
            foreach (Project::orderBy('name')->get(['id', 'name']) as $branch) {
                $r = MonthlyResult::forRange($day, $day, $branch->id);
                if (abs($r['revenue']) < 0.01) {
                    continue;
                }
                $lines[] = '• ' . $branch->name . ': ' . Money::format($r['revenue'], 0) . ' ₺' . self::cashCard($r['cash'], $r['bank']);
                $listed += $r['revenue'];
            }
        }
        // Yere yazılmamış kişiye satışlar (veresiye ya da peşin): kişi kişi.
        $placeFilter = fn ($q) => Features::places() ? $q->whereNull('project_id') : $q;
        $date = $day->toDateString();
        $byParty = [];
        $ledger = $placeFilter(PartyLedgerEntry::where('type', PartyLedgerEntry::TYPE_SALE)->whereNull('sale_id')
            ->withoutCarriedBalances()->whereDate('entry_date', $date))->with('party:id,name')->get();
        foreach ($ledger as $e) {
            $name = $e->party?->name ?? '—';
            $byParty[$name] = ($byParty[$name] ?? 0) + (float) $e->amount;
        }
        foreach ($placeFilter(Sale::whereNotNull('party_id')->whereDate('sale_date', $date))->with('party:id,name')->get() as $s) {
            $name = $s->party?->name ?? '—';
            $byParty[$name] = ($byParty[$name] ?? 0) + (float) $s->total_amount;
        }
        foreach ($byParty as $name => $amount) {
            $lines[] = '• Satış — ' . $name . ': ' . Money::format($amount, 0) . ' ₺';
            $listed += $amount;
        }
        // Kalan (yere yazılmamış kasa, iade…): toplam her zaman tutsun.
        $rest = round($all['revenue'] - $listed, 2);
        if (abs($rest) >= 0.01) {
            $lines[] = '• ' . (Features::places() ? 'Şubesiz / diğer' : 'Kasa / diğer') . ': ' . Money::format($rest, 0) . ' ₺';
        }
        if ($all['cash'] + $all['bank'] > 0) {
            $lines[] = 'Nakit ' . Money::format($all['cash'], 0) . ' ₺ · Kart ' . Money::format($all['bank'], 0) . ' ₺';
        }

        return implode("\n", $lines);
    }

    private static function cashCard(float $cash, float $bank): string
    {
        return $cash + $bank > 0 ? ' (nakit ' . Money::format($cash, 0) . ' · kart ' . Money::format($bank, 0) . ')' : '';
    }
}
