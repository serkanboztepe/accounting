<?php

namespace Tests\Feature\Products;

use App\Filament\Resources\Sales\Pages\EditSale;
use App\Filament\Resources\Sales\RelationManagers\ReturnsRelationManager;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Models\Party;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class StockAndReturnUiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local']);
        $this->actingAs(User::factory()->create());
    }

    private function saleWith(float $opening, float $sellQty): array
    {
        $party = Party::create(['name' => 'Ahmet İnşaat']);
        $product = Product::create(['name' => 'Çimento', 'type' => Product::TYPE_PRODUCT]);
        StockMovement::create(['product_id' => $product->id, 'direction' => 'in', 'reason' => 'purchase', 'quantity' => $opening, 'movement_date' => now()]);
        $sale = Sale::create(['party_id' => $party->id, 'sale_date' => now(), 'total_amount' => 0]);
        $sale->rebuildFromLines([['product_id' => $product->id, 'quantity' => $sellQty, 'unit_price' => '10,00']]);

        return [$sale, $product];
    }

    public function test_stock_movements_sale_line_readonly_manual_editable(): void
    {
        [$sale, $product] = $this->saleWith(200, 50);
        $saleLine = $sale->lines()->first();               // sale_id dolu (kilitli)
        $manual = StockMovement::where('reason', 'purchase')->first(); // elle giriş

        Livewire::test(ListStockMovements::class)
            ->assertTableActionHidden('edit', $saleLine)
            ->assertTableActionHidden('delete', $saleLine)
            ->assertTableActionVisible('edit', $manual)
            ->assertTableActionVisible('delete', $manual);
    }

    public function test_geri_al_from_returns_relation_manager_undoes(): void
    {
        [$sale, $product] = $this->saleWith(200, 100); // stok 100
        $return = $sale->processReturn([['product_id' => $product->id, 'return_qty' => 30, 'unit_price' => '10,00']], now()->toDateString());
        $this->assertSame(130.0, $product->fresh()->currentStock());

        Livewire::test(ReturnsRelationManager::class, [
            'ownerRecord' => $sale,
            'pageClass' => EditSale::class,
        ])
            ->callTableAction('delete', $return)
            ->assertHasNoTableActionErrors();

        $this->assertSame(100.0, $product->fresh()->currentStock()); // geri alındı
        $this->assertNull($return->fresh());
    }
}
