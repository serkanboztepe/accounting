<?php

namespace App\Support;

use App\Models\ContractDelivery;
use App\Models\Expense;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * WhatsApp "bu ay ne kadar giderim var?" cevabı. Rapor sayfasıyla aynı hesap:
 * maliyet = direkt giderler + sözleşme teslimatları (ayrı satırlarda). Cari "Alış / Hizmet"
 * hareketleri maliyet DEĞİLDİR — toplama girmez, varsa ayrı bilgi satırında gösterilir
 * (panelden alışı cari hareketi olarak giren esnaf rakamı eksik sanmasın).
 */
class ExpenseSummary
{
    public const TIMEZONE = 'Europe/Istanbul';

    private const MONTHS = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

    /**
     * @return array{from:string,to:string,expenses:float,count:int,categories:array<string,float>,
     *               unpaid:float,deliveries:float,purchases:float,total:float}
     */
    public static function build(?string $from, ?string $to, ?int $projectId = null): array
    {
        [$from, $to] = self::period($from, $to);

        $expenses = Expense::query()
            ->whereBetween('expense_date', [$from, $to])
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->with('category')
            ->get();

        $categories = $expenses
            ->groupBy(fn (Expense $e) => $e->category?->name ?? 'Kategorisiz')
            ->map(fn ($rows) => (float) $rows->sum('amount'))
            ->sortDesc()
            ->all();

        // Rapor sayfasındaki "Teslimat Maliyeti" ile aynı kaynak (contract_deliveries.project_id).
        $deliveries = \App\Support\Features::contracts()
            ? (float) ContractDelivery::query()
                ->whereBetween('delivery_date', [$from, $to])
                ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
                ->sum('amount')
            : 0.0;

        // WhatsApp "Mehmet'e 15 bin borcum var" de alış satırıyla tutulur ama alış değil, borç kaydıdır
        // (açıklaması "Borç kaydı" ile başlar — WhatsappWebhookController::commitLedger).
        $purchases = (float) PartyLedgerEntry::query()
            ->where('type', PartyLedgerEntry::TYPE_PURCHASE)
            ->where(fn ($q) => $q->whereNull('description')->orWhere('description', 'not like', 'Borç kaydı%'))
            ->whereBetween('entry_date', [$from, $to])
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->sum('amount')
            - (float) PartyLedgerEntry::query()
                ->where('type', PartyLedgerEntry::TYPE_PURCHASE_RETURN)
                ->whereBetween('entry_date', [$from, $to])
                ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
                ->sum('amount');

        $expenseTotal = (float) $expenses->sum('amount');

        return [
            'from' => $from,
            'to' => $to,
            'expenses' => $expenseTotal,
            'count' => $expenses->count(),
            'categories' => $categories,
            'unpaid' => (float) $expenses->whereIn('payment_status', ['unpaid', 'partial'])->sum('amount'),
            'deliveries' => $deliveries,
            'purchases' => $purchases,
            'total' => $expenseTotal + $deliveries,
        ];
    }

    public static function text(?string $from, ?string $to, ?int $projectId = null): string
    {
        // Müteahhitte proje sorusu = proje maliyeti, Proje Raporu ile AYNI (gider + cari alış [+ teslimat]); dönem yoksa
        // projenin başından bugüne (firma 7, 20.10: "Fatihin maliyeti" → bu ayın gideri + "alışlar gidere dahil değil",
        // hemen ardından "Fatih projesinin maliyeti" → 8.800.000 — aynı soruya iki rakam).
        if ($projectId && config('app.profile') === 'muteahhit' && ($project = Project::find($projectId))) {
            return self::projectCostText($project, $from, $to);
        }
        // Müteahhitte projesiz soru ("toplam maliyetim ne", "ne kadar harcadım") da aynı mantık: gider + cari alış, dönem yoksa
        // şu ana kadar, proje proje döküm — Ana sayfadaki "Toplam maliyet" ile aynı (kullanıcı 20.10: "müteahhitte günlük bir şey yok").
        if (! $projectId && config('app.profile') === 'muteahhit' && \App\Support\Features::places()) {
            return self::firmCostText($from, $to);
        }
        $s = self::build($from, $to, $projectId);
        $project = $projectId ? Project::find($projectId) : null;
        $label = self::periodLabel($s['from'], $s['to']);

        $lines = ['📊 *' . ($project ? $project->name . ' — ' : '') . $label . ' giderin: ' . Money::format($s['total']) . ' ₺*'];

        if ($s['deliveries'] >= 0.01) {
            // Müteahhit: iki kaynak ayrı ayrı, toplamı rapor sayfasıyla aynı.
            $lines[] = '• Giderler: ' . Money::format($s['expenses']) . ' ₺ (' . $s['count'] . ' kayıt)';
            $lines[] = '• Sözleşme teslimatları: ' . Money::format($s['deliveries']) . ' ₺';
            if ($s['categories'] !== []) {
                $lines[] = '';
                $lines[] = 'Giderlerin dağılımı:';
            }
        } elseif ($s['count'] > 0) {
            $lines[0] .= ' (' . $s['count'] . ' kayıt)';
        }

        $shown = array_slice($s['categories'], 0, 6, true);
        foreach ($shown as $name => $amount) {
            $lines[] = '• ' . $name . ': ' . Money::format($amount);
        }
        if (count($s['categories']) > count($shown)) {
            $rest = array_sum(array_slice($s['categories'], count($shown)));
            $lines[] = '• Diğer: ' . Money::format($rest);
        }

        if ($s['unpaid'] >= 0.01) {
            $lines[] = 'Ödemesi tamamlanmamış: ' . Money::format($s['unpaid']) . ' ₺';
        }
        if ($s['purchases'] >= 0.01) {
            $lines[] = '🧾 Ayrıca alışların: ' . Money::format($s['purchases']) . ' ₺ (gidere dahil değil)';
        }
        if ($s['total'] < 0.01 && $s['purchases'] < 0.01) {
            $lines = ['📊 ' . ($project ? $project->name . ' — ' : '') . $label . ' kayıtlı giderin yok.'];
        }

        return implode("\n", $lines);
    }

