<?php

namespace App\Services\PropertyTax;

use App\Models\PropertyTaxBlock;

/**
 * Toplu daire oluşturma (Emlak Beyanı): mesken katları + (varsa) zemin dükkan.
 * v1 daire listesi ("Toplu Daire Oluştur"), v2 sihirbaz (④ Daireler) ve Hisse Dağılımı'ndan aktarım
 * (LandShareImporter — numaralar Hisse'den, yerleşim buradan) aynı kuralı kullanır.
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
        $no = (int) ($data['start_no'] ?? 1);
        $sort = (int) ($block->units()->max('sort_order') ?? 0);

        $created = 0;
        foreach (self::slots($data, $residentialFloors * $perFloor) as $slot) {
            $block->units()->create($slot + ['unit_no' => (string) $no++, 'sort_order' => ++$sort]);
            $created++;
        }

        return $created;
    }

    /**
     * Numara sırasıyla yerleşim: [floor_no, floor_position, area, usage_type] listesi.
     * $meskenCount mesken katta $per_floor'luk dizilir (son kat eksik kalabilir — kroki o katı yayar).
     *
     * @return list<array{floor_no:int,floor_position:int,area:?float,usage_type:?string}>
     */
    public static function slots(array $data, int $meskenCount): array
    {
        $perFloor = max(1, (int) ($data['per_floor'] ?? 1));
        $groundShops = (bool) ($data['ground_shops'] ?? false);
        $shopCount = $groundShops ? max(0, (int) ($data['shop_count'] ?? 0)) : 0;
        $area = ($data['area'] ?? '') !== '' && ($data['area'] ?? null) !== null ? (float) $data['area'] : null;
        $shopArea = ($data['shop_area'] ?? '') !== '' && ($data['shop_area'] ?? null) !== null ? (float) $data['shop_area'] : null;

        // Zemin dükkan → meskenler 1.KAT'tan yukarı; değilse zemin de mesken (alttan).
        $firstMeskenFloor = $groundShops ? 1 : 0;
        $mesken = [];
        for ($i = 0; $i < max(0, $meskenCount); $i++) {
            $mesken[] = [
                'floor_no'       => $firstMeskenFloor + intdiv($i, $perFloor),
                'floor_position' => $i % $perFloor + 1,
                'area'           => $area,
                'usage_type'     => null, // bloktan (MESKEN)
            ];
        }
        $shops = [];
        for ($pos = 1; $pos <= $shopCount; $pos++) {
            $shops[] = ['floor_no' => 0, 'floor_position' => $pos, 'area' => $shopArea, 'usage_type' => 'DÜKKAN'];
        }

        if (! $groundShops) {
            return $mesken;
        }

        // Evet: meskenler önce (1.KAT'tan 1,2…), DÜKKANLAR EN SON. Hayır: alttan (dükkan önce).
        return (bool) ($data['start_above'] ?? true) ? [...$mesken, ...$shops] : [...$shops, ...$mesken];
    }
}
