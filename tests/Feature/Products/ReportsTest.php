<?php

namespace Tests\Feature\Products;

use App\Models\Party;
use App\Models\Product;
use App\Models\Project;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\StockReporting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * ⑥ Raporlar + çıktı — Stok Raporu (A), Satış Özeti (B), Yazdırma (C).
 */
class ReportsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local']);
    }

    private function scenario(): array
    {
        $party = Party::create(['name' => 'Ahmet İnşaat']);
        $project = Project::create(['name' => 'Yıldız Sitesi', 'party_id' => $party->id, 'status' => 'active']);
        $cimento = Product::create(['name' => 'Çimento 50kg', 'type' => Product::TYPE_PRODUCT]);

        // Açılış 200
        StockMovement::create(['product_id' => $cimento->id, 'direction' => 'in', 'reason' => 'purchase', 'quantity' => 200, 'movement_date' => now()]);

        // Sat 100 @ 10 → borç 1000, stok 100
        $sale = Sale::create(['party_id' => $party->id, 'project_id' => $project->id, 'sale_date' => now(), 'total_amount' => 0]);
        $sale->rebuildFromLines([['product_id' => $cimento->id, 'quantity' => 100, 'unit_price' => '10,00']]);

        // 20 iade → stok 120, iade tutarı 200
        $sale->processReturn([['product_id' => $cimento->id, 'return_qty' => 20, 'unit_price' => '10,00']], now()->toDateString());

        return compact('party', 'project', 'cimento', 'sale');
    }

    public function test_stock_rows_report(): void
    {
        ['cimento' => $cimento] = $this->scenario();

        $rows = collect(StockReporting::stockRows())->firstWhere('id', $cimento->id);

        $this->assertSame(220.0, $rows['in']);   // 200 açılış + 20 iade
        $this->assertSame(100.0, $rows['out']);  // 100 satış
        $this->assertSame(120.0, $rows['current']);
    }

    public function test_sales_summary_party_nets_returns(): void
    {
        ['party' => $party] = $this->scenario();

        $row = collect(StockReporting::salesSummary('party'))->firstWhere('id', $party->id);

        $this->assertSame(1000.0, $row['gross']); // 100 × 10
        $this->assertSame(200.0, $row['ret']);    // 20 × 10
        $this->assertSame(800.0, $row['net']);
    }

    public function test_sales_summary_project_grouping(): void
    {
        ['project' => $project] = $this->scenario();

        $row = collect(StockReporting::salesSummary('project'))->firstWhere('id', $project->id);

        $this->assertSame(800.0, $row['net']);
    }

    public function test_report_pages_and_prints_render(): void
    {
        $user = User::factory()->create();
        ['sale' => $sale] = $this->scenario();

        $this->actingAs($user)->get('/admin/stock-report')->assertOk()->assertSee('Stok Raporu');
        $this->actingAs($user)->get('/admin/sales-summary')->assertOk()->assertSee('Satış Özeti');

        $this->actingAs($user)->get(route('stock-report.print'))->assertOk()->assertSee('STOK RAPORU');
        $this->actingAs($user)->get(route('sales-summary.print', ['group' => 'party']))->assertOk()->assertSee('SATIŞ ÖZETİ');
        $this->actingAs($user)->get(route('sale.print', $sale))->assertOk()
            ->assertSee('SATIŞ FİŞİ')
            ->assertSee('İadeler')
            ->assertSee('Net:');
    }
}
