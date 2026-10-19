<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\PartyLedgerEntry;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;

/**
 * Ay Sonucu (yaklaşık) = ciro − alışlar − giderler. Ödeme ve tahsilat SAYILMAZ (para hareketi, kazanç değil).
 * Her kaynak yalnız bir kez sayılır:
 *   ciro   = satış belgeleri (Satışlar + caride kalemli satış) + belgesiz basit "Satış Yaptım" − iadeler
 *   alış   = alış belgeleri (Alışlar) + belgesiz basit "Alış Yaptım" + belgesiz Mal Girişi (tutarlı olanlar) − alış iadeleri
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
    public static function forRange(Carbon $fromDate, Carbon $toDate, ?int $projectId = null): array
    {
        if (! $projectId) {
            return self::compute($fromDate, $toDate, null);
        }
        // Şube sonucu (kullanıcı kararı 16.10): şubenin kendi ciro/alış/gideri + GENEL maliyetin (şubesiz alış, gider,
        // personel — un/maya, ortak kira) ciro payı kadarı (tahmini). Yoksa genel maliyet hiçbir şubeye düşmez, şube
        // sonuçları olduğundan kârlı görünür. İşletme toplamı değişmez (paylar toplamı = genel maliyet).
        $branch = self::compute($fromDate, $toDate, $projectId);
        $all = self::compute($fromDate, $toDate, null);
        $general = self::compute($fromDate, $toDate, 'none');
        // Tek gün: aylık maaş günün sonucuna (ve şube payına) yazılmaz — bilgi satırı (kullanıcı kararı 19.10).
        $generalCost = $general['purchases'] + ($fromDate->isSameDay($toDate) ? 0.0 : $general['personnel']) + $general['expenses'];
        $share = $all['revenue'] > 0 ? max(0.0, $branch['revenue'] / $all['revenue']) : 0.0;
        $allocated = round($generalCost * $share, 2);

        return array_merge($branch, [
            'general_cost' => $generalCost,
            'share'        => $share,
            'allocated'    => $allocated,
            'result'       => $branch['result'] - $allocated,
        ]);
    }

    /** @param  int|'none'|null  $scope  null = hepsi, int = o şube, 'none' = şubesiz (genel) kayıtlar */
    private static function compute(Carbon $fromDate, Carbon $toDate, int|string|null $scope): array
    {
        $from = $fromDate->toDateString();
        $to = $toDate->toDateString();
        $projectId = is_int($scope) ? $scope : null;
        // Şube / proje süzgeci (Akkuş "dünkü Ergenekon ciro"): her kaynakta project_id.
        $p = fn ($q) => match (true) {
            is_int($scope) => $q->where('project_id', $scope),
            $scope === 'none' => $q->whereNull('project_id'),
            default => $q,
        };

        $salesDocs = (float) $p(Sale::whereBetween('sale_date', [$from, $to]))->sum('total_amount');
        $cash = (float) $p(Sale::whereNull('party_id')->whereBetween('sale_date', [$from, $to]))->sum('cash_amount');
        $bank = (float) $p(Sale::whereNull('party_id')->whereBetween('sale_date', [$from, $to]))->sum('bank_amount');
        $salesLedger = (float) $p(PartyLedgerEntry::where('type', PartyLedgerEntry::TYPE_SALE)->whereNull('sale_id')
            ->whereBetween('entry_date', [$from, $to]))->sum('amount');
        // Belgesiz basit satışın iadesi (WhatsApp) başlıksız cari satırıdır; belgelinin cari aynası SaleReturn'de sayılı.
        $returns = (float) $p(SaleReturn::whereBetween('return_date', [$from, $to]))->sum('total_amount')
            + (float) $p(PartyLedgerEntry::where('type', PartyLedgerEntry::TYPE_SALE_RETURN)->whereNull('sale_return_id')
                ->whereBetween('entry_date', [$from, $to]))->sum('amount');
        $revenue = $salesDocs + $salesLedger - $returns;

        $purchaseDocs = (float) $p(Purchase::whereBetween('purchase_date', [$from, $to]))->sum('total_amount');
        // Personel carisinin hak edişleri (maaş) alış değil, ayrı "Personel" satırı — bir kez sayılır.
        $employees = \App\Models\Party::where('is_employee', true)->pluck('id');
        $ledgerIn = fn () => $p(PartyLedgerEntry::where('type', PartyLedgerEntry::TYPE_PURCHASE)->whereNull('purchase_id')
            ->whereBetween('entry_date', [$from, $to]));
        $purchaseLedger = (float) $ledgerIn()->whereNotIn('party_id', $employees)->sum('amount');
        $personnel = $employees->isEmpty() ? 0.0 : (float) $ledgerIn()->whereIn('party_id', $employees)->sum('amount');
        $goodsIn = $p(StockMovement::where('reason', StockMovement::REASON_PURCHASE)->whereNull('purchase_id')
            ->whereBetween('movement_date', [$from, $to]));
        $goodsInAmount = (float) (clone $goodsIn)->sum('amount');
        $unpriced = (clone $goodsIn)->whereNull('amount')->count();
        // Alış iadesi (tedarikçiye geri verilen) alıştan düşülür: belgeli = PurchaseReturn, belgesiz = başlıksız cari satırı.
        $purchaseReturns = (float) $p(PurchaseReturn::whereBetween('return_date', [$from, $to]))->sum('total_amount')
            + (float) $p(PartyLedgerEntry::where('type', PartyLedgerEntry::TYPE_PURCHASE_RETURN)->whereNull('purchase_return_id')
                ->whereBetween('entry_date', [$from, $to]))->sum('amount');
        $purchases = $purchaseDocs + $purchaseLedger + $goodsInAmount - $purchaseReturns;

        $expenses = config('modules.expenses')
            ? (float) $p(Expense::whereBetween('expense_date', [$from, $to]))->sum('amount')
            : 0.0;

        return [
            'from'            => $fromDate->copy()->startOfDay(),
            'to'              => $toDate->copy()->startOfDay(),
            'label'           => self::rangeLabel($fromDate, $toDate)
                . ($projectId && ($pn = \App\Models\Project::whereKey($projectId)->value('name')) ? ' · ' . $pn : ''),
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
            'purchase_returns' => $purchaseReturns,
            'unpriced_goods'  => $unpriced,
            'expenses'        => $expenses,
            'personnel'       => $personnel,
            // Tek günde maaş düşülmez (Akkuş 17.10: 60.750 ciroluk gün maaş yüzünden "eksi 2.400" göründü) — bilgi satırı.
            'result'          => $revenue - $purchases - ($fromDate->isSameDay($toDate) ? 0.0 : $personnel) - $expenses,
            'personnel_names' => $fromDate->isSameDay($toDate) && $personnel > 0
                ? \App\Models\Party::whereIn('id', (clone $ledgerIn())->whereIn('party_id', $employees)->pluck('party_id'))->pluck('name')->all() : [],
            // Satış girilen günler ("kaç günlük?" — Akkuş 18.10: 5 günlük ciroya "18 günlük" denmişti).
            'sale_days'       => collect()
                ->merge($p(Sale::whereBetween('sale_date', [$from, $to]))->pluck('sale_date')->map(fn ($x) => Carbon::parse($x)->toDateString()))
                ->merge($p(PartyLedgerEntry::where('type', PartyLedgerEntry::TYPE_SALE)->whereNull('sale_id')->whereBetween('entry_date', [$from, $to]))
                    ->pluck('entry_date')->map(fn ($x) => Carbon::parse($x)->toDateString()))
                ->unique()->sort()->values()->all(),
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
        $own = isset($r['allocated']) ? ' (' . mb_strtolower(Labels::project()) . 'nin)' : ''; // şube sonucunda: yalnız şubeye yazılanlar
        $lines = [
            '📊 *' . $r['label'] . ' sonucu' . (str_contains($r['label'], ' 20') ? ' (yaklaşık)' : '') . '*',
            'Ciro: ' . Money::format($r['revenue']) . ' ₺'
                . ($r['cash'] + $r['bank'] > 0 ? ' (peşin nakit ' . Money::format($r['cash']) . ', kart ' . Money::format($r['bank']) . ')' : ''),
            'Alış' . $own . ': ' . Money::format($r['purchases']) . ' ₺',
        ];
        $oneDay = $r['from']->isSameDay($r['to']);
        if (($r['personnel'] ?? 0) > 0 && ! $oneDay) {
            $lines[] = 'Personel' . $own . ': ' . Money::format($r['personnel']) . ' ₺';
        }
        if (config('modules.expenses')) {
            $lines[] = 'Gider' . $own . ': ' . Money::format($r['expenses']) . ' ₺';
        }
        if (isset($r['allocated']) && $r['general_cost'] > 0) {
            $lines[] = 'Genel maliyet payı (%' . Money::format($r['share'] * 100, 0) . ', tahmini): ' . Money::format($r['allocated']) . ' ₺';
        }
        $lines[] = ($r['result'] >= 0 ? '✅ Kalan: ' : '🔻 Eksi: ') . Money::format(abs($r['result'])) . ' ₺';
        if ($oneDay && ($r['personnel'] ?? 0) > 0) {
            $lines[] = '👷 Bugün maaş ödendi' . (($r['personnel_names'] ?? []) ? ' (' . implode(', ', $r['personnel_names']) . ')' : '') . ': '
                . Money::format($r['personnel']) . ' ₺ — aylık sonuçta düşülür';
        }
        $days = $r['sale_days'] ?? [];
        if (! $oneDay && $days !== []) {
            $first = Carbon::parse($days[0])->locale('tr');
            $last = Carbon::parse(end($days))->locale('tr');
            $lines[] = '📅 Satış girilen gün: ' . count($days) . ' (' . ($first->isSameDay($last) ? $first->translatedFormat('j F')
                : self::rangeLabel($first, $last)) . ')';
        }
        if (isset($r['allocated']) && $r['general_cost'] > 0) {
            $lines[] = '_Şubesiz alış/gider (' . Money::format($r['general_cost']) . ' ₺) ciro payına göre dağıtıldı._';
        }
        $lines[] = '_Ödeme ve tahsilatlar sayılmaz; stokta kalan mal düşülmez._';

        return implode("\n", $lines);
    }
}
