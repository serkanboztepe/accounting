<?php

namespace Tests\Feature\Products;

use App\Filament\Resources\Sales\Pages\ListSales;
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
 * ⑥ İade — sevkte satış modeli: stok geri (+), cari borç azalır (alacak).
 */
class SaleReturnTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local']);
    }

    private function saleWith(Party $party, Product $product, float $opening, float $sellQty, string $price): Sale
    {
        StockMovement::create([
            'product_id' => $product->id, 'direction' => 'in', 'reason' => 'purchase',
            'quantity' => $opening, 'movement_date' => now(),
        ]);
        $sale = Sale::create(['party_id' => $party->id, 'sale_date' => now(), 'total_amount' => 0]);
        $sale->rebuildFromLines([
            ['product_id' => $product->id, 'quantity' => $sellQty, 'unit_price' => $price],
        ]);

        return $sale;
    }

    public function test_return_restores_stock_and_credits_party(): void
    {
        $party = Party::create(['name' => 'Ahmet İnşaat']);
        $cimento = Product::create(['name' => 'Çimento 50kg', 'type' => Product::TYPE_PRODUCT]);

        // Açılış 200, sat 100 @ 10 → stok 100, borç 1000
        $sale = $this->saleWith($party, $cimento, 200, 100, '10,00');
        $this->assertSame(100.0, $cimento->fresh()->currentStock());

        // 30 iade al
        $return = $sale->processReturn(
            [['product_id' => $cimento->id, 'return_qty' => 30, 'unit_price' => '10,00']],
            now()->toDateString(),
        );

        $this->assertNotNull($return);
        $this->assertSame(300.0, (float) $return->total_amount);
        // Stok geri: 100 + 30 = 130
        $this->assertSame(130.0, $cimento->fresh()->currentStock());
        // İade hareketi (in/return) oluştu
        $this->assertSame(1, $sale->returns()->count());
        // Cari alacak (satis_iade) kaydı = 300
        $credit = PartyLedgerEntry::where('sale_id', $sale->id)
            ->where('type', PartyLedgerEntry::TYPE_SALE_RETURN)->first();
        $this->assertNotNull($credit);
        $this->assertSame('alacak', $credit->direction);
        $this->assertSame('300.00', $credit->amount);

        // Kalan iade edilebilir: 100 − 30 = 70
        $this->assertSame(70.0, $sale->returnableQtyForProduct($cimento->id));
    }

    public function test_return_action_via_livewire(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $party = Party::create(['name' => 'Mehmet Yapı']);
        $tugla = Product::create(['name' => 'Tuğla', 'type' => Product::TYPE_PRODUCT]);
        $sale = $this->saleWith($party, $tugla, 1000, 400, '2,50');

        Livewire::test(ListSales::class)
            ->callTableAction('return', $sale, data: [
                'return_date' => now()->toDateString(),
                'lines' => [
                    ['product_id' => $tugla->id, 'unit_price' => '2,50', 'remaining' => 400, 'return_qty' => 50],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(650.0, $tugla->fresh()->currentStock()); // 1000-400+50
        $this->assertSame(1, $sale->returns()->count());
    }

    public function test_undoing_return_restores_stock_and_removes_credit(): void
    {
        $party = Party::create(['name' => 'Kaya Ltd']);
        $cimento = Product::create(['name' => 'Çimento 50kg', 'type' => Product::TYPE_PRODUCT]);

        // Açılış 200, sat 100 @10 → stok 100, borç 1000
        $sale = $this->saleWith($party, $cimento, 200, 100, '10,00');

        // 30 iade → stok 130, alacak 300
        $return = $sale->processReturn(
            [['product_id' => $cimento->id, 'return_qty' => 30, 'unit_price' => '10,00']],
            now()->toDateString(),
        );
        $this->assertSame(130.0, $cimento->fresh()->currentStock());
        $this->assertSame(1, \App\Models\PartyLedgerEntry::where('sale_return_id', $return->id)->count());

        // GERİ AL: iade başlığını sil → stok girişi + cari alacak cascade ile gider
        $return->delete();

        $this->assertSame(100.0, $cimento->fresh()->currentStock()); // iade geri alındı
        $this->assertSame(0, StockMovement::where('sale_return_id', $return->id)->count());
        $this->assertSame(0, \App\Models\PartyLedgerEntry::where('sale_return_id', $return->id)->count());

        // Satış ve borcu yerinde kalır
        $this->assertNotNull($sale->fresh());
        $this->assertSame(1, \App\Models\PartyLedgerEntry::where('sale_id', $sale->id)
            ->where('type', \App\Models\PartyLedgerEntry::TYPE_SALE)->count());
    }

    public function test_ledger_balance_nets_sale_and_return(): void
    {
        $party = Party::create(['name' => 'Kaya Ltd']);
        $demir = Product::create(['name' => 'Demir', 'type' => Product::TYPE_PRODUCT]);
        $sale = $this->saleWith($party, $demir, 100, 50, '100,00'); // borç 5000
        $sale->processReturn([['product_id' => $demir->id, 'return_qty' => 10, 'unit_price' => '100,00']], now()->toDateString()); // alacak 1000

        $borc = (float) PartyLedgerEntry::where('party_id', $party->id)->where('direction', 'borc')->sum('amount');
        $alacak = (float) PartyLedgerEntry::where('party_id', $party->id)->where('direction', 'alacak')->sum('amount');

        $this->assertSame(5000.0, $borc);
        $this->assertSame(1000.0, $alacak);
        $this->assertSame(4000.0, $borc - $alacak); // net müşteri borcu
    }
}
