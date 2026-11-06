<?php

namespace Tests\Feature\PropertyTax;

use App\Filament\Resources\PropertyTaxProjects\Pages\DeclarationBuilder;
use App\Models\LandSection;
use App\Models\LandShareStudy;
use App\Models\Project;
use App\Models\PropertyTaxProject;
use App\Models\User;
use App\Services\PropertyTax\LandShareImporter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class DeclarationBuilderTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
    }

    private function project(): PropertyTaxProject
    {
        return PropertyTaxProject::create(['name' => 'Hocabey Apt', 'filing_reason' => 'first_acquisition', 'declaration_year' => '2026']);
    }

    public function test_page_renders_and_new_project_starts_at_step_one(): void
    {
        $p = $this->project();

        $this->get("/admin/property-tax-projects/{$p->id}/adim-adim")
            ->assertOk()->assertSee('Proje &amp; Konum', false)->assertSee('Mükellefler')->assertSee('Kontrol &amp; Çıktı', false);
    }

    public function test_steps_save_taxpayers_blocks_units_owners_and_checks(): void
    {
        $p = $this->project();
        $c = Livewire::test(DeclarationBuilder::class, ['record' => $p->id]);
        $i = $c->instance();

        // ② mükellefler
        $i->data['taxpayers'] = [
            ['id' => null, 'surname' => 'ALİ YILMAZ', 'tax_id' => '111', 'property_registry_no' => null, 'phone_area_code' => null, 'phone' => null],
            ['id' => null, 'surname' => 'VELİ KAYA', 'tax_id' => null, 'property_registry_no' => null, 'phone_area_code' => null, 'phone' => null],
        ];
        $i->saveTaxpayers();
        $this->assertSame(['ALİ YILMAZ', 'VELİ KAYA'], $p->taxpayers()->orderBy('sort_order')->pluck('surname')->all());

        // ③ blok
        $i->data['blocks'] = [['id' => null, 'name' => 'A Blok', 'construction_type' => 'B.ARME', 'construction_class' => '3.SINIF',
            'usage_type' => 'MESKEN', 'share_ratio' => 'TAM', 'has_heating' => true, 'has_elevator' => false]];
        $i->saveBlocks();
        $block = $p->blocks()->sole();
        $this->assertTrue($block->has_heating);

        // Tek apartman: adı boş blok kaydedilir (eskiden NOT NULL hatası veriyordu).
        $i->data['blocks'] = [...array_values($i->data['blocks']), ['id' => null, 'name' => null, 'construction_type' => 'B.ARME']];
        $i->saveBlocks();
        $this->assertSame(['A Blok', null], $p->blocks()->orderBy('id')->pluck('name')->all());
        $i->data['blocks'] = [array_values($i->data['blocks'])[0]];
        $i->saveBlocks();

        // ④ toplu daire: 2 kat × 2 = 4 daire, sonra listede birinin m²'si değişir
        $i->bulkCreateUnits($block->id, ['residential_floors' => 2, 'per_floor' => 2, 'area' => 100, 'start_no' => 1]);
        $this->assertSame(['1', '2', '3', '4'], $block->units()->orderBy('sort_order')->pluck('unit_no')->all());
        $rows = array_values($i->data['units']['b' . $block->id]);
        $rows[3]['area'] = 125;
        $i->data['units']['b' . $block->id] = $rows;
        $i->saveUnits();
        $u = $block->units()->orderBy('sort_order')->get();
        $this->assertEquals(125, (float) $u[3]->area);

        // ⑤ sahipler: Ali 1-2-3, Veli 3-4 → 3 ortak (1/2 + 1/2)
        [$ali, $veli] = $p->taxpayers()->orderBy('sort_order')->pluck('id')->all();
        $i->assignTaxpayerUnits($ali, ['block_' . $block->id => [$u[0]->id, $u[1]->id, $u[2]->id]]);
        $i->assignTaxpayerUnits($veli, ['block_' . $block->id => [$u[2]->id, $u[3]->id]]);
        $share = fn ($unit) => $unit->allocations()->orderBy('property_tax_taxpayer_id')->get()->map(fn ($a) => $a->pay . '/' . $a->payda)->implode(' ');
        $this->assertSame('1/1', $share($u[0]));
        $this->assertSame('1/2 1/2', $share($u[2]));
        $this->assertSame('1/1', $share($u[3]));

        // Özel hisse: toplam 1/1 değilse kaydetmez; 2/3 + 1/3 kaydeder.
        try {
            $i->saveCustomShare(['unit_id' => $u[2]->id, "pay_{$ali}" => 2, "payda_{$ali}" => 3, "pay_{$veli}" => 2, "payda_{$veli}" => 3]);
            $this->fail('1/1 olmayan hisse kaydedilmemeli');
        } catch (\Filament\Support\Exceptions\Halt) {
        }
        $this->assertSame('1/2 1/2', $share($u[2]));
        $i->saveCustomShare(['unit_id' => $u[2]->id, "pay_{$ali}" => 2, "payda_{$ali}" => 3, "pay_{$veli}" => 1, "payda_{$veli}" => 3]);
        $this->assertSame('2/3 1/3', $share($u[2]));

        // ⑥ kontrol: sahipsiz/hissesi bozuk yok; Veli'nin TC'si ve arsa alanı eksik uyarısı var.
        $checks = collect($i->checks());
        $this->assertFalse($checks->contains(fn ($x) => $x[0] === 'error'));
        $this->assertTrue($checks->contains(fn ($x) => str_contains($x[1], 'T.C. / VKN boş: VELİ KAYA')));

        Livewire::test(DeclarationBuilder::class, ['record' => $p->id])
            ->assertSee('Tümü — Formatlı PDF')->assertSee('ALİ YILMAZ');
    }

    public function test_import_from_land_share_is_repeatable(): void
    {
        $proj = Project::create(['name' => 'Arsa P', 'status' => 'active']);
        $study = LandShareStudy::create(['project_id' => $proj->id, 'name' => 'HD', 'status' => 'draft', 'block_count' => 1]);
        $ahmet = $study->shareholders()->create(['name' => 'Ahmet Yılmaz', 'current_pay' => 1, 'current_payda' => 2]);
        $mehmet = $study->shareholders()->create(['name' => 'Mehmet Yılmaz', 'current_pay' => 1, 'current_payda' => 2]);
        $study->syncStructure(['A' => 3]);
        [$s1, $s2, $s3] = LandSection::whereIn('block_id', $study->blocks()->pluck('id'))->orderBy('bb_no')->get()->all();
        $s1->allocations()->create(['shareholder_id' => $ahmet->id, 'pay' => 1, 'payda' => 1]);
        $s2->allocations()->createMany([['shareholder_id' => $ahmet->id, 'pay' => 1, 'payda' => 2], ['shareholder_id' => $mehmet->id, 'pay' => 1, 'payda' => 2]]);
        $s3->update(['type' => 'dukkan', 'arsa_pay' => 5, 'arsa_payda' => 100]);

        $p = $this->project();
        $p->taxpayers()->create(['surname' => 'AHMET YILMAZ', 'tax_id' => '999']); // önceden girilmiş, tekrar açılmamalı

        $c = LandShareImporter::import($p, $study);
        $this->assertSame(['taxpayers' => 1, 'blocks' => 1, 'units' => 3, 'owners' => 3], $c);

        $block = $p->blocks()->sole();
        $this->assertSame('A Blok', $block->name);
        $units = $block->units()->orderBy('sort_order')->get();
        $this->assertSame('1', $units[0]->unit_no);
        $this->assertSame('DÜKKAN', $units[2]->usage_type);
        $this->assertSame(5, (int) $units[2]->land_share_numerator);
        $this->assertSame('999', $p->taxpayers()->where('surname', 'AHMET YILMAZ')->value('tax_id'));
        $this->assertSame(2, $units[1]->allocations()->count());

        // İkinci kez: hiçbir şey çoğalmaz.
        $again = LandShareImporter::import($p, $study);
        $this->assertSame(['taxpayers' => 0, 'blocks' => 0, 'units' => 0], array_slice($again, 0, 3, true));
        $this->assertSame(2, $p->taxpayers()->count());
        $this->assertSame(3, $block->units()->count());
        $this->assertSame(2, $units[1]->allocations()->count());
    }

    public function test_unnamed_block_prints_pdf(): void
    {
        $p = $this->project();
        $t = $p->taxpayers()->create(['surname' => 'TEK SAHİP']);
        $block = $p->blocks()->create(['name' => null, 'construction_type' => 'B.ARME', 'usage_type' => 'MESKEN']);
        $unit = $block->units()->create(['unit_no' => '1', 'floor_no' => 0, 'area' => 100]);
        $unit->allocations()->create(['property_tax_taxpayer_id' => $t->id, 'pay' => 1, 'payda' => 1]);

        $this->get(route('property-tax.taxpayer.pdf', $t))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('property-tax.project.pdf', $p))->assertOk();
    }

    /** Mimar 06.11: Hisse'den Al → BB'ler sırayla katlara, standart m², hepsi mesken (Hisse'deki dükkan/kat yok sayılır). */
    public function test_import_lays_units_out_on_floors_from_layout(): void
    {
        $proj = Project::create(['name' => 'Kat P', 'status' => 'active']);
        $study = LandShareStudy::create(['project_id' => $proj->id, 'name' => 'HD', 'status' => 'draft', 'block_count' => 1]);
        $study->syncStructure(['A' => 10]);
        LandSection::whereIn('block_id', $study->blocks()->pluck('id'))->where('bb_no', '1')->update(['type' => 'dukkan', 'floor' => '3']);

        $p = $this->project();
        LandShareImporter::import($p, $study, ['per_floor' => 4, 'area' => '120', 'ground_shops' => false]);

        $units = $p->blocks()->sole()->units()->get()->sortBy(fn ($u) => (int) $u->unit_no)->values();
        $this->assertSame(10, $units->count());
        $this->assertSame([0, 0, 0, 0, 1, 1, 1, 1, 2, 2], $units->pluck('floor_no')->map(fn ($f) => (int) $f)->all());
        $this->assertSame([1, 2, 3, 4, 1, 2, 3, 4, 1, 2], $units->pluck('floor_position')->map(fn ($f) => (int) $f)->all());
        $this->assertTrue($units->every(fn ($u) => (float) $u->area === 120.0 && $u->usage_type === null));

        // Mimar elle düzeltir → tekrar aktarımda bozulmaz.
        $units[0]->update(['usage_type' => 'DÜKKAN', 'area' => 300, 'floor_no' => 0]);
        LandShareImporter::import($p, $study, ['per_floor' => 2, 'area' => '90']);
        $u1 = $units[0]->fresh();
        $this->assertSame('DÜKKAN', $u1->usage_type);
        $this->assertSame(300.0, (float) $u1->area);
        $this->assertSame(2, (int) $units[9]->fresh()->floor_no);
    }

    public function test_import_layout_with_ground_shops_numbers_from_first_floor(): void
    {
        $proj = Project::create(['name' => 'Kat P', 'status' => 'active']);
        $study = LandShareStudy::create(['project_id' => $proj->id, 'name' => 'HD', 'status' => 'draft', 'block_count' => 1]);
        $study->syncStructure(['A' => 6]);

        $p = $this->project();
        LandShareImporter::import($p, $study, ['per_floor' => 2, 'area' => '100', 'ground_shops' => true, 'shop_count' => 2, 'shop_area' => '80', 'start_above' => true]);

        $units = $p->blocks()->sole()->units()->get()->sortBy(fn ($u) => (int) $u->unit_no)->values();
        $this->assertSame([1, 1, 2, 2, 0, 0], $units->pluck('floor_no')->map(fn ($f) => (int) $f)->all());
        $this->assertSame([null, null, null, null, 'DÜKKAN', 'DÜKKAN'], $units->pluck('usage_type')->all());
        $this->assertSame(80.0, (float) $units[5]->area);
    }

    /** Yıldız 30.10: katta 10 daire → şablonun sabit yazdırma alanı (A1:O67) krokinin sağ yarısını kesiyordu. */
    public function test_wide_kroki_print_area_covers_whole_drawing(): void
    {
        $p = $this->project();
        $t = $p->taxpayers()->create(['surname' => 'TEK SAHİP']);
        $block = $p->blocks()->create(['name' => 'A Blok', 'construction_type' => 'B.ARME', 'usage_type' => 'MESKEN']);
        \App\Services\PropertyTax\UnitGenerator::create($block, ['residential_floors' => 3, 'per_floor' => 10, 'area' => 75]);
        $block->units()->first()->allocations()->create(['property_tax_taxpayer_id' => $t->id, 'pay' => 1, 'payda' => 1]);

        $xlsx = (new \App\Services\PropertyTax\DeclarationExporter())->exportForProject($p);
        $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($xlsx);
        @unlink($xlsx);
        $kroki = collect($ss->getAllSheets())->first(fn ($sh) => str_starts_with($sh->getTitle(), 'KROK'));
        $this->assertNotNull($kroki);

        [$from, $to] = explode(':', $kroki->getPageSetup()->getPrintArea());
        [$col, $row] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($to);
        $this->assertSame(1 + 10 * 3, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($col)); // B + 10 kutu × 3 sütun
        $this->assertGreaterThanOrEqual($kroki->getHighestDataRow(), (int) $row); // özet satırı dahil
    }

    /** 06.11: ④ Daireler'de boş Kullanış / Arsa payı "bloktan" yazmaz — bloğun değeriyle dolu gelir; elle yazılan korunur. */
    public function test_units_step_shows_block_values_instead_of_placeholder(): void
    {
        $p = $this->project();
        $b = $p->blocks()->create(['name' => 'A Blok', 'construction_type' => 'B.ARME', 'usage_type' => 'MESKEN', 'land_share_numerator' => 7, 'land_share_denominator' => 400]);
        $u1 = $b->units()->create(['unit_no' => '1', 'floor_no' => 0]); // pay kolonu NOT NULL varsayılan 1; payda boş
        $u2 = $b->units()->create(['unit_no' => '2', 'floor_no' => 0, 'usage_type' => 'DÜKKAN', 'land_share_numerator' => 66, 'land_share_denominator' => 400]);

        $rows = collect(Livewire::test(DeclarationBuilder::class, ['record' => $p->id])->get('data.units.b' . $b->id))->keyBy('id');
        $this->assertSame(['MESKEN', 1, 400], [$rows[$u1->id]['usage_type'], (int) $rows[$u1->id]['land_share_numerator'], (int) $rows[$u1->id]['land_share_denominator']]);
        $this->assertSame(['DÜKKAN', 66], [$rows[$u2->id]['usage_type'], (int) $rows[$u2->id]['land_share_numerator']]);
    }
}
