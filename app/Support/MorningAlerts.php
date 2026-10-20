<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\HubPhone;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Models\Sale;
use App\Models\WhatsappPendingExpense;
use App\Tenancy\Tenancy;
use Illuminate\Support\Carbon;

/**
 * Faz 4 (B) — sabah 09:00 kendiliğinden haber (modül `proactive_alerts`, firma firma açılır). Kişi başına günde EN ÇOK 1
 * mesaj; "hatırlatma alsın" kapalı numaraya gitmez. Yalnız okuma — kayıt açmaz.
 *  - GİRİLMEYEN KASA: son 7 günün en az 4'ünde kasası (ürünsüz günlük ciro) girilen şube (ya da tek kasa) dün girilmediyse
 *    ("Dün Ergenekon kasası girilmedi"); o şubeyi kim giriyorsa ona.
 *  - PAZARTESİ HAFTALIK ÖZET: geçen haftanın ciro / alış / gider / kalan, şube şube ciro, bu hafta vadesi gelenler.
 *  - AÇIK AVANS (Faz G, 16.10): verilen/alınan avansın karşılığı (iş, mal) kaydedilmediyse 14. ve 30. gün, sonra ayda bir sorulur —
 *    yoksa bakiye sonsuza kadar "o size borçlu" görünür (Taner/asansör, Erkan/peynir). Personel avansı hariç (maaştan düşer).
 */
final class MorningAlerts
{
    private const HABIT_DAYS = 7;

    private const HABIT_MIN = 4;

    /** @return array<string, list<string>> numara → satırlar */
    public static function build(Carbon $today): array
    {
        $out = [];
        $phones = self::recipients();
        if ($phones === []) {
            return [];
        }
        foreach (self::missingTills($today->copy()->subDay()) as [$line, $owner]) {
            foreach ($owner && in_array($owner, $phones, true) ? [$owner] : $phones as $p) {
                $out[$p][] = $line;
            }
        }
        foreach (self::openAdvances($today) as $line) {
            foreach ($phones as $p) {
                $out[$p][] = $line;
            }
        }
        // Haftalık özet "ciro − alış − gider" hesabını kullanır: yalnız Ay Sonucu'nu kullanan firmada (müteahhitte yanıltır).
        if ($today->isMonday() && config('modules.report_monthly') && ($weekly = self::weekly($today))) {
            foreach ($phones as $p) {
                $out[$p][] = $weekly;
            }
        }

        return $out;
    }

    /** @return list<array{0:string,1:?string}> [satır, o kasayı giren numara] */
    public static function missingTills(Carbon $day): array
    {
        if (! config('modules.sales_desk')) {
            return [];
        }
        $from = $day->copy()->subDays(self::HABIT_DAYS)->toDateString();
        $to = $day->copy()->subDay()->toDateString();
        $branches = config('modules.projects') ? Project::whereNotIn('status', ['cancelled'])->get(['id', 'name']) : collect([null]);
        $lines = [];
        foreach ($branches as $branch) {
            // "Kasa" = ürünsüz günlük ciro (peşin, kalemsiz: "Ergenekon kasa nakit … pos …"). Ürünlü peşin satış (saman, yem,
            // un) kasa alışkanlığı DEĞİL — çiftliğe/toptancıya "kasa girilmedi" denmesin.
            $q = fn () => Sale::whereNull('party_id')->whereDoesntHave('lines')->when($branch, fn ($w) => $w->where('project_id', $branch->id));
            $habit = $q()->whereBetween('sale_date', [$from, $to])->distinct()->count('sale_date');
            if ($habit < self::HABIT_MIN || $q()->whereDate('sale_date', $day->toDateString())->exists()) {
                continue;
            }
            $label = $branch ? $branch->name . ' kasası' : 'Kasa';
            $lines[] = ['🧾 Dün (' . $day->locale('tr')->translatedFormat('j F') . ') ' . $label . ' girilmedi — yazman yeterli (ör. *'
                . ($branch ? $branch->name . ' ' : '') . 'kasa nakit … pos …*).', $branch ? self::tillOwner($branch->id) : null];
        }

        return $lines;
    }

    /** Pazartesi: geçen haftanın sonucu + şube ciroları + bu hafta vadesi gelenler. */
    public static function weekly(Carbon $monday): ?string
    {
        $from = $monday->copy()->subWeek()->startOfDay();
        $to = $monday->copy()->subDay()->startOfDay();
        $r = MonthlyResult::forRange($from, $to);
        if ($r['revenue'] + $r['purchases'] + $r['expenses'] + ($r['personnel'] ?? 0) <= 0) {
            return null;
        }
        $lines = ['📅 *Geçen hafta (' . MonthlyResult::rangeLabel($from, $to) . ')*',
            'Ciro: ' . Money::format($r['revenue']) . ' ₺ · Alış: ' . Money::format($r['purchases']) . ' ₺'
                . (config('modules.expenses') ? ' · Gider: ' . Money::format($r['expenses']) . ' ₺' : '')
                . (($r['personnel'] ?? 0) > 0 ? ' · Personel: ' . Money::format($r['personnel']) . ' ₺' : ''),
            ($r['result'] >= 0 ? '✅ Kalan: ' : '🔻 Eksi: ') . Money::format(abs($r['result'])) . ' ₺'];
        if (config('modules.projects')) {
            $byBranch = Project::whereNotIn('status', ['cancelled'])->get(['id', 'name'])
                ->map(fn ($p) => [$p->name, MonthlyResult::forRange($from, $to, $p->id)['revenue']])
                ->filter(fn ($x) => $x[1] > 0)->sortByDesc(fn ($x) => $x[1]);
            if ($byBranch->count() > 1) {
                $lines[] = Labels::project() . ' ciroları: ' . $byBranch->map(fn ($x) => $x[0] . ' ' . Money::format($x[1]))->implode(' · ');
            }
        }
        $due = self::dueThisWeek($monday);
        if ($due !== []) {
            $lines[] = '⏰ Bu hafta vadesi gelen: ' . implode(' · ', $due);
        }

        return implode("\n", $lines);
    }

