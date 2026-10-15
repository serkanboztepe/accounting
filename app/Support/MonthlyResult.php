<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\PartyLedgerEntry;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;

/**
 * Ay Sonucu (yaklaşık) = ciro − alışlar − giderler. Ödeme ve tahsilat SAYILMAZ (para hareketi, kazanç değil).
 * Her kaynak yalnız bir kez sayılır:
 *   ciro   = satış belgeleri (Satışlar + caride kalemli satış) + belgesiz basit "Satış Yaptım" − iadeler
 *   alış   = alış belgeleri (Alışlar) + belgesiz basit "Alış Yaptım" + belgesiz Mal Girişi (tutarlı olanlar)
 *   gider  = Giderler (modül açıksa)
 * Yaklaşık: ay sonunda stokta duran malın değeri düşülmez (alınan her şey o ayın alışı sayılır).
 */
class MonthlyResult
{
    public static function forMonth(Carbon $month): array
    {
        return ['month' => $month->copy()->startOfMonth()]
            + self::forRange($month->copy()->startOfMonth(), $month->copy()->endOfMonth());
    }

    /**
     * Herhangi bir aralık (gün, birkaç gün, ay) — WhatsApp "dünkü ciro", "14 Ekim", "iki günlük" (Akkuş: 17 kez ay sonucu
     * gelmişti). Hesap ay sonucuyla aynı; başlık aralığa göre.
     */
    public static function forRange(Carbon $fromDate, Carbon $toDate): array
    {
        $from = $fromDate->toDateString();
        $to = $toDate->toDateString();

        $salesDocs = (float) Sale::whereBetween('sale_date', [$from, $to])->sum('total_amount');
        $cash = (float) Sale::whereNull('party_id')->whereBetween('sale_date', [$from, $to])->sum('cash_amount');
        $bank = (float) Sale::whereNull('party_id')->whereBetween('sale_date', [$from, $to])->sum('bank_amount');
        $salesLedger = (float) PartyLedgerEntry::where('type', PartyLedgerEntry::TYPE_SALE)->whereNull('sale_id')
            ->whereBetween('entry_date', [$from, $to])->sum('amount');
        $returns = (float) SaleReturn::whereBetween('return_date', [$from, $to])->sum('total_amount');
        $revenue = $salesDocs + $salesLedger - $returns;

        $purchaseDocs = (float) Purchase::whereBetween('purchase_date', [$from, $to])->sum('total_amount');
        $purchaseLedger = (float) PartyLedgerEntry::where('type', PartyLedgerEntry::TYPE_PURCHASE)->whereNull('purchase_id')
            ->whereBetween('entry_date', [$from, $to])->sum('amount');
        $goodsIn = StockMovement::where('reason', StockMovement::REASON_PURCHASE)->whereNull('purchase_id')
            ->whereBetween('movement_date', [$from, $to]);
        $goodsInAmount = (float) (clone $goodsIn)->sum('amount');
        $unpriced = (clone $goodsIn)->whereNull('amount')->count();
        $purchases = $purchaseDocs + $purchaseLedger + $goodsInAmount;

        $expenses = config('modules.expenses')
            ? (float) Expense::whereBetween('expense_date', [$from, $to])->sum('amount')
            : 0.0;

        return [
            'from'            => $fromDate->copy()->startOfDay(),
            'to'              => $toDate->copy()->startOfDay(),
            'label'           => self::rangeLabel($fromDate, $toDate),
            'revenue'         => $revenue,
            'sales_docs'      => $salesDocs,
            'sales_ledger'    => $salesLedger,
            'returns'         => $returns,
            'cash'            => $cash,
            'bank'            => $bank,
            'purchases'       => $purchases,
            'purchase_docs'   => $purchaseDocs,
            'purchase_ledger' => $purchaseLedger,
            'goods_in'        => $goodsInAmount,
            'unpriced_goods'  => $unpriced,
            'expenses'        => $expenses,
            'result'          => $revenue - $purchases - $expenses,
        ];
    }

    /** "Ekim 2026" / "14 Ekim" / "13–14 Ekim" / "28 Eylül – 2 Ekim". */
    public static function rangeLabel(Carbon $from, Carbon $to): string
    {
        $f = $from->copy()->locale('tr');
        $t = $to->copy()->locale('tr');
        if ($f->isSameDay($f->copy()->startOfMonth()) && $t->isSameDay($f->copy()->endOfMonth())) {
            return $f->translatedFormat('F Y');
        }
        if ($f->isSameDay($t)) {
            return $f->translatedFormat('j F');
        }

        return $f->isSameMonth($t)
            ? $f->format('j') . '–' . $t->translatedFormat('j F')
            : $f->translatedFormat('j F') . ' – ' . $t->translatedFormat('j F');
    }

    /** Son N ay (en yeni üstte). */
    public static function lastMonths(int $count = 12, ?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->startOfMonth();

        return collect(range(0, $count - 1))
            ->map(fn (int $i) => self::forMonth($now->copy()->subMonthsNoOverflow($i)))
            ->all();
    }

    /** WhatsApp "bu ay ne kazandım" cevabı. */
    public static function whatsappText(array $r): string
    {
        $lines = [
            '📊 *' . $r['label'] . ' sonucu' . (str_contains($r['label'], ' 20') ? ' (yaklaşık)' : '') . '*',
            'Ciro: ' . Money::format($r['revenue']) . ' ₺'
                . ($r['cash'] + $r['bank'] > 0 ? ' (peşin nakit ' . Money::format($r['cash']) . ', kart ' . Money::format($r['bank']) . ')' : ''),
            'Alış: ' . Money::format($r['purchases']) . ' ₺',
        ];
        if (config('modules.expenses')) {
            $lines[] = 'Gider: ' . Money::format($r['expenses']) . ' ₺';
        }
        $lines[] = ($r['result'] >= 0 ? '✅ Kalan: ' : '🔻 Eksi: ') . Money::format(abs($r['result'])) . ' ₺';
        $lines[] = '_Ödeme ve tahsilatlar sayılmaz; stokta kalan mal düşülmez._';

        return implode("\n", $lines);
    }
}
