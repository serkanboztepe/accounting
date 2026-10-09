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
        $deliveries = config('modules.contracts')
            ? (float) ContractDelivery::query()
                ->whereBetween('delivery_date', [$from, $to])
                ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
                ->sum('amount')
            : 0.0;

        $purchases = (float) PartyLedgerEntry::query()
            ->where('type', PartyLedgerEntry::TYPE_PURCHASE)
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
            $lines[] = '🧾 Ayrıca cari alışların: ' . Money::format($s['purchases']) . ' ₺ (gidere dahil değil)';
        }
        if ($s['total'] < 0.01 && $s['purchases'] < 0.01) {
            $lines = ['📊 ' . ($project ? $project->name . ' — ' : '') . $label . ' kayıtlı giderin yok.'];
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
