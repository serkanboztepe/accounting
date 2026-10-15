<?php

namespace App\Services\PropertyTax;

use App\Models\LandShareStudy;
use App\Models\PropertyTaxBlock;
use App\Models\PropertyTaxProject;
use Illuminate\Support\Facades\DB;

/**
 * Hisse Dağılımı çalışması → Emlak Beyanı projesi: hissedarlar mükellef, bloklar blok, BB'ler daire,
 * atamalar daire sahibi (pay/payda) olur. Aynı adlı mükellef/blok ve aynı numaralı daire varsa yeniden
 * açılmaz (tekrar aktarım güvenli); aktarılan dairelerin sahipleri çalışmadakiyle değiştirilir.
 */
class LandShareImporter
{
    /** Hisse Dağılımı BB türü → Emlak Beyanı kullanış şekli (null = bloktan, MESKEN). */
    private const USAGE = ['dukkan' => 'DÜKKAN', 'ofis' => 'OFİS', 'depo' => 'DEPO'];

    /** @return array{taxpayers:int,blocks:int,units:int,owners:int} yeni açılanlar + atanan sahip satırı */
    public static function import(PropertyTaxProject $project, LandShareStudy $study): array
    {
        return DB::transaction(function () use ($project, $study) {
            $counts = ['taxpayers' => 0, 'blocks' => 0, 'units' => 0, 'owners' => 0];

            // Hissedar → mükellef (ad eşleşmesi büyük/küçük harf duyarsız).
            $byName = $project->taxpayers()->get()->keyBy(fn ($t) => self::key($t->surname));
            $sort = (int) $project->taxpayers()->max('sort_order');
            $taxpayerOf = [];
            foreach ($study->shareholders()->orderBy('sort')->orderBy('id')->get() as $sh) {
                $t = $byName[self::key($sh->name)] ?? null;
                if (! $t) {
                    $t = $project->taxpayers()->create(['surname' => trim($sh->name), 'filer_role' => 'taxpayer', 'sort_order' => ++$sort]);
                    $byName[self::key($sh->name)] = $t;
                    $counts['taxpayers']++;
                }
                $taxpayerOf[$sh->id] = $t->id;
            }

            $blocks = $project->blocks()->get();
            foreach ($study->blocks()->with('sections.allocations')->orderBy('sort')->orderBy('id')->get() as $landBlock) {
                // "A" ↔ "A" / "A Blok" aynı blok sayılır.
                $block = $blocks->first(fn (PropertyTaxBlock $b) => in_array(self::key($b->name), [self::key($landBlock->name), self::key($landBlock->name . ' Blok')], true));
                if (! $block) {
                    $block = $project->blocks()->create([
                        'name' => $landBlock->name . ' Blok',
                        'construction_type' => 'B.ARME', 'construction_class' => '3.SINIF', 'usage_type' => 'MESKEN',
                        'share_ratio' => 'TAM', 'restriction_status' => 'YOK', 'exemption_status' => 'YOK',
                    ]);
                    $blocks->push($block);
                    $counts['blocks']++;
                }

                $sortOrder = (int) $block->units()->max('sort_order');
                foreach ($landBlock->sections->sortBy(fn ($s) => [(int) $s->sort, $s->id]) as $section) {
                    $unit = $block->units()->where('unit_no', (string) $section->bb_no)->first();
                    if (! $unit) {
                        $unit = $block->units()->create([
                            'unit_no' => (string) $section->bb_no,
                            'floor_no' => self::floor($section->floor),
                            'usage_type' => self::USAGE[$section->type] ?? null,
                            'sort_order' => ++$sortOrder,
                        ]);
                        $counts['units']++;
                    }
                    if ($section->arsa_pay && $section->arsa_payda) {
                        $unit->update(['land_share_numerator' => $section->arsa_pay, 'land_share_denominator' => $section->arsa_payda]);
                    }

                    if ($section->allocations->isNotEmpty()) {
                        $unit->allocations()->delete();
                        foreach ($section->allocations as $a) {
                            if (isset($taxpayerOf[$a->shareholder_id]) && $a->pay > 0) {
                                $unit->allocations()->create([
                                    'property_tax_taxpayer_id' => $taxpayerOf[$a->shareholder_id],
                                    'pay' => $a->pay, 'payda' => $a->payda,
                                ]);
                                $counts['owners']++;
                            }
                        }
                    }
                }
            }

            return $counts;
        });
    }

    private static function key(?string $name): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string) $name)), 'UTF-8');
    }

    /** "Zemin" / "0" → 0, "3" / "3. Kat" → 3; bilinmiyorsa boş. */
    private static function floor(?string $floor): ?int
    {
        if ($floor === null || trim($floor) === '') {
            return null;
        }
        if (preg_match('/zemin/iu', $floor)) {
            return 0;
        }

        return preg_match('/\d+/', $floor, $m) ? (int) $m[0] : null;
    }
}