    private static function projectCostText(Project $project, ?string $from, ?string $to): string
    {
        if ($from === null && $to === null) {
            $expenses = ProjectCost::expenses($project->id);
            $purchases = ProjectCost::purchases($project->id);
            $deliveries = \App\Support\Features::contracts() ? (float) ContractDelivery::where('project_id', $project->id)->sum('amount') : 0.0;
            $label = 'şu ana kadar';
        } else {
            $s = self::build($from, $to, $project->id);
            [$expenses, $purchases, $deliveries] = [$s['expenses'], $s['purchases'], $s['deliveries']];
            $label = self::periodLabel($s['from'], $s['to']);
        }
        $total = $expenses + $purchases + $deliveries;
        if ($total < 0.01) {
            return '📊 ' . $project->name . ' — ' . $label . ' maliyet kaydı yok.';
        }

        return implode("\n", array_filter([
            '📊 *' . $project->name . ' maliyeti (' . $label . '): ' . Money::format($total) . ' ₺*',
            $expenses >= 0.01 ? '• Gider: ' . Money::format($expenses) . ' ₺' : null,
            $purchases >= 0.01 ? '• Alışlar (iş / malzeme): ' . Money::format($purchases) . ' ₺' : null,
            $deliveries >= 0.01 ? '• Sözleşme teslimatı: ' . Money::format($deliveries) . ' ₺' : null,
        ]));
    }

    private static function firmCostText(?string $from, ?string $to): string
    {
        $all = $from === null && $to === null;
        $label = $all ? 'şu ana kadar' : self::periodLabel(...self::period($from, $to));
        $cost = function (?int $projectId) use ($all, $from, $to): float {
            if ($all) {
                return $projectId === null ? ProjectCost::expenses() + ProjectCost::purchases() : ProjectCost::expenses($projectId) + ProjectCost::purchases($projectId);
            }
            $s = self::build($from, $to, $projectId);

            return $s['expenses'] + $s['purchases'] + $s['deliveries'];
        };
        $total = $cost(null) + ($all && \App\Support\Features::contracts() ? (float) ContractDelivery::sum('amount') : 0.0);
        if ($total < 0.01) {
            return '📊 ' . ucfirst($label) . ' maliyet kaydı yok.';
        }
        $lines = ['📊 *Toplam maliyet (' . $label . '): ' . Money::format($total) . ' ₺*'];
        $sum = 0.0;
        foreach (Project::orderBy('id')->get(['id', 'name']) as $p) {
            $c = $cost($p->id) + ($all && \App\Support\Features::contracts() ? (float) ContractDelivery::where('project_id', $p->id)->sum('amount') : 0.0);
            if ($c >= 0.01) {
                $lines[] = '• ' . $p->name . ': ' . Money::format($c) . ' ₺';
                $sum += $c;
            }
        }
        if ($total - $sum >= 0.01) {
            $lines[] = '• Genel (projesiz): ' . Money::format($total - $sum) . ' ₺';
        }

        return implode("\n", $lines);
    }

    /** Dönem verilmediyse bu ay (ayın 1'i → bugün); tek uç verildiyse diğeri tamamlanır. */
    private static function period(?string $from, ?string $to): array
    {
        $today = Carbon::now(self::TIMEZONE)->startOfDay();
        $from = $from ? Carbon::parse($from) : ($to ? Carbon::parse($to)->startOfMonth() : $today->copy()->startOfMonth());
        $to = $to ? Carbon::parse($to) : $today;

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->toDateString(), $to->toDateString()];
    }

    /** "Ekim" / "Eylül 2025" / "2026" / "01.09 – 15.09.2026". */
    private static function periodLabel(string $from, string $to): string
    {
        $f = Carbon::parse($from);
        $t = Carbon::parse($to);
        $today = Carbon::now(self::TIMEZONE);
        $year = fn (CarbonInterface $d) => $d->year === $today->year ? '' : ' ' . $d->year;

        if ($f->day === 1 && $f->isSameMonth($t) && ($t->isLastOfMonth() || $t->isSameDay($today))) {
            return self::MONTHS[$f->month] . $year($f);
        }
        if ($f->dayOfYear === 1 && $f->isSameYear($t) && ($t->format('m-d') === '12-31' || $t->isSameDay($today))) {
            return (string) $f->year;
        }

        return $f->format($f->isSameYear($t) ? 'd.m' : 'd.m.Y') . ' – ' . $t->format('d.m.Y') . ' arası';
    }
}
