<?php

namespace Tests\Feature\Products;

use App\Filament\Pages\ProjectReports;
use App\Filament\Pages\SalesSummary;
use App\Filament\Pages\StockReport;
use App\Filament\Resources\LandShareStudies\LandShareStudyResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Models\Contract;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ModuleTogglesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local']);
    }

    /** GÜVENLİK: satış sözleşmesi maliyet (scoped) sorgusuna GİRMEZ, listede (unscoped) görünür. */
    public function test_sale_contract_excluded_from_cost_scope_but_visible_in_list(): void
    {
        $project = Project::create(['name' => 'Test Proje', 'status' => 'active']);
        $party = Party::create(['name' => 'Test Cari']);

        $buy = Contract::create([
            'project_id' => $project->id, 'party_id' => $party->id, 'title' => 'Alım',
            'contract_type' => 'supply', 'direction' => Contract::DIRECTION_PURCHASE,
            'total_amount' => 1000, 'status' => 'active',
        ]);
        $sell = Contract::create([
            'project_id' => $project->id, 'party_id' => $party->id, 'title' => 'Satış',
            'contract_type' => 'supply', 'direction' => Contract::DIRECTION_SALE,
            'total_amount' => 5000, 'status' => 'active',
        ]);

        // Maliyet hesapları Contract::query() (scoped) kullanır → satış HARİÇ
        $scopedIds = Contract::where('project_id', $project->id)->pluck('id');
        $this->assertTrue($scopedIds->contains($buy->id));
        $this->assertFalse($scopedIds->contains($sell->id), 'Satış sözleşmesi maliyet scope\'una sızmamalı');

        // Liste (resource) withoutGlobalScope kullanır → ikisi de görünür
        $allIds = Contract::withoutGlobalScope('purchase')->where('project_id', $project->id)->pluck('id');
        $this->assertTrue($allIds->contains($buy->id) && $allIds->contains($sell->id));
    }

    public function test_toggles_gate_resource_access(): void
    {
        config(['modules.direct_sales' => false, 'modules.stock' => false, 'modules.land_share' => false, 'modules.quotes' => false]);
        $this->assertFalse(ProductResource::canAccess());
        $this->assertFalse(StockMovementResource::canAccess());
        $this->assertFalse(SaleResource::canAccess());
        $this->assertFalse(LandShareStudyResource::canAccess());
        $this->assertFalse(QuoteResource::canAccess());

        config(['modules.direct_sales' => true, 'modules.stock' => true, 'modules.land_share' => true, 'modules.quotes' => true]);
        $this->assertTrue(ProductResource::canAccess());
        $this->assertTrue(LandShareStudyResource::canAccess());
        $this->assertTrue(QuoteResource::canAccess());
    }

    public function test_report_toggles(): void
    {
        // Proje raporu — bağımsız bayrak
        config(['modules.report_project' => false]);
        $this->assertFalse(ProjectReports::canAccess());
        config(['modules.report_project' => true]);
        $this->assertTrue(ProjectReports::canAccess());

        // Stok raporu — hem stok modülü hem rapor bayrağı gerekli
        config(['modules.stock' => true, 'modules.report_stock' => true]);
        $this->assertTrue(StockReport::canAccess());
        config(['modules.report_stock' => false]);
        $this->assertFalse(StockReport::canAccess());
        config(['modules.stock' => false, 'modules.report_stock' => true]);
        $this->assertFalse(StockReport::canAccess(), 'Stok kapalıysa stok raporu da görünmez');

        // Satış özeti — direkt satış + rapor bayrağı
        config(['modules.direct_sales' => true, 'modules.report_sales' => true]);
        $this->assertTrue(SalesSummary::canAccess());
        config(['modules.report_sales' => false]);
        $this->assertFalse(SalesSummary::canAccess());
    }

    public function test_product_form_defaults_to_service_when_stock_off(): void
    {
        $this->actingAs(User::factory()->create());

        // Stok kapalı → tür seçimi gizli, varsayılan Hizmet, kaydedince service olur
        config(['modules.stock' => false, 'modules.direct_sales' => true]);
        \Livewire\Livewire::test(\App\Filament\Resources\Products\Pages\CreateProduct::class)
            ->assertSet('data.type', 'service')
            ->set('data.name', 'Mimarlık Hizmeti')
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertDatabaseHas('products', ['name' => 'Mimarlık Hizmeti', 'type' => 'service']);

        // Stok açık → varsayılan Ürün, seçim görünür
        config(['modules.stock' => true]);
        \Livewire\Livewire::test(\App\Filament\Resources\Products\Pages\CreateProduct::class)
            ->assertSet('data.type', 'product');
    }

    /** yildiz profili: direkt satış açık, stok kapalı → satış+katalog var, envanter ekranları yok. */
    public function test_direct_sales_without_stock(): void
    {
        config(['modules.direct_sales' => true, 'modules.stock' => false]);
        $this->assertTrue(SaleResource::canAccess(), 'Direkt satış açık olmalı');
        $this->assertTrue(ProductResource::canAccess(), 'Katalog (hizmet/ürün) açık olmalı');
        $this->assertFalse(StockMovementResource::canAccess(), 'Stok hareketleri kapalı olmalı');

        // Tersi: stok açık, satış kapalı → katalog yine görünür (stok için)
        config(['modules.direct_sales' => false, 'modules.stock' => true]);
        $this->assertFalse(SaleResource::canAccess());
        $this->assertTrue(ProductResource::canAccess());
        $this->assertTrue(StockMovementResource::canAccess());
    }

    public function test_contract_create_pages_render_for_both_directions(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Her iki yön için create sayfası hatasız açılmalı
        $this->get('/admin/contracts/create?direction=alim')->assertOk();
        $this->get('/admin/contracts/create?direction=satis')->assertOk();
    }

    public function test_sale_button_query_sets_sale_direction(): void
    {
        $this->actingAs(User::factory()->create());

        \Livewire\Livewire::withQueryParams(['direction' => 'satis'])
            ->test(\App\Filament\Resources\Contracts\Pages\CreateContract::class)
            ->assertSet('data.direction', 'satis');
    }

    public function test_default_direction_is_purchase(): void
    {
        $this->actingAs(User::factory()->create());

        \Livewire\Livewire::test(\App\Filament\Resources\Contracts\Pages\CreateContract::class)
            ->assertSet('data.direction', 'alim');
    }

    public function test_pure_sales_install_hides_purchase_button_and_defaults_to_sale(): void
    {
        $this->actingAs(User::factory()->create());
        config(['modules.purchase_contracts' => false, 'modules.sales_contracts' => true]);

        // Sadece "Satış Sözleşmesi" butonu görünür
        \Livewire\Livewire::test(\App\Filament\Resources\Contracts\Pages\ListContracts::class)
            ->assertActionHidden('createPurchase')
            ->assertActionVisible('createSale');

        // Param yoksa varsayılan yön satış olur
        \Livewire\Livewire::test(\App\Filament\Resources\Contracts\Pages\CreateContract::class)
            ->assertSet('data.direction', 'satis');
    }
}
