<?php

namespace Tests\Feature\Products;

use App\Filament\Resources\Contracts\Pages\EditContract;
use App\Filament\Resources\Contracts\RelationManagers\ItemsRelationManager;
use App\Models\Contract;
use App\Models\Party;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ② Kalem formu (ItemsRelationManager) katalog Select'i ile mount olur ve
 * product_id'yi kaydeder. Lazy closure (options/createOption) hatalarını yakalar.
 */
class ItemFormCatalogTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local']);
    }

    public function test_item_form_saves_product_id_from_catalog(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = Project::create(['name' => 'Test Proje', 'status' => 'active']);
        $party = Party::create(['name' => 'Test Tedarikçi']);
        $contract = Contract::create([
            'project_id' => $project->id, 'party_id' => $party->id,
            'title' => 'Test Sözleşme', 'contract_type' => 'supplier',
            'direction' => 'in', 'total_amount' => 0, 'status' => 'active',
        ]);
        $product = Product::create(['name' => 'Çimento 50kg', 'type' => Product::TYPE_PRODUCT]);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $contract,
            'pageClass' => EditContract::class,
        ])
            ->callTableAction('create', data: [
                'product_id' => $product->id,
                'description' => 'Çimento 50kg',
                'quantity' => 100,
                'unit_price' => '185,50',
                'amount' => '18.550,00',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('contract_items', [
            'contract_id' => $contract->id,
            'product_id' => $product->id,
            'description' => 'Çimento 50kg',
        ]);
    }
}
