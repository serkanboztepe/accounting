<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Reminder;
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
        // "Alışı yere yaz" (çiftlik, kullanıcı kararı 26.10): genel gider DAĞITILMAZ — ayrı bilgi satırı, sonuca dahil değil.
        if (Features::placeCost()) {
            return array_merge($branch, ['general_unallocated' => $generalCost]);
        }
        $share = $all['revenue'] > 0 ? max(0.0, $branch['revenue'] / $all['revenue']) : 0.0;
        $allocated = round($generalCost * $share, 2);

        return array_merge($branch, [
            'general_cost' => $generalCost,
            'share'        => $share,
            'allocated'    => $allocated,
            'result'       => $branch['result'] - $allocated,
        ]);
    }

    /**
     * Yerin SEZON sonucu ("Tarla 1 · 2026", çiftlik 26.10): yerin başından (başlangıç tarihi ya da yere yazılan ilk kayıt,
     * hangisi önceyse) bitişine (tamamlandıysa bitiş günü, yoksa bugün) kadar yalnız O YERE yazılanlar: satış − alış −
     * personel − gider. Yere yazılmamış genel gider dağıtılmaz, ayrı bilgi (kullanıcı kararı 26.10). Üretim miktarları
     * (WhatsApp "ürettim", maliyetsiz stok girişi) bilgi olarak eklenir.
     */
    public static function forPlace(Project $place): array
    {
        $from = self::placeStart($place);
        $to = $place->end_date ? Carbon::parse($place->end_date)->startOfDay() : now(Reminder::TIMEZONE)->startOfDay();
        if ($to->lt($from)) {
            $to = $from->copy();
        }
        $own = self::compute($from, $to, $place->id);
        $general = self::compute($from, $to, 'none');

        $production = StockMovement::query()
            ->where('reason', StockMovement::REASON_PRODUCTION)->where('project_id', $place->id)
            ->whereBetween('movement_date', [$from->toDateString(), $to->toDateString()])
            ->with('product.unit')->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => [
                'name' => $rows->first()->product?->name ?? 'Ürün',
                'quantity' => (float) $rows->sum('quantity'),
                'unit' => $rows->first()->product?->unit?->name,
            ])->values()->all();

        return array_merge($own, [
            'place'               => $place->name,
            'closed'              => $place->status === 'completed',
            'label'               => $place->name,
            'season_label'        => $from->copy()->locale('tr')->translatedFormat('j F Y') . ' – '
                . ($place->end_date ? $to->copy()->locale('tr')->translatedFormat('j F Y') : 'bugün'),
            'general_unallocated' => $general['purchases'] + $general['personnel'] + $general['expenses'],
            'production'          => $production,
        ]);
    }

    /** Sezon başı: başlangıç tarihi ya da yere yazılan ilk kayıt (sonradan açılıp geriye tarihli girilen alış dışarıda kalmasın). */
    private static function placeStart(Project $place): Carbon
    {
        $dates = array_filter([
            $place->start_date ? Carbon::parse($place->start_date)->toDateString() : null,
            Sale::where('project_id', $place->id)->min('sale_date'),
            Purchase::where('project_id', $place->id)->min('purchase_date'),
            Expense::where('project_id', $place->id)->min('expense_date'),
            PartyLedgerEntry::where('project_id', $place->id)->min('entry_date'),
            StockMovement::where('project_id', $place->id)->min('movement_date'),
        ]);
        $first = $dates ? min(array_map(fn ($d) => Carbon::parse($d)->toDateString(), $dates)) : null;

        return $first ? Carbon::parse($first)->startOfDay() : Carbon::parse($place->created_at ?? now())->startOfDay();
    }

    /** WhatsApp / panel: yerin sezon sonucu metni. */
    public static function placeText(array $r): string
    {
        $lines = [
            '🌾 *' . $r['place'] . ' — sezon sonucu*' . ($r['closed'] ? ' (kapandı)' : ''),
            '_' . $r['season_label'] . '_',
            'Satış: ' . Money::format($r['revenue']) . ' ₺',
            'Alış: ' . Money::format($r['purchases']) . ' ₺',
        ];
        if (($r['personnel'] ?? 0) > 0) {
            $lines[] = 'Personel: ' . Money::format($r['personnel']) . ' ₺';
        }
        if (Features::expenses()) {
            $lines[] = 'Gider: ' . Money::format($r['expenses']) . ' ₺';
        }
        $lines[] = ($r['result'] >= 0 ? '✅ Kalan: ' : '🔻 Eksi: ') . Money::format(abs($r['result'])) . ' ₺';
        if ($r['production'] !== []) {
            $lines[] = '🌱 Üretim: ' . implode(', ', array_map(
                fn (array $p) => StockUsage::quantity($p['quantity']) . ($p['unit'] ? ' ' . $p['unit'] : '') . ' ' . $p['name'],
                $r['production'],
            ));
        }
        if ($r['general_unallocated'] > 0) {
            $lines[] = 'Yere yazılmamış genel gider: ' . Money::format($r['general_unallocated']) . ' ₺ (bu sonuca dahil değil)';
        }
        $lines[] = '_Ödeme ve tahsilatlar sayılmaz; stokta kalan mal düşülmez._';

        return implode("\n", $lines);
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
        // Personelin maaş kesintisi (27.10, "Kesinti — …" = alış iadesi tipi) alış iadesi değil — personel maliyetinden düşer.
        $returnsLedger = fn () => $p(PartyLedgerEntry::where('type', PartyLedgerEntry::TYPE_PURCHASE_RETURN)->whereNull('purchase_return_id')
            ->whereBetween('entry_date', [$from, $to]));
        $purchaseReturns = (float) $p(PurchaseReturn::whereBetween('return_date', [$from, $to]))->sum('total_amount')
            + (float) $returnsLedger()->whereNotIn('party_id', $employees)->sum('amount');
        if ($employees->isNotEmpty()) {
            $personnel -= (float) $returnsLedger()->whereIn('party_id', $employees)->sum('amount');
        }
        $purchases = $purchaseDocs + $purchaseLedger + $goodsInAmount - $purchaseReturns;

        $expenses = \App\Support\Features::expenses()
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
            // Elden verilen maaş (EmployeeDraft "Maaş ödemesi — …" satırı): hak edişten farkı avanstan düşülen kısım.
            'personnel_paid'  => $personnel > 0 && $employees->isNotEmpty() ? (float) $p(PartyLedgerEntry::where('type', PartyLedgerEntry::TYPE_PAYMENT)
                ->whereIn('party_id', $employees)->whereBetween('entry_date', [$from, $to])
                ->where(fn ($q) => $q->where('description', 'like', 'Maaş ödemesi%')->orWhere('description', 'like', 'Yevmiye ödemesi%')))->sum('amount') : 0.0,
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
        if (\App\Support\Features::expenses()) {
            $lines[] = 'Gider' . $own . ': ' . Money::format($r['expenses']) . ' ₺';
        }
        if (isset($r['allocated']) && $r['general_cost'] > 0) {
            $lines[] = 'Genel maliyet payı (%' . Money::format($r['share'] * 100, 0) . ', tahmini): ' . Money::format($r['allocated']) . ' ₺';
        }
        $lines[] = ($r['result'] >= 0 ? '✅ Kalan: ' : '🔻 Eksi: ') . Money::format(abs($r['result'])) . ' ₺';
        if ($oneDay && ($r['personnel'] ?? 0) > 0) {
            // Avanstan düşülen kısım ayrı (Akkuş 19.10: "Bugün maaş ödendi: 55.000" — 5.500'ü avanstandı, elden 49.500).
            $paid = (float) ($r['personnel_paid'] ?? 0);
            $fromAdvance = round($r['personnel'] - $paid, 2);
            $lines[] = '👷 Bugün maaş ödendi' . (($r['personnel_names'] ?? []) ? ' (' . implode(', ', $r['personnel_names']) . ')' : '') . ': '
                . Money::format($r['personnel']) . ' ₺'
                . ($paid > 0 && $fromAdvance > 0.009 ? ' — ' . Money::format($fromAdvance) . ' ₺ avanstan, elden ' . Money::format($paid) . ' ₺' : '')
                . ' — aylık sonuçta düşülür';
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
        if (($r['general_unallocated'] ?? 0) > 0) {
            $lines[] = 'Yere yazılmamış genel gider: ' . Money::format($r['general_unallocated']) . ' ₺ (bu sonuca dahil değil)';
        }
        $lines[] = '_Ödeme ve tahsilatlar sayılmaz; stokta kalan mal düşülmez._';

        return implode("\n", $lines);
    }
}