    /**
     * Karşılığı kaydedilmemiş avanslar: son avans 14 ya da 30 gün önceyse, sonra her 30 günde bir (durumsuz, tekrar etmez). En çok 3.
     *
     * @return list<string>
     */
    public static function openAdvances(Carbon $today): array
    {
        $lines = [];
        $rows = PartyLedgerEntry::with('party')->whereIn('type', [PartyLedgerEntry::TYPE_PAYMENT, PartyLedgerEntry::TYPE_COLLECTION])
            ->advance()->orderByDesc('entry_date')->get()->groupBy(fn ($e) => $e->party_id . ':' . $e->type);
        foreach ($rows as $entries) {
            $last = $entries->first();
            $party = $last->party;
            if (! $party || $party->is_employee) {
                continue;
            }
            $days = (int) Carbon::parse($last->entry_date)->startOfDay()->diffInDays($today->copy()->startOfDay());
            if (! in_array($days, [14, 30], true) && ! ($days > 30 && $days % 30 === 0)) {
                continue;
            }
            $ours = $last->type === PartyLedgerEntry::TYPE_PAYMENT; // biz verdik: iş/mal onda
            $open = \App\Services\Whatsapp\AdvanceOffset::open($party, $ours ? \App\Services\Whatsapp\ExpenseExtractor::KIND_PURCHASE : \App\Services\Whatsapp\ExpenseExtractor::KIND_SALE);
            if ($open <= 0.01) {
                continue; // karşılığı gelmiş (alış/satış avanstan düşmüş)
            }
            $date = Carbon::parse($last->entry_date)->locale('tr')->translatedFormat('j F');
            $lines[] = $ours
                ? '⏳ ' . $party->name . ': ' . $date . "'de önceden verdiğiniz " . Money::format($open) . ' ₺\'nin işi/malı geldi mi? Geldiyse yazmanız yeterli (ör. "' . $party->name . ' işi bitirdi").'
                : '⏳ ' . $party->name . ': ' . $date . "'de aldığınız " . Money::format($open) . ' ₺ peşin paranın malı/işi teslim edildi mi? Edildiyse yazmanız yeterli (ör. "' . $party->name . "'e teslim ettim\").";
            if (count($lines) >= 3) {
                break;
            }
        }

        return $lines;
    }

    /** @return list<string> */
    private static function dueThisWeek(Carbon $monday): array
    {
        $end = $monday->copy()->addDays(6)->toDateString();
        $items = PartyLedgerEntry::with('party:id,name')->whereNotNull('due_date')
            ->whereBetween('due_date', [$monday->toDateString(), $end])->orderBy('due_date')->limit(5)->get()
            ->map(fn ($e) => ($e->party?->name ?? '—') . ' ' . Money::format((float) $e->amount) . ' (' . Carbon::parse($e->due_date)->format('d.m') . ')');
        if (config('modules.expenses')) {
            $items = $items->merge(Expense::whereIn('payment_status', ['unpaid', 'partial'])->whereNotNull('due_date')
                ->whereBetween('due_date', [$monday->toDateString(), $end])->orderBy('due_date')->limit(5)->get()
                ->map(fn ($e) => ($e->description ?: 'gider') . ' ' . Money::format((float) $e->amount) . ' (' . $e->due_date->format('d.m') . ')'));
        }

        return $items->take(6)->values()->all();
    }

    /** O şubenin kasasını en çok giren numara (son 30 gün WhatsApp kayıtları). */
    private static function tillOwner(int $projectId): ?string
    {
        $counts = WhatsappPendingExpense::where('status', 'confirmed')->where('updated_at', '>=', now()->subDays(30))
            ->latest('id')->limit(300)->get()
            ->filter(fn ($d) => ($d->extracted['kind'] ?? null) === 'trade_sale' && (int) ($d->extracted['project_id'] ?? 0) === $projectId)
            ->countBy(fn ($d) => Phone::normalize($d->phone));

        return $counts->isEmpty() ? null : (string) $counts->sortDesc()->keys()->first();
    }

    /** @return list<string> hatırlatma alan aktif numaralar */
    public static function recipients(): array
    {
        $firm = Tenancy::current();
        if (! $firm) {
            return array_values(array_map(fn ($p) => Phone::normalize($p), (array) config('services.whatsapp.reminder_phones', [])));
        }

        return HubPhone::where('hub_firm_id', $firm->id)->where('is_active', true)->where('receives_reminders', true)
            ->pluck('phone')->map(fn ($p) => Phone::normalize($p))->values()->all();
    }
}
