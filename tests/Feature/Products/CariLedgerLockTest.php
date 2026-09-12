<?php

namespace Tests\Feature\Products;

use App\Filament\Resources\Parties\Pages\EditParty;
use App\Filament\Resources\Parties\RelationManagers\LedgerEntriesRelationManager;
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
 * Cari Hareketleri: Direkt Satış'tan gelen (sale_id'li) satırlar salt-okunur —
 * düzenle/sil kapalı, "Satışı Aç" açık. Elle girilen satır tam yönetilebilir.
 */
class CariLedgerLockTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local']);
    }

    public function test_sale_linked_row_is_readonly_manual_row_editable(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $party = Party::create(['name' => 'Ahmet İnşaat']);
        $product = Product::create(['name' => 'Çimento', 'type' => Product::TYPE_PRODUCT]);
        StockMovement::create(['product_id' => $product->id, 'direction' => 'in', 'reason' => 'purchase', 'quantity' => 100, 'movement_date' => now()]);

        // Otomatik satır: direkt satış
        $sale = Sale::create(['party_id' => $party->id, 'sale_date' => now(), 'total_amount' => 0]);
        $sale->rebuildFromLines([['product_id' => $product->id, 'quantity' => 10, 'unit_price' => '100,00']]);
        $saleEntry = PartyLedgerEntry::where('sale_id', $sale->id)->first();

        // Elle satır: tahsilat
        $manual = PartyLedgerEntry::create([
            'party_id' => $party->id, 'entry_date' => now(),
            'type' => PartyLedgerEntry::TYPE_COLLECTION, 'amount' => 500, 'description' => 'Nakit',
        ]);

        $component = Livewire::test(LedgerEntriesRelationManager::class, [
            'ownerRecord' => $party,
            'pageClass' => EditParty::class,
        ]);

        // Satışa bağlı satır: düzenle/sil kapalı, "Satışı Aç" açık
        $component->assertTableActionHidden('edit', $saleEntry)
            ->assertTableActionHidden('delete', $saleEntry)
            ->assertTableActionVisible('openSale', $saleEntry);

        // Elle satır: düzenle/sil açık, "Satışı Aç" kapalı
        $component->assertTableActionVisible('edit', $manual)
            ->assertTableActionVisible('delete', $manual)
            ->assertTableActionHidden('openSale', $manual);
    }
}
