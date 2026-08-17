<?php

namespace Tests\Feature\LandShare;

use App\Models\LandBlock;
use App\Models\LandSection;
use App\Models\LandSectionAllocation;
use App\Models\LandShareholder;
use App\Models\LandShareStudy;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Gerçek şemaya karşı render duman testi — DatabaseTransactions ile tüm
 * yazımlar geri alınır (dev veritabanına kalıcı kayıt bırakmaz).
 */
class LedgerRenderTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // User modeli FilamentUser implement etmiyor → Filament panele erişimi
        // yalnız config('app.env')==='local' iken açar. Render testi için ayarla.
        config(['app.env' => 'local']);
    }

    private function makeStudy(): LandShareStudy
    {
        $project = Project::create(['name' => 'Render Test Projesi', 'status' => 'active']);
        $study = LandShareStudy::create(['project_id' => $project->id, 'name' => 'Render Test', 'status' => 'draft']);

        $ali  = LandShareholder::create(['study_id' => $study->id, 'name' => 'Ali', 'current_pay' => 1, 'current_payda' => 2]);
        $veli = LandShareholder::create(['study_id' => $study->id, 'name' => 'Veli', 'current_pay' => 1, 'current_payda' => 2]);

        $blok = LandBlock::create(['study_id' => $study->id, 'name' => 'A']);
        $d1 = LandSection::create(['block_id' => $blok->id, 'bb_no' => '1', 'type' => 'daire']);
        $d2 = LandSection::create(['block_id' => $blok->id, 'bb_no' => '2', 'type' => 'daire']);
        LandSectionAllocation::create(['section_id' => $d1->id, 'shareholder_id' => $ali->id, 'pay' => 1, 'payda' => 1]);
        LandSectionAllocation::create(['section_id' => $d2->id, 'shareholder_id' => $veli->id, 'pay' => 1, 'payda' => 1]);

        return $study;
    }

    public function test_ledger_page_renders_and_shows_cetvel(): void
    {
        $user = User::factory()->create();
        $study = $this->makeStudy();

        $response = $this->actingAs($user)
            ->get("/admin/land-share-studies/{$study->id}/cetvel");

        $response->assertOk();
        $response->assertSee('Hisse Dağılım Cetveli');
        $response->assertSee('TOPLAM');
        $response->assertSee('1/2'); // her iki hissedarın kalan hissesi
    }

    public function test_index_page_renders(): void
    {
        $user = User::factory()->create();
        $this->makeStudy();

        $this->actingAs($user)
            ->get('/admin/land-share-studies')
            ->assertOk();
    }

    public function test_print_output_renders_tapu_cetveli(): void
    {
        $user = User::factory()->create();
        $study = $this->makeStudy();

        $this->actingAs($user)
            ->get(route('land-share.print', ['study' => $study->id]))
            ->assertOk()
            ->assertSee('HİSSE DAĞILIM CETVELİ')
            ->assertSee('TOPLAM')
            ->assertSee('1/2'); // her iki hissedarın kalan hissesi

        // Yazdırma butonunu içerir (ekranda görünür, baskıda gizli).
        $this->actingAs($user)
            ->get(route('land-share.print', ['study' => $study->id]))
            ->assertSee('Yazdır');
    }

    public function test_standalone_create_page_has_project_selector(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/land-share-studies/create')
            ->assertOk()
            ->assertSee('Proje')
            ->assertSee('Çalışma Adı');
    }
}
