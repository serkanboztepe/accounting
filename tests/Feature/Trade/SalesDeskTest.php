<?php

namespace Tests\Feature\Trade;

use App\Filament\Resources\Sales\Pages\ListSales;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\TradeDesk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Satışlar tezgahı: peşin (carisiz) satış cariye yazılmaz, cariye satış borç yazar, ürünsüz tutar (toplu ciro),
 * nakit/kart dağılımı. Modül kapalı firmada eski liste aynen (Yeni Yapı korunur).
 */
class SalesDeskTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local', 'modules.stock' => true, 'modules.direct_sales' => true, 'modules.sales_desk' => true, 'modules.projects' => false]); // şubesiz firma (şube kuralı ayrı testte)
    }

    private function bread(float $stock = 100): Product
    {
        $p = Product::create(['name' => 'Ekmek', 'type' => Product::TYPE_PRODUCT, 'default_price' => 10]);
        StockMovement::create(['product_id' => $p->id, 'direction' => 'in', 'reason' => 'purchase', 'quantity' => $stock, 'movement_date' => now()]);

        return $p;
    }

    public function test_cash_sale_without_party_touches_stock_but_not_ledger(): void
    {
        $p = $this->bread();
        $ledgerBefore = PartyLedgerEntry::count();

        $sale = TradeDesk::saveSale([
            'mode' => 'cash', 'payment' => 'cash', 'sale_date' => now()->toDateString(),
            'lines' => [['product_id' => $p->id, 'quantity' => 5, 'unit_price' => '10,00', 'amount' => '50,00']],
        ]);

        $this->assertNull($sale->party_id);
        $this->assertSame('50.00', $sale->total_amount);
        $this->assertSame('50.00', $sale->cash_amount);
        $this->assertSame(95.0, $p->currentStock());
        $this->assertSame($ledgerBefore, PartyLedgerEntry::count(), 'Peşin satış cariye yazılmamalı');
    }

    public function test_productless_daily_revenue_with_cash_and_card_split(): void
    {
        $sale = TradeDesk::saveSale([
            'mode' => 'cash', 'payment' => 'mixed', 'cash_part' => '10.000,00',
            'manual_amount' => '25.000,00', 'notes' => 'Günün cirosu',
        ]);

        $this->assertSame('25000.00', $sale->total_amount);
        $this->assertSame('10000.00', $sale->cash_amount);
        $this->assertSame('15000.00', $sale->bank_amount);
        $this->assertSame('Nakit + Kart', $sale->paymentLabel());
        $this->assertSame(0, $sale->lines()->count());
    }

    public function test_party_sale_writes_debt_and_requires_party(): void
    {
        $party = Party::create(['name' => 'Lokanta']);
        $sale = TradeDesk::saveSale(['mode' => 'party', 'party_id' => $party->id, 'manual_amount' => '1.200,00']);

        $this->assertSame('1200.00', $sale->ledgerEntry->amount);
        $this->assertSame(PartyLedgerEntry::TYPE_SALE, $sale->ledgerEntry->type);
        $this->assertNull($sale->cash_amount, 'Cariye satışta nakit/kart dağılımı tutulmaz');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        TradeDesk::saveSale(['mode' => 'party', 'manual_amount' => '100']);
    }

    public function test_empty_sale_is_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        TradeDesk::saveSale(['mode' => 'cash', 'lines' => []]);
    }

    public function test_cash_sale_return_restores_stock_without_ledger(): void
    {
        $p = $this->bread();
        $sale = TradeDesk::saveSale(['mode' => 'cash', 'lines' => [['product_id' => $p->id, 'quantity' => 4, 'unit_price' => '10']]]);
        $ledgerBefore = PartyLedgerEntry::count();

        $sale->processReturn([['product_id' => $p->id, 'return_qty' => 1, 'unit_price' => '10']], now()->toDateString());

        $this->assertSame(97.0, $p->currentStock());
        $this->assertSame($ledgerBefore, PartyLedgerEntry::count());
    }

    public function test_desk_page_saves_and_clears_form_keeping_mode(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ListSales::class)
            ->assertSee('Yeni satış')
            ->assertSee('Peşin')
            ->set('deskData.mode', 'cash')
            ->set('deskData.payment', 'card')
            ->set('deskData.manual_amount', '7.450,00')
            ->call('saveDesk')
            ->assertHasNoErrors()
            ->assertSet('deskData.manual_amount', null)
            ->assertSet('deskData.payment', 'card')
            ->assertSee('Peşin müşteri');

        $sale = Sale::latest('id')->first();
        $this->assertSame('7450.00', $sale->total_amount);
        $this->assertSame('7450.00', $sale->bank_amount);
    }

    public function test_desk_hidden_when_module_off_old_list_unchanged(): void
    {
        config(['modules.sales_desk' => false]);
        $this->actingAs(User::factory()->create());

        Livewire::test(ListSales::class)
            ->assertDontSee('Yeni satış')
            ->assertSee('Yeni Satış');   // eski "Yeni Satış" butonu

        $this->assertFalse(\App\Filament\Resources\Sales\SaleResource::shouldRegisterNavigation());
    }

    /** Şubeli firmada (Akkuş) peşin kasa şubesiz kaydedilmez; seçilen şube satışa yazılır ve sonraki girişte hatırlanır. */
    public function test_desk_requires_branch_for_cash_sale_when_branches_exist(): void
    {
        config(['modules.projects' => true, 'app.profile' => 'trade']);
        $this->actingAs(User::factory()->create());
        \App\Models\Project::query()->update(['status' => 'cancelled']);
        $a = \App\Models\Project::create(['name' => 'Ergenekon Masa', 'status' => 'active']);
        \App\Models\Project::create(['name' => 'İzzetpaşa Masa', 'status' => 'active']);
        $before = Sale::count();

        Livewire::test(ListSales::class)
            ->assertSee('Şube')
            ->set('deskData.mode', 'cash')
            ->set('deskData.manual_amount', '1.000,00')
            ->call('saveDesk')
            ->assertHasErrors(['deskData.project_id'])
            ->set('deskData.project_id', $a->id)
            ->call('saveDesk')
            ->assertHasNoErrors()
            ->assertSet('deskData.project_id', $a->id);

        $this->assertSame($before + 1, Sale::count());
        $this->assertSame($a->id, Sale::latest('id')->value('project_id'));
    }
}
