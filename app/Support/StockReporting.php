<?php

namespace App\Support;

use App\Models\Party;
use App\Models\Product;
use App\Models\Project;
use App\Models\StockMovement;

/**
 * Stok & satış özet raporları — tek kaynak (sayfa + çıktı + testler ona bağlanır).
 * Tümü yalnız stock_movements'tan türetilir; proje maliyetine dokunmaz.
 */
class StockReporting
{
    /** Ürün bazında Giren / Çıkan / Mevcut. */
    public static function stockRows(): array
    {
        $agg = StockMovement::selectRaw(
            "product_id,
             SUM(CASE WHEN direction = 'in' THEN quantity ELSE 0 END)  AS tin,
             SUM(CASE WHEN direction = 'out' THEN quantity ELSE 0 END) AS tout"
        )->groupBy('product_id')->get()->keyBy('product_id');

        return Product::query()
            ->where('type', Product::TYPE_PRODUCT)
            ->with('unit')
            ->orderBy('name')
            ->get()
            ->map(function (Product $p) use ($agg) {
                $in  = (float) ($agg[$p->id]->tin ?? 0);
                $out = (float) ($agg[$p->id]->tout ?? 0);

                return [
                    'id'      => $p->id,
                    'name'    => $p->name,
                    'unit'    => $p->unit?->code ?? $p->unit?->name ?? '',
                    'in'      => $in,
                    'out'     => $out,
                    'current' => $in - $out,
                ];
            })
            ->toArray();
    }

    /**
     * Satış özeti — cari veya proje bazında: brüt satış, iade, net (iade düşülmüş).
     * $groupBy: 'party' | 'project'
     */
    public static function salesSummary(string $groupBy = 'party'): array
    {
        $col = $groupBy === 'project' ? 'project_id' : 'party_id';

        $rows = StockMovement::query()
            ->whereIn('reason', [StockMovement::REASON_SALE, StockMovement::REASON_RETURN])
            ->selectRaw(
                "$col AS gid,
                 SUM(CASE WHEN reason = 'sale' THEN amount ELSE 0 END)   AS gross,
                 SUM(CASE WHEN reason = 'return' THEN amount ELSE 0 END) AS ret"
            )
            ->groupBy($col)
            ->get();

        $names = $groupBy === 'project'
            ? Project::pluck('name', 'id')
            : Party::pluck('name', 'id');

        return $rows->map(function ($r) use ($names) {
            $gross = (float) $r->gross;
            $ret   = (float) $r->ret;

            return [
                'id'    => $r->gid,
                'name'  => $r->gid ? ($names[$r->gid] ?? '—') : 'Belirtilmemiş',
                'gross' => $gross,
                'ret'   => $ret,
                'net'   => $gross - $ret,
            ];
        })
            ->sortByDesc('net')
            ->values()
            ->toArray();
    }
}
