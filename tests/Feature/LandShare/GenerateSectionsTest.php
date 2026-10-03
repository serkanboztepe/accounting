<?php

namespace Tests\Feature\LandShare;

use App\Models\LandShareholder;
use App\Models\LandShareStudy;
use App\Models\Project;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GenerateSectionsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_sync_structure_creates_blocks_and_bbs(): void
    {
        $project = Project::create(['name' => 'Sync Test', 'status' => 'active']);
        $study = LandShareStudy::create([
            'project_id' => $project->id, 'name' => 'Sync', 'status' => 'draft',
            'block_count' => 2, 'units_per_block' => 3,
        ]);

        $study->syncStructure();

        $this->assertSame(2, $study->blocks()->count());
        $this->assertEqualsCanonicalizing(['A', 'B'], $study->blocks()->pluck('name')->all());
        foreach ($study->blocks as $block) {
            $this->assertSame(3, $block->sections()->count(), "{$block->name} bloğunda 3 BB olmalı");
            $this->assertEqualsCanonicalizing(['1', '2', '3'], $block->sections()->pluck('bb_no')->all());
        }
    }

    public function test_sync_is_idempotent_and_preserves_allocations(): void
    {
        $project = Project::create(['name' => 'Idem Test', 'status' => 'active']);
        $study = LandShareStudy::create([
            'project_id' => $project->id, 'name' => 'Idem', 'status' => 'draft',
            'block_count' => 1, 'units_per_block' => 2,
        ]);
        $sh = LandShareholder::create(['study_id' => $study->id, 'name' => 'X', 'current_pay' => 1, 'current_payda' => 1]);

        $study->syncStructure();
        $section = $study->blocks()->first()->sections()->where('bb_no', '1')->first();
        $section->allocations()->create(['shareholder_id' => $sh->id, 'pay' => 1, 'payda' => 1]);

        // Tekrar çalıştır — çift blok/BB olmamalı, atama korunmalı.
        $study->syncStructure();

        $this->assertSame(1, $study->blocks()->count());
        $this->assertSame(2, $study->blocks()->first()->sections()->count());
        $this->assertSame(1, $section->fresh()->allocations()->count());
    }

    private function bbCounts(LandShareStudy $study): array
    {
        return $study->blocks()->orderBy('name')->get()
            ->mapWithKeys(fn ($b) => [$b->name => $b->sections()->count()])->all();
    }

    public function test_blocks_can_have_different_unit_counts(): void
    {
        $project = Project::create(['name' => 'Per Block', 'status' => 'active']);
        $study = LandShareStudy::create(['project_id' => $project->id, 'name' => 'PB', 'status' => 'draft', 'block_count' => 3]);

        $study->syncStructure(['A' => 8, 'B' => 6, 'C' => 4]);

        $this->assertSame(['A' => 8, 'B' => 6, 'C' => 4], $this->bbCounts($study));
        $this->assertSame(6, $study->blocks()->where('name', 'B')->first()->planned_unit_count);

        // Sayı vermeden tekrar çalıştır → kayıtlı sayılar korunur.
        $study->syncStructure();
        $this->assertSame(['A' => 8, 'B' => 6, 'C' => 4], $this->bbCounts($study));
    }

    public function test_reducing_counts_removes_only_empty_sections_and_blocks(): void
    {
        $project = Project::create(['name' => 'Reduce', 'status' => 'active']);
        $study = LandShareStudy::create(['project_id' => $project->id, 'name' => 'R', 'status' => 'draft', 'block_count' => 3]);
        $sh = LandShareholder::create(['study_id' => $study->id, 'name' => 'X', 'current_pay' => 1, 'current_payda' => 1]);
        $study->syncStructure(['A' => 5, 'B' => 5, 'C' => 2]);

        $a5 = $study->blocks()->where('name', 'A')->first()->sections()->where('bb_no', '5')->first();
        $a5->allocations()->create(['shareholder_id' => $sh->id, 'pay' => 1, 'payda' => 1]);

        // A 5→3 (A-4 boş → silinir, A-5 atamalı → korunur), B 5→2, C bloğu boş → silinir.
        $study->update(['block_count' => 2]);
        $kept = $study->syncStructure(['A' => 3, 'B' => 2]);

        $this->assertSame(['A-5'], $kept);
        $this->assertSame(['A' => 4, 'B' => 2], $this->bbCounts($study));
        $this->assertNotNull($a5->fresh());
    }

    public function test_extend_block_units_suggests_previous_count_for_new_blocks(): void
    {
        $project = Project::create(['name' => 'Ext', 'status' => 'active']);
        $study = LandShareStudy::create(['project_id' => $project->id, 'name' => 'E', 'status' => 'draft']);
        $this->actingAs(\App\Models\User::factory()->create());

        $inst = \Livewire\Livewire::test(\App\Filament\Resources\LandShareStudies\Pages\StudyBuilder::class, ['record' => $study->id])->instance();

        $this->assertSame(['A' => 8, 'B' => 6, 'C' => 6], $inst->extendBlockUnits(3, ['A' => 8, 'B' => 6]));
        $this->assertSame(['A' => 8], $inst->extendBlockUnits(1, ['A' => 8, 'B' => 6]));
    }
}
