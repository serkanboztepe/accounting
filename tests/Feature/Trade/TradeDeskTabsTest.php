<?php

namespace Tests\Feature\Trade;

use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Filament\Resources\Sales\Pages\ListSales;
use App\Models\Party;
use App\Models\Product;
use App\Models\User;
use App\Support\ModuleProfiles;
use App\Support\TradeDesk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Satışlar / Alışlar sekmeleri (Tümü / Peşin / Cariye) kayıt varken açılır. Canlıda 2026-10-13 07:07 Alışlar'da
 * sekmeye basınca ekran çöküyordu: sekme sorgusu `$q` adıyla yazılmıştı, Filament parametreyi ADINDAN
 * ($query) verir — boş sorgu gelip "newQueryWithoutRelationships() on null" hatası.
 */
class TradeDeskTabsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('trade'), 'app.env' => 'local']);
        $this->actingAs(User::first() ?? User::factory()->create());

        $product = Product::create(['name' => 'Sekme Un', 'type' => Product::TYPE_PRODUCT]);
        $party = Party::create(['name' => 'Sekme Cari']);
        $line = [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => '10', 'amount' => null]];
        TradeDesk::savePurchase(['mode' => 'party', 'party_id' => $party->id, 'lines' => $line]);
        TradeDesk::savePurchase(['mode' => 'cash', 'manual_amount' => '50']);
        TradeDesk::saveSale(['mode' => 'party', 'party_id' => $party->id, 'manual_amount' => '70']);
        TradeDesk::saveSale(['mode' => 'cash', 'manual_amount' => '30']);
    }

    public function test_purchase_tabs_render(): void
    {
        foreach (['all', 'party', 'cash'] as $tab) {
            Livewire::test(ListPurchases::class)->set('activeTab', $tab)->assertOk()->assertSee('Sekme', false);
        }
    }

    public function test_sale_tabs_render(): void
    {
        foreach (['all', 'party', 'cash'] as $tab) {
            Livewire::test(ListSales::class)->set('activeTab', $tab)->assertOk();
        }
    }
}
