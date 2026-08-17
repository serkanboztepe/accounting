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
}
