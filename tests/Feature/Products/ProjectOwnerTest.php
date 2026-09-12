<?php

namespace Tests\Feature\Products;

use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * ⑤ projects.party_id — "bu proje bu müşteriye ait".
 */
class ProjectOwnerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local']);
    }

    public function test_project_belongs_to_owner_party(): void
    {
        $party = Party::create(['name' => 'Ahmet İnşaat']);
        $owned = Project::create(['name' => 'Yıldız Sitesi', 'party_id' => $party->id, 'status' => 'active']);
        $own = Project::create(['name' => 'Kendi Projem', 'status' => 'active']); // sahipsiz

        $this->assertSame($party->id, $owned->party->id);
        $this->assertNull($own->party);
    }

    public function test_project_index_shows_owner_column(): void
    {
        $user = User::factory()->create();
        $party = Party::create(['name' => 'Mehmet Yapı']);
        Project::create(['name' => 'Park Evleri', 'party_id' => $party->id, 'status' => 'active']);

        $this->actingAs($user)
            ->get('/admin/projects')
            ->assertOk()
            ->assertSee('Mehmet Yapı');
    }

    public function test_project_form_saves_owner(): void
    {
        $user = User::factory()->create();
        $party = Party::create(['name' => 'Kaya Ltd']);

        $this->actingAs($user)
            ->get('/admin/projects/create')
            ->assertOk()
            ->assertSee('Sahip müşteri');
    }
}
