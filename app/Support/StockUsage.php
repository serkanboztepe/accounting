<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Reminder;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Otomatik stoktan düş (ürün kartındaki anahtar). Günlük kullanım GERÇEK stok çıkışı ("Kullanım")
 * olarak yazılır — ayrı bir "tahmini stok" tutulmaz, mevcut stok ve stok raporu tek kaynak kalır.
 */
class StockUsage
{
    /**
     * Bugüne kadar düşülmemiş her gün için kullanım çıkışı yazar (sunucu bir sabah kaçırırsa sonraki
     * çalışmada telafi eder). Tekrar çalışınca aynı günü iki kez düşmez; stok sıfırın altına inmez.
     *
     * @return list<array{product: string, quantity: float, days: int}>
     */
    public static function book(?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::now(Reminder::TIMEZONE))->copy()->startOfDay();
        $booked = [];

        $ids = Product::where('type', Product::TYPE_PRODUCT)->where('is_active', true)
            ->where('auto_usage', true)->where('daily_usage', '>', 0)->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $today, &$booked) {
                $product = Product::lockForUpdate()->find($id);
                // "3 günde 1": düşülen son günden N gün sonra bir kez (N = 1 → her gün, eskisi gibi).
                $every = $product->usageEveryDays();
                $from = $product->usage_booked_through
                    ? Carbon::parse($product->usage_booked_through->toDateString(), Reminder::TIMEZONE)->addDays($every)
                    : $today->copy();
                $last = null;

                $stock = $product->currentStock();
                $total = 0.0;
                $days = 0;

                for ($day = $from; $day->lte($today); $day->addDays($every)) {
                    $last = $day->copy();
                    $qty = min((float) $product->daily_usage, max(0.0, $stock));
                    if ($qty > 0) {
                        StockMovement::create([
                            'product_id'    => $product->id,
                            'direction'     => StockMovement::DIRECTION_OUT,
                            'reason'        => StockMovement::REASON_USAGE,
                            'quantity'      => $qty,
                            'movement_date' => $day->toDateString(),
                            'notes'         => 'Otomatik günlük kullanım',
                        ]);
                        $stock -= $qty;
                        $total += $qty;
                        $days++;
                    }
                }

                // Aralıklıda yalnız işlenen güne kadar ilerler (sıradaki düşüş o günden N gün sonra).
                if ($every === 1) {
                    $product->usage_booked_through = $today->toDateString();
                } elseif ($last !== null) {
                    $product->usage_booked_through = $last->toDateString();
                }
                $product->saveQuietly();

                if ($total > 0) {
                    $booked[] = ['product' => $product->name, 'quantity' => $total, 'days' => $days];
                }
            });
        }

        return $booked;
    }

    /**
     * Eşiğe inmiş ve henüz haber verilmemiş ürünler. Eşiğin üstüne çıkan (mal girişi yapılan)
     * ürünlerin işareti sıfırlanır, bir sonraki inişte yine haber verilir.
     *
     * @return Collection<int, array{product: Product, stock: float, days: ?int, supplier: ?string}>
     */
    public static function newlyLow(bool $write = true): Collection
    {
        $products = Product::where('type', Product::TYPE_PRODUCT)->where('is_active', true)
            ->whereNotNull('reorder_level')->with('unit')->get();

        $low = collect();
        foreach ($products as $product) {
            $stock = $product->currentStock();

            if (! $product->isLowStock($stock)) {
                if ($write && $product->low_stock_alerted_at) {
                    $product->forceFill(['low_stock_alerted_at' => null])->saveQuietly();
                }

                continue;
            }

            if ($product->low_stock_alerted_at === null) {
                $low->push([
                    'product'  => $product,
                    'stock'    => $stock,
                    'days'     => $product->daysLeft($stock),
                    'supplier' => self::lastSupplier($product),
                ]);
            }
        }

        return $low;
    }

    /** "Un: 6 çuval kaldı (~2 gün yeter) — son alış: Ak Un" */
    public static function line(array $item): string
    {
        $product = $item['product'];
        $unit = $product->unit?->name;
        $text = $product->name . ': ' . self::quantity($item['stock']) . ($unit ? " {$unit}" : '') . ' kaldı';

        if ($item['days'] !== null) {
            $text .= $item['days'] === 0 ? ' (bugün biter)' : " (~{$item['days']} gün yeter)";
        }
        if ($item['supplier']) {
            $text .= ' — son alış: ' . $item['supplier'];
        }

        return $text;
    }

    public static function quantity(float $value): string
    {
        return fmod($value, 1.0) === 0.0 ? (string) (int) $value : Money::format($value);
    }

    private static function lastSupplier(Product $product): ?string
    {
        return $product->stockMovements()
            ->where('direction', StockMovement::DIRECTION_IN)
            ->where('reason', StockMovement::REASON_PURCHASE)
            ->whereNotNull('party_id')
            ->latest('movement_date')->latest('id')
            ->first()?->party?->name;
    }
}
