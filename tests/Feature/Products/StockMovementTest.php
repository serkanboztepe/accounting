<?php

namespace Tests\Feature\Products;

use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Models\Party;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ③ Stok hareketi (ayrı stock_movements tablosu) — mevcut = Σgiren − Σçıkan,
 * amount otomatiği, Mal Girişi ekranı.
 */
class StockMovementTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local']);
    }

    public function test_current_stock_is_in_minus_out(): void
    {
        $p = Product::create(['name' => 'Çimento', 'type' => Product::TYPE_PRODUCT]);

        StockMovement::create(['product_id' => $p->id, 'direction' => 'in', 'reason' => 'purchase', 'quantity' => 50, 'movement_date' => now()]);
        StockMovement::create(['product_id' => $p->id, 'direction' => 'in', 'reason' => 'purchase', 'quantity' => 20, 'movement_date' => now()]);
        StockMovement::create(['product_id' => $p->id, 'direction' => 'out', 'reason' => 'sale', 'quantity' => 30, 'movement_date' => now()]);

        $this->assertSame(40.0, $p->fresh()->currentStock());
    }

    public function test_service_stock_is_zero(): void
    {
        $service = Product::create(['name' => 'İşçilik', 'type' => Product::TYPE_SERVICE]);
        $this->assertSame(0.0, $service->currentStock());
    }

    public function test_amount_derived_from_quantity_and_price(): void
    {
        $p = Product::create(['name' => 'Demir', 'type' => Product::TYPE_PRODUCT]);
        $m = StockMovement::create([
            'product_id' => $p->id, 'direction' => 'in', 'reason' => 'purchase',
            'quantity' => 10, 'unit_price' => 250, 'movement_date' => now(),
        ]);

        $this->assertSame('2500.00', $m->fresh()->amount);
    }

    public function test_mal_girisi_screen_creates_incoming_movement(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $p = Product::create(['name' => 'Çimento 50kg', 'type' => Product::TYPE_PRODUCT]);
        $party = Party::create(['name' => 'Ahmet Tic.']);

        Livewire::test(ListStockMovements::class)
            ->callAction('malGirisi', data: [
                'product_id' => $p->id,
                'movement_date' => now()->toDateString(),
                'quantity' => 500,
                'unit_price' => '185,50',
                'party_id' => $party->id,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $p->id,
            'direction' => 'in',
            'reason' => 'purchase',
            'quantity' => 500,
            'party_id' => $party->id,
        ]);
        $this->assertSame(500.0, $p->fresh()->currentStock());
    }

    public function test_stok_duzeltme_cikar_reduces_stock(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $p = Product::create(['name' => 'Boya', 'type' => Product::TYPE_PRODUCT]);
        StockMovement::create(['product_id' => $p->id, 'direction' => 'in', 'reason' => 'purchase', 'quantity' => 100, 'movement_date' => now()]);

        Livewire::test(ListStockMovements::class)
            ->callAction('duzeltme', data: [
                'adj_dir' => StockMovement::DIRECTION_OUT,
                'product_id' => $p->id,
                'movement_date' => now()->toDateString(),
                'quantity' => 15,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(85.0, $p->fresh()->currentStock()); // 100 − 15 fire
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $p->id, 'direction' => 'out', 'reason' => 'adjustment', 'quantity' => 15,
        ]);
    }

    public function test_product_index_shows_current_stock(): void
    {
        $user = User::factory()->create();
        $p = Product::create(['name' => 'Tuğla', 'type' => Product::TYPE_PRODUCT]);
        StockMovement::create(['product_id' => $p->id, 'direction' => 'in', 'reason' => 'purchase', 'quantity' => 1250, 'movement_date' => now()]);

        $this->actingAs($user)
            ->get('/admin/products')
            ->assertOk()
            ->assertSee('1.250,00'); // Mevcut Stok kolonu
    }
}
