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
            ->set('data.units_per_block', 3)
            ->set('data.shareholders', [
                ['id' => null, 'name' => 'Müteahhit', 'party_id' => null, 'current_pay' => 1, 'current_payda' => 1, 'is_contractor' => true, 'group_key' => null],
            ])
            ->goToNextWizardStep()
            ->assertHasNoErrors();

        $study->refresh();
        $this->assertSame(2, $study->blocks()->count());
        $this->assertSame(6, LandSection::whereIn('block_id', $study->blocks()->pluck('id'))->count());
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
}
