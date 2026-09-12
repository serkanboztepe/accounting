<?php

namespace Tests\Feature\Products;

use App\Filament\Resources\Sales\Pages\CreateSale;
use App\Models\PartyLedgerEntry;
use App\Models\Party;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ④ Sözleşmesiz direkt satış — çok kalem → stok çıkışı + cari borç; silince geri döner.
 */
class DirectSaleTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local']);
    }

    private function stockedProduct(string $name, float $openingStock): Product
    {
        $p = Product::create(['name' => $name, 'type' => Product::TYPE_PRODUCT]);
        StockMovement::create([
            'product_id' => $p->id, 'direction' => 'in', 'reason' => 'purchase',
            'quantity' => $openingStock, 'movement_date' => now(),
        ]);

        return $p;
    }

    public function test_create_sale_decrements_stock_and_debits_party(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $party = Party::create(['name' => 'Ahmet İnşaat']);
        $cimento = $this->stockedProduct('Çimento 50kg', 100);
        $demir = $this->stockedProduct('Demir 12mm', 50);

        Livewire::test(CreateSale::class)
            ->fillForm([
                'party_id' => $party->id,
                'sale_date' => now()->toDateString(),
                'lines' => [
                    ['product_id' => $cimento->id, 'quantity' => 30, 'unit_price' => '185,50', 'amount' => '5.565,00'],
                    ['product_id' => $demir->id, 'quantity' => 10, 'unit_price' => '32.000,00', 'amount' => '320.000,00'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $sale = Sale::latest('id')->first();
        $this->assertNotNull($sale);

        // İki satış kalemi = iki stok çıkışı
        $this->assertSame(2, $sale->lines()->count());
        $this->assertSame(2, StockMovement::where('sale_id', $sale->id)->where('direction', 'out')->where('reason', 'sale')->count());

        // Stok düştü: 100-30=70, 50-10=40
        $this->assertSame(70.0, $cimento->fresh()->currentStock());
        $this->assertSame(40.0, $demir->fresh()->currentStock());

        // Toplam = 5.565 + 320.000 = 325.565
        $this->assertSame('325565.00', $sale->fresh()->total_amount);

        // Cari borç (satis) kaydı = toplam
        $ledger = PartyLedgerEntry::where('sale_id', $sale->id)->first();
        $this->assertNotNull($ledger);
        $this->assertSame(PartyLedgerEntry::TYPE_SALE, $ledger->type);
        $this->assertSame('borc', $ledger->direction);
        $this->assertSame('325565.00', $ledger->amount);
        $this->assertSame($party->id, $ledger->party_id);
    }

    public function test_deleting_sale_restores_stock_and_reverses_debt(): void
    {
        $party = Party::create(['name' => 'Mehmet Yapı']);
        $tugla = $this->stockedProduct('Tuğla', 1000);

        $sale = Sale::create(['party_id' => $party->id, 'sale_date' => now(), 'total_amount' => 0]);
        $sale->rebuildFromLines([
            ['product_id' => $tugla->id, 'quantity' => 200, 'unit_price' => '2,50', 'amount' => '500,00'],
        ]);

        $this->assertSame(800.0, $tugla->fresh()->currentStock());
        $this->assertSame(1, PartyLedgerEntry::where('sale_id', $sale->id)->count());

        $sale->delete();

        // Stok geri döndü, cari borç silindi (cascade)
        $this->assertSame(1000.0, $tugla->fresh()->currentStock());
        $this->assertSame(0, StockMovement::where('sale_id', $sale->id)->count());
        $this->assertSame(0, PartyLedgerEntry::where('sale_id', $sale->id)->count());
    }
}
