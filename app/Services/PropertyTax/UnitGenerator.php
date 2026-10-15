<?php

namespace App\Services\PropertyTax;

use App\Models\PropertyTaxBlock;

/**
 * Toplu daire oluşturma (Emlak Beyanı): mesken katları + (varsa) zemin dükkan.
 * v1 daire listesi ("Toplu Daire Oluştur") ve v2 sihirbaz (④ Daireler) aynı kuralı kullanır.
 *
 * $data: residential_floors, per_floor, area, ground_shops, shop_count, shop_area, start_above, start_no
 */
class UnitGenerator
{
    /** @return int oluşturulan daire/dükkan sayısı */
    public static function create(PropertyTaxBlock $block, array $data): int
    {
        $residentialFloors = max(0, (int) ($data['residential_floors'] ?? 0));
        $perFloor = max(1, (int) ($data['per_floor'] ?? 1));
        $groundShops = (bool) ($data['ground_shops'] ?? false);
        $shopCount = $groundShops ? max(0, (int) ($data['shop_count'] ?? 0)) : 0;
        $no = (int) ($data['start_no'] ?? 1);
        $area = ($data['area'] ?? '') !== '' ? (float) $data['area'] : null;
        $shopArea = ($data['shop_area'] ?? '') !== '' ? (float) $data['shop_area'] : null;
        $sort = (int) ($block->units()->max('sort_order') ?? 0);

        $created = 0;
        // Ortak üretici — sıra: unit_no + sort_order artan.
        $makeUnits = function (int $floor, int $count, ?string $usage, ?float $unitArea) use (&$no, &$sort, &$created, $block): void {
            for ($pos = 1; $pos <= $count; $pos++) {
                $block->units()->create([
                    'unit_no'        => (string) $no,
                    'floor_no'       => $floor,
                    'floor_position' => $pos,
                    'area'           => $unitArea,
                    'usage_type'     => $usage,
                    'sort_order'     => ++$sort,
                ]);
                $no++;
                $created++;
            }
        };

        if ($groundShops) {
            // Meskenler 1.KAT'tan yukarı (floor 1..N), dükkanlar zemin (floor 0).
            $meskenFloors = $residentialFloors > 0 ? range(1, $residentialFloors) : [];
            $startAbove = (bool) ($data['start_above'] ?? true);

            if ($startAbove) {
                // Evet: meskenler önce (1.KAT'tan yukarı 1,2…), DÜKKANLAR EN SON.
                foreach ($meskenFloors as $floor) {
                    $makeUnits($floor, $perFloor, null, $area);
                }
                $makeUnits(0, $shopCount, 'DÜKKAN', $shopArea);
            } else {
                // Hayır: alttan başla → zemin (dükkan) önce, sonra meskenler yukarı.
                $makeUnits(0, $shopCount, 'DÜKKAN', $shopArea);
                foreach ($meskenFloors as $floor) {
                    $makeUnits($floor, $perFloor, null, $area);
                }
            }
        } else {
            // Zemin dükkan değil → zemin de mesken (floor 0..N-1), standart ALTTAN başlar.
            $meskenFloors = $residentialFloors > 0 ? range(0, $residentialFloors - 1) : [];
            foreach ($meskenFloors as $floor) {
                $makeUnits($floor, $perFloor, null, $area);
            }
        }

        return $created;
    }
}
