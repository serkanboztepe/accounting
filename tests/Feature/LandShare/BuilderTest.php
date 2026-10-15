<?php

namespace Tests\Feature\LandShare;

use App\Filament\Resources\LandShareStudies\Pages\StudyBuilder;
use App\Models\LandSection;
use App\Models\LandShareStudy;
use App\Models\Project;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class BuilderTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_builder_page_renders_wizard(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['name' => 'Builder P', 'status' => 'active']);
        $study = LandShareStudy::create(['project_id' => $project->id, 'name' => 'B', 'status' => 'draft']);

        $this->actingAs($user)
            ->get("/admin/land-share-studies/{$study->id}/olustur")
            ->assertOk()
            ->assertSee('Çalışma & Hissedarlar')
            ->assertSee('Blok Sayısı');
    }

    public function test_full_flow_step1_generates_step2_saves_step3_balances(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = Project::create(['name' => 'Flow P', 'status' => 'active']);
        $study = LandShareStudy::create(['project_id' => $project->id, 'name' => 'Flow', 'status' => 'draft']);

        $component = Livewire::test(StudyBuilder::class, ['record' => $study->id])
            ->set('data.project_id', $project->id)
            ->set('data.name', 'Flow')
            ->set('data.block_count', 1)
            ->set('data.units_per_block', 2)
            ->set('data.shareholders', [
                ['id' => null, 'name' => 'Ali', 'party_id' => null, 'current_pay' => 1, 'current_payda' => 2, 'is_contractor' => false, 'group_key' => null],
                ['id' => null, 'name' => 'Veli', 'party_id' => null, 'current_pay' => 1, 'current_payda' => 2, 'is_contractor' => false, 'group_key' => null],
            ])
            ->call('saveStep1');

        // Adım 1: 1 blok × 2 BB oluşmalı, 2 hissedar kaydedilmeli.
        $study->refresh();
        $this->assertSame(1, $study->blocks()->count());
        $this->assertSame(2, $study->shareholders()->count());
        $sections = LandSection::whereIn('block_id', $study->blocks()->pluck('id'))->orderBy('bb_no')->get();
        $this->assertCount(2, $sections);

        $ali = $study->shareholders()->where('name', 'Ali')->first();
        $veli = $study->shareholders()->where('name', 'Veli')->first();

        // Adım 2: BB1→Ali, BB2→Veli. (Gerçek UI'da "Kişi ekle" ile eklenir;
        // burada saveStep2 mantığını bileşen örneği üzerinden doğruluyoruz —
        // Livewire render reconciliation'ı iç içe state'i düşürmesin diye.)
        $inst = $component->instance();
        $inst->data['sections'] = [
            ['section_id' => $sections[0]->id, 'label' => '', 'allocations' => [['shareholder_id' => $ali->id, 'pay' => 1, 'payda' => 1]]],
            ['section_id' => $sections[1]->id, 'label' => '', 'allocations' => [['shareholder_id' => $veli->id, 'pay' => 1, 'payda' => 1]]],
        ];
        $inst->saveStep2();

        $this->assertSame(1, $sections[0]->fresh()->allocations()->count());
        $this->assertSame(1, $sections[1]->fresh()->allocations()->count());

        // Adım 3: cetvel dengeli (her biri 1/2), toplam 1/1.
        $result = (new \App\Services\LandShare\ShareCalculator())
            ->calculate($study->fresh()->toStudyData(), \App\Services\LandShare\ShareCalculator::BLOK_DAIRE);
        $this->assertTrue($result->isBalanced());
    }

    public function test_wizard_next_action_advances_from_step1(): void
    {
        // Gerçek "Tamam →" (sihirbaz next aksiyonu) yolu — getStateSnapshot
        // regresyonunu kapsar (enjekte edilen repeater state'i container'sızdı).
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = Project::create(['name' => 'Next P', 'status' => 'active']);
        $study = LandShareStudy::create(['project_id' => $project->id, 'name' => 'Next', 'status' => 'draft']);

        Livewire::test(StudyBuilder::class, ['record' => $study->id])
            ->set('data.project_id', $project->id)
            ->set('data.name', 'Next')
            ->set('data.block_count', 2)
            ->set('data.block_units', ['A' => 4, 'B' => 2])
            ->set('data.shareholders', [
                ['id' => null, 'name' => 'Müteahhit', 'party_id' => null, 'current_pay' => 1, 'current_payda' => 1, 'is_contractor' => true, 'group_key' => null],
            ])
            ->goToNextWizardStep()
            ->assertHasNoErrors();

        $study->refresh();
        $this->assertSame(2, $study->blocks()->count());
        $this->assertSame(6, LandSection::whereIn('block_id', $study->blocks()->pluck('id'))->count());
        $this->assertSame(4, $study->blocks()->where('name', 'A')->first()->sections()->count());
        $this->assertSame(2, $study->blocks()->where('name', 'B')->first()->sections()->count());
    }

    public function test_distribute_by_existing_shares(): void
    {
        // Kullanıcının gerçek senaryosu: paylaşımlı BB'ye 5 kişi 1/1 girilmiş;
        // düğme mevcut hisse oranına göre düzeltmeli (Beyhan 1/4, diğerleri 3/16).
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = Project::create(['name' => 'Dist P', 'status' => 'active']);
        $study = LandShareStudy::create(['project_id' => $project->id, 'name' => 'Dist', 'status' => 'draft', 'block_count' => 1, 'units_per_block' => 6]);

        $defs = [
            ['Beyhan', 15, 124], ['Aydın', 45, 496], ['Sezer', 45, 496], ['Yağmur', 45, 496], ['Doğuş', 45, 496],
        ];
        $ids = [];
        foreach ($defs as [$n, $p, $d]) {
            $ids[$n] = $study->shareholders()->create(['name' => $n, 'current_pay' => $p, 'current_payda' => $d])->id;
        }
        $study->syncStructure();
        $bb2 = $study->blocks()->first()->sections()->where('bb_no', '2')->first();

        $inst = Livewire::test(StudyBuilder::class, ['record' => $study->id])->instance();

        $input = [[
            'section_id'  => $bb2->id,
            'label'       => 'A · BB 2',
            'allocations' => array_map(fn ($id) => ['shareholder_id' => $id, 'pay' => 1, 'payda' => 1], array_values($ids)),
        ]];
        [$out, $touched] = $inst->applyExistingShareSplit($input);

        $this->assertSame(1, $touched);
        $result = collect($out[0]['allocations'])
            ->mapWithKeys(fn ($a) => [array_search($a['shareholder_id'], $ids, true) => $a['pay'] . '/' . $a['payda']]);

        $this->assertSame('1/4', $result['Beyhan']);
        $this->assertSame('3/16', $result['Aydın']);
        $this->assertSame('3/16', $result['Doğuş']);

        // Toplam 1/1 mi?
        $sum = \App\Support\Fraction::zero();
        foreach ($out[0]['allocations'] as $a) {
            $sum = $sum->add(\App\Support\Fraction::of($a['pay'], $a['payda']));
        }
        $this->assertTrue($sum->equals(\App\Support\Fraction::of(1)));
    }

    public function test_fill_empty_with_contractor(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = Project::create(['name' => 'Fill P', 'status' => 'active']);
        $study = LandShareStudy::create(['project_id' => $project->id, 'name' => 'Fill', 'status' => 'draft']);

        Livewire::test(StudyBuilder::class, ['record' => $study->id])
            ->set('data.project_id', $project->id)
            ->set('data.name', 'Fill')
            ->set('data.block_count', 1)
            ->set('data.units_per_block', 3)
            ->set('data.shareholders', [
                ['id' => null, 'name' => 'Müteahhit', 'party_id' => null, 'current_pay' => 0, 'current_payda' => 1, 'is_contractor' => true, 'group_key' => null],
            ])
            ->call('saveStep1')
            ->call('fillEmptyWithContractor');

        $allocated = LandSection::whereIn('block_id', $study->blocks()->pluck('id'))
            ->whereHas('allocations')->count();
        $this->assertSame(3, $allocated, 'Tüm boş BB\'ler müteahhide atanmalı');
    }

    public function test_owner_ticks_split_equally_or_share_the_remainder(): void
    {
        $alloc = fn (array $owners) => collect(StudyBuilder::ownersToAllocations($owners))
            ->mapWithKeys(fn ($a) => [$a['shareholder_id'] => $a['pay'] . '/' . $a['payda']])->all();

        // Hiç pay yazılmadı → tikliler eşit; tiksiz yok sayılır.
        $this->assertSame([1 => '1/2', 2 => '1/2'], $alloc([
            'sh1' => ['inc' => true], 'sh2' => ['inc' => true], 'sh3' => ['inc' => false, 'pay' => 1, 'payda' => 1],
        ]));
        // Tek kişi → 1/1 (TAM).
        $this->assertSame([7 => '1/1'], $alloc(['sh7' => ['inc' => true, 'pay' => null, 'payda' => null]]));
        // Biri 1/2 yazıldı, iki boş → kalanı eşit: 1/4 + 1/4.
        $this->assertSame([1 => '1/2', 2 => '1/4', 3 => '1/4'], $alloc([
            'sh1' => ['inc' => true, 'pay' => 1, 'payda' => 2], 'sh2' => ['inc' => true], 'sh3' => ['inc' => true, 'pay' => '', 'payda' => ''],
        ]));
        // Yazılanlar zaten 1/1 → boş kalan pay alamaz (atlanır; rozet uyarır).
        $this->assertSame([1 => '1/1'], $alloc(['sh1' => ['inc' => true, 'pay' => 1, 'payda' => 1], 'sh2' => ['inc' => true]]));
    }

    public function test_step2_saves_from_owner_ticks_and_reloads_them(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::create(['name' => 'Tick P', 'status' => 'active']);
        $study = LandShareStudy::create(['project_id' => $project->id, 'name' => 'Tick', 'status' => 'draft']);

        $component = Livewire::test(StudyBuilder::class, ['record' => $study->id])
            ->set('data.project_id', $project->id)
            ->set('data.name', 'Tick')
            ->set('data.block_count', 1)
            ->set('data.block_units', ['A' => 2])
            ->set('data.shareholders', [
                ['id' => null, 'name' => 'Ali', 'party_id' => null, 'current_pay' => 1, 'current_payda' => 2, 'is_contractor' => false, 'group_key' => null],
                ['id' => null, 'name' => 'Veli', 'party_id' => null, 'current_pay' => 1, 'current_payda' => 2, 'is_contractor' => false, 'group_key' => null],
            ])
            ->call('saveStep1');

        $sections = LandSection::whereIn('block_id', $study->blocks()->pluck('id'))->orderBy('bb_no')->get();
        $ali = $study->shareholders()->where('name', 'Ali')->value('id');
        $veli = $study->shareholders()->where('name', 'Veli')->value('id');

        $inst = $component->instance();
        $inst->data['sections'] = [
            ['section_id' => $sections[0]->id, 'label' => '', 'owners' => ["sh{$ali}" => ['inc' => true], "sh{$veli}" => ['inc' => true]]],
            ['section_id' => $sections[1]->id, 'label' => '', 'owners' => ["sh{$ali}" => ['inc' => true], "sh{$veli}" => ['inc' => false]]],
        ];
        $inst->saveStep2();

        $this->assertSame([[$ali, 1, 2], [$veli, 1, 2]], $sections[0]->allocations()->orderBy('shareholder_id')->get()
            ->map(fn ($a) => [$a->shareholder_id, $a->pay, $a->payda])->all());
        $this->assertSame([[$ali, 1, 1]], $sections[1]->allocations()->get()
            ->map(fn ($a) => [$a->shareholder_id, $a->pay, $a->payda])->all());

        // Sayfa yeniden açılınca kayıtlı sahipler tikli + hisseleri yazılı gelir; adım 2 render olur.
        $reloaded = Livewire::test(StudyBuilder::class, ['record' => $study->id])
            ->assertSee('Sahipleri')
            ->assertSee('Ali');
        $first = array_values($reloaded->instance()->data['sections'])[0]['owners'];
        $this->assertTrue((bool) $first["sh{$ali}"]['inc']);
        $this->assertTrue((bool) $first["sh{$veli}"]['inc']);
        $this->assertEquals([1, 2], [$first["sh{$ali}"]['pay'], $first["sh{$ali}"]['payda']]);
    }

    public function test_person_picks_units_shared_ones_split_equally(): void
    {
        $owners = fn (array $item) => collect(StudyBuilder::ownersToAllocations($item['owners'] ?? []))
            ->map(fn ($a) => $a['shareholder_id'] . ':' . $a['pay'] . '/' . $a['payda'])->implode(' ');
        $sections = [
            ['section_id' => 10, 'owners' => []],                                                   // boş
            ['section_id' => 11, 'owners' => ['sh2' => ['inc' => true, 'pay' => 1, 'payda' => 1]]], // Mehmet'in
            ['section_id' => 12, 'owners' => ['sh1' => ['inc' => true, 'pay' => 1, 'payda' => 1]]], // Ahmet'in
            ['section_id' => 13, 'owners' => ['sh2' => ['inc' => true, 'pay' => 2, 'payda' => 3], 'sh3' => ['inc' => true, 'pay' => 1, 'payda' => 3]]],
        ];

        // Ahmet (1) 10 ve 11'i seçti, 12'yi bıraktı; 13'e dokunmadı.
        $out = StudyBuilder::applyPersonUnits($sections, 1, [10, 11]);
        $this->assertSame('1:1/1', $owners($out[0]));          // boş BB → tek sahip TAM
        $this->assertSame('2:1/2 1:1/2', $owners($out[1]));    // ortak → eşit
        $this->assertSame('', $owners($out[2]));               // işareti kaldırıldı → boş
        $this->assertSame('2:2/3 3:1/3', $owners($out[3]));    // değişmeyen özel hisse korunur

        // Ortaktan çıkınca kalan kişi TAM olur.
        $back = StudyBuilder::applyPersonUnits($out, 1, [10]);
        $this->assertSame('2:1/1', $owners($back[1]));
    }

    public function test_assign_person_units_saves_to_db(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::create(['name' => 'Person P', 'status' => 'active']);
        $study = LandShareStudy::create(['project_id' => $project->id, 'name' => 'Person', 'status' => 'draft']);
        $component = Livewire::test(StudyBuilder::class, ['record' => $study->id])
            ->set('data.project_id', $project->id)->set('data.name', 'Person')
            ->set('data.block_count', 1)->set('data.block_units', ['A' => 3])
            ->set('data.shareholders', [
                ['id' => null, 'name' => 'Ali', 'party_id' => null, 'current_pay' => 1, 'current_payda' => 2, 'is_contractor' => false, 'group_key' => null],
                ['id' => null, 'name' => 'Veli', 'party_id' => null, 'current_pay' => 1, 'current_payda' => 2, 'is_contractor' => false, 'group_key' => null],
            ])
            ->call('saveStep1');

        $s = LandSection::whereIn('block_id', $study->blocks()->pluck('id'))->orderBy('bb_no')->get();
        $block = 'block_' . $s[0]->block_id;
        $ali = $study->shareholders()->where('name', 'Ali')->value('id');
        $veli = $study->shareholders()->where('name', 'Veli')->value('id');

        $component->call('assignPersonUnits', $ali, [$block => [$s[0]->id, $s[1]->id]])
            ->call('assignPersonUnits', $veli, [$block => [$s[1]->id, $s[2]->id]]);

        $rows = fn (LandSection $x) => $x->allocations()->orderBy('shareholder_id')->get()
            ->map(fn ($a) => [$a->shareholder_id, $a->pay, $a->payda])->all();
        $this->assertSame([[$ali, 1, 1]], $rows($s[0]));
        $this->assertSame([[$ali, 1, 2], [$veli, 1, 2]], $rows($s[1]));
        $this->assertSame([[$veli, 1, 1]], $rows($s[2]));

        $component->assertSee('Daire Seç')->assertSee('2 BB: A1, A2 (ortak)');
    }

    public function test_split_shares_equal_or_by_existing(): void
    {
        $f = fn ($p, $d) => \App\Support\Fraction::of($p, $d);
        $existing = [1 => $f(1, 2), 2 => $f(1, 4), 3 => $f(0, 1)];
        $txt = fn (array $rows) => collect($rows)->map(fn ($a) => $a['shareholder_id'] . ':' . $a['pay'] . '/' . $a['payda'])->implode(' ');

        $this->assertSame('1:1/2 2:1/2', $txt(StudyBuilder::splitShares([1, 2], StudyBuilder::SPLIT_EQUAL, $existing)));
        $this->assertSame('1:2/3 2:1/3', $txt(StudyBuilder::splitShares([1, 2], StudyBuilder::SPLIT_EXISTING, $existing)));
        // Birinin mevcut hissesi yoksa (müteahhit) o BB eşit — 0 pay alıp düşmesin.
        $this->assertSame('1:1/2 3:1/2', $txt(StudyBuilder::splitShares([1, 3], StudyBuilder::SPLIT_EXISTING, $existing)));
        $this->assertSame('3:1/1', $txt(StudyBuilder::splitShares([3], StudyBuilder::SPLIT_EXISTING, $existing)));

        // Otomatik mi elle mi: eşit ve mevcut-hisse bölmesi otomatik; 3/4 + 1/4 elle.
        $rows = fn (array $x) => array_map(fn ($r) => ['shareholder_id' => $r[0], 'pay' => $r[1], 'payda' => $r[2]], $x);
        $this->assertTrue(StudyBuilder::isAutoSplit($rows([[1, 1, 2], [2, 1, 2]]), $existing));
        $this->assertTrue(StudyBuilder::isAutoSplit($rows([[1, 2, 3], [2, 1, 3]]), $existing));
        $this->assertTrue(StudyBuilder::isAutoSplit($rows([[1, 2, 4], [2, 2, 4]]), $existing)); // sadeleşmemiş eşit
        $this->assertFalse(StudyBuilder::isAutoSplit($rows([[1, 3, 4], [2, 1, 4]]), $existing));

        // Kişi ataması mevcut-hisse modunda: ortak BB 2/3 + 1/3.
        $out = StudyBuilder::applyPersonUnits([['section_id' => 5, 'owners' => ['sh2' => ['inc' => true, 'pay' => 1, 'payda' => 1]]]],
            1, [5], StudyBuilder::SPLIT_EXISTING, $existing);
        $this->assertSame('2:1/3 1:2/3', $txt(StudyBuilder::ownersToAllocations($out[0]['owners'])));
    }

    public function test_changing_split_mode_resplits_auto_and_keeps_manual(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::create(['name' => 'Mode P', 'status' => 'active']);
        $study = LandShareStudy::create(['project_id' => $project->id, 'name' => 'Mode', 'status' => 'draft']);
        $ali = $study->shareholders()->create(['name' => 'Ali', 'current_pay' => 1, 'current_payda' => 2])->id;
        $veli = $study->shareholders()->create(['name' => 'Veli', 'current_pay' => 1, 'current_payda' => 4])->id;
        $study->update(['block_count' => 1]);
        $study->syncStructure(['A' => 3]);
        [$a1, $a2, $a3] = LandSection::whereIn('block_id', $study->blocks()->pluck('id'))->orderBy('bb_no')->get()->all();
        $a1->allocations()->createMany([['shareholder_id' => $ali, 'pay' => 1, 'payda' => 2], ['shareholder_id' => $veli, 'pay' => 1, 'payda' => 2]]); // eşit (oto)
        $a2->allocations()->createMany([['shareholder_id' => $ali, 'pay' => 3, 'payda' => 4], ['shareholder_id' => $veli, 'pay' => 1, 'payda' => 4]]); // elle
        $a3->allocations()->create(['shareholder_id' => $ali, 'pay' => 1, 'payda' => 1]);

        $rows = fn (LandSection $x) => $x->allocations()->orderBy('shareholder_id')->get()->map(fn ($a) => $a->pay . '/' . $a->payda)->implode(' ');
        $c = Livewire::test(StudyBuilder::class, ['record' => $study->id])->call('setSplitMode', StudyBuilder::SPLIT_EXISTING);

        $this->assertSame(StudyBuilder::SPLIT_EXISTING, $study->fresh()->shared_split);
        $this->assertSame('2/3 1/3', $rows($a1));   // oto → mevcut hisseye göre
        $this->assertSame('3/4 1/4', $rows($a2));   // elle → korunur
        $this->assertSame('1/1', $rows($a3));       // tek sahip

        // Yeni ortak atama da bu kurala uyar; geri "eşit" seçilince oto olanlar eşite döner, elle olan yine kalır.
        $c->call('assignPersonUnits', $veli, ['block_' . $a3->block_id => [$a1->id, $a2->id, $a3->id]]);
        $this->assertSame('2/3 1/3', $rows($a3));
        $c->call('setSplitMode', StudyBuilder::SPLIT_EQUAL);
        $this->assertSame('1/2 1/2', $rows($a1));
        $this->assertSame('3/4 1/4', $rows($a2));
        $this->assertSame('1/2 1/2', $rows($a3));
    }

    public function test_fill_empty_needs_a_marked_contractor(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::create(['name' => 'NoC P', 'status' => 'active']);
        $study = LandShareStudy::create(['project_id' => $project->id, 'name' => 'NoC', 'status' => 'draft', 'block_count' => 1]);
        $study->shareholders()->create(['name' => 'Ahmet', 'current_pay' => 1, 'current_payda' => 1]);
        $study->syncStructure(['A' => 2]);

        // Müteahhit işaretli değil → boş BB'ler ilk kişiye YAZILMAZ.
        Livewire::test(StudyBuilder::class, ['record' => $study->id])->call('fillEmptyWithContractor');
        $this->assertSame(0, LandSection::whereIn('block_id', $study->blocks()->pluck('id'))->whereHas('allocations')->count());
    }

    public function test_unsaved_land_share_survives_person_modal_save(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::create(['name' => 'Arsa P', 'status' => 'active']);
        $study = LandShareStudy::create(['project_id' => $project->id, 'name' => 'Arsa', 'status' => 'draft', 'block_count' => 1, 'land_share_denominator' => 1000]);
        $ali = $study->shareholders()->create(['name' => 'Ali', 'current_pay' => 1, 'current_payda' => 1])->id;
        $study->syncStructure(['A' => 2]);
        [$a1, $a2] = LandSection::whereIn('block_id', $study->blocks()->pluck('id'))->orderBy('bb_no')->get()->all();
        $a1->update(['arsa_pay' => 600, 'arsa_payda' => 1000]);
        $a2->update(['arsa_pay' => 400, 'arsa_payda' => 1000]);

        $c = Livewire::test(StudyBuilder::class, ['record' => $study->id]);
        $key = array_keys($c->instance()->data['sections'])[1];
        $c->set("data.sections.{$key}.arsa_pay", 150)              // ③'te yazıldı, Tamam'a basılmadı
            ->call('assignPersonUnits', $ali, ['block_' . $a1->block_id => [$a1->id]]);

        $this->assertSame(150, $a2->fresh()->arsa_pay, 'Kişi penceresi kaydı ③\'teki kaydedilmemiş arsa payını silmemeli');
        $this->assertSame(600, $a1->fresh()->arsa_pay);
        $this->assertSame(1, $a1->allocations()->count());
    }
}
