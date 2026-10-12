<?php

namespace Tests\Feature\Trade;

use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use App\Models\User;
use App\Support\PartyStatement;
use App\Support\StockUsage;
use App\Support\TradeDesk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Alışlar tezgahı: cariden alış → stok + cari borç; peşin alış → stok + alış + ödeme (bakiye değişmez);
 * carisiz peşin → yalnız stok. Ekstrede belge satırları kilitli. Modül kapalıyken sayfa yok.
 */
class PurchaseDeskTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local', 'modules.stock' => true, 'modules.purchases' => true]);
    }

    private function flour(): Product
    {
        return Product::create(['name' => 'Un', 'type' => Product::TYPE_PRODUCT]);
    }

    public function test_purchase_from_party_adds_stock_and_debt(): void
    {
        $p = $this->flour();
        $party = Party::create(['name' => 'Ak Un']);

        $purchase = TradeDesk::savePurchase([
            'mode' => 'party', 'party_id' => $party->id,
            'lines' => [['product_id' => $p->id, 'quantity' => 20, 'unit_price' => '850,00']],
        ]);

        $this->assertSame('17000.00', $purchase->total_amount);
        $this->assertSame(20.0, $p->currentStock());
        $this->assertSame(-17000.0, PartyStatement::build($party)['balance'], 'Biz borçlanırız');
        // "Bitmek üzere" mesajındaki "son alış" bu cariyi bulur.
        $p->update(['reorder_level' => 25]);
        $this->assertSame('Ak Un', StockUsage::newlyLow(false)->first()['supplier']);
    }

    public function test_cash_purchase_with_party_writes_purchase_and_payment_balance_unchanged(): void
    {
        $p = $this->flour();
        $party = Party::create(['name' => 'Ak Un']);

        $purchase = TradeDesk::savePurchase([
            'mode' => 'cash', 'party_id' => $party->id, 'payment_type' => 'cash',
            'lines' => [['product_id' => $p->id, 'quantity' => 10, 'amount' => '8.500,00']],
        ]);

        $types = $purchase->ledgerEntries()->pluck('type')->sort()->values()->all();
        $this->assertSame([PartyLedgerEntry::TYPE_PURCHASE, PartyLedgerEntry::TYPE_PAYMENT], $types);
        $this->assertSame(0.0, PartyStatement::build($party)['balance']);
        $this->assertSame(10.0, $p->currentStock());
    }

    public function test_cash_purchase_without_party_only_stock(): void
    {
        $p = $this->flour();
        $before = PartyLedgerEntry::count();

        TradeDesk::savePurchase(['mode' => 'cash', 'lines' => [['product_id' => $p->id, 'quantity' => 3, 'amount' => '300']]]);

        $this->assertSame(3.0, $p->currentStock());
        $this->assertSame($before, PartyLedgerEntry::count());
    }

    public function test_deleting_purchase_reverts_stock_and_ledger(): void
    {
        $p = $this->flour();
        $party = Party::create(['name' => 'Ak Un']);
        $purchase = TradeDesk::savePurchase(['mode' => 'party', 'party_id' => $party->id, 'lines' => [['product_id' => $p->id, 'quantity' => 5, 'amount' => '500']]]);

        $purchase->delete();

        $this->assertSame(0.0, $p->currentStock());
        $this->assertSame(0.0, PartyStatement::build($party)['balance']);
    }

    public function test_statement_row_from_purchase_is_locked_and_links_document(): void
    {
        $party = Party::create(['name' => 'Ak Un']);
        $purchase = TradeDesk::savePurchase(['mode' => 'party', 'party_id' => $party->id, 'manual_amount' => '1.000,00']);

        $row = collect(PartyStatement::build($party)['rows'])->firstWhere('purchase_id', $purchase->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['editable']);
    }

    public function test_simple_ledger_purchase_still_editable(): void
    {
        // Koruma: caride basit "Alış Yaptım" satırı eskisi gibi düzenlenebilir.
        $party = Party::create(['name' => 'Usta']);
        $party->ledgerEntries()->create(['type' => PartyLedgerEntry::TYPE_PURCHASE, 'amount' => 500, 'entry_date' => now()]);

        $row = PartyStatement::build($party)['rows'][0];
        $this->assertTrue($row['editable']);
        $this->assertNull($row['purchase_id']);
    }

    public function test_desk_page_saves(): void
    {
        $this->actingAs(User::factory()->create());
        $party = Party::create(['name' => 'Ak Un']);

        Livewire::test(ListPurchases::class)
            ->assertSee('Yeni alış')
            ->set('deskData.mode', 'party')
            ->set('deskData.party_id', $party->id)
            ->set('deskData.manual_amount', '2.000,00')
            ->call('saveDesk')
            ->assertHasNoErrors()
            ->assertSet('deskData.party_id', null)
            ->assertSee('Ak Un');

        $this->assertSame(-2000.0, PartyStatement::build($party)['balance']);
    }

    public function test_page_closed_when_module_off(): void
    {
        config(['modules.purchases' => false]);
        $this->assertFalse(PurchaseResource::canAccess());
    }
}
