<?php

namespace Tests\Feature\Features;

use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use App\Models\Project;
use App\Models\StockMovement;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\ProjectChoice;
use App\Services\Whatsapp\TradeDraft;
use App\Support\MonthlyResult;
use App\Support\TradeDesk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Çiftlik (plan 26.10, Faz C ilk bölüm): "Alışı yere yaz" — ürünlü alış da seçilen yere yazılır (yem → ahır), yer
 * sorulur; yerin sezon sonucu genel gideri dağıtmaz. Kapalıyken fırın düzeni birebir aynı (pozitif kontrol).
 */
class PlaceCostTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local', 'modules.stock' => true, 'modules.purchases' => true, 'modules.direct_sales' => true,
            'modules.sales_desk' => true, 'modules.projects' => true, 'modules.expenses' => true, 'modules.place_cost' => false]);
    }

    private function farm(): void
    {
        config(['modules.place_cost' => true]);
    }

    private function feed(): Product
    {
        return Product::create(['name' => 'Besi yemi', 'type' => Product::TYPE_PRODUCT]);
    }

    private function buyFeed(Project $place, Product $feed, string $date = null): \App\Models\Purchase
    {
        return TradeDesk::savePurchase([
            'mode' => 'party', 'party_id' => Party::firstOrCreate(['name' => 'Yemci Ali'])->id, 'project_id' => $place->id,
            'purchase_date' => $date ?? now()->toDateString(),
            'lines' => [['product_id' => $feed->id, 'quantity' => 10, 'unit_price' => '1.000,00']],
        ]);
    }

    public function test_bakery_stocked_purchase_stays_general(): void
    {
        $branch = Project::create(['name' => 'Ergenekon']);
        $purchase = $this->buyFeed($branch, $this->feed());

        $this->assertNull($purchase->project_id, 'fırın: ürünlü alış şubeye yazılmaz (16.10)');
        $this->assertNull(PartyLedgerEntry::where('purchase_id', $purchase->id)->value('project_id'));
    }

    public function test_farm_stocked_purchase_goes_to_place_and_stock(): void
    {
        $this->farm();
        $barn = Project::create(['name' => 'Ahır · 2026 besi']);
        $feed = $this->feed();
        $purchase = $this->buyFeed($barn, $feed);

        $this->assertSame($barn->id, $purchase->project_id);
        $this->assertSame($barn->id, PartyLedgerEntry::where('purchase_id', $purchase->id)->value('project_id'), 'cari satırı da ahırın');
        $this->assertSame($barn->id, StockMovement::where('purchase_id', $purchase->id)->value('project_id'));
        $this->assertSame(10.0, $feed->currentStock(), 'stoğa da girer');
    }

    public function test_whatsapp_asks_place_for_stocked_purchase_and_production_only_on_farm(): void
    {
        $feed = $this->feed();
        $draft = ['kind' => ExpenseExtractor::KIND_TRADE_PURCHASE, 'items' => [['product_id' => $feed->id]], 'project_name' => 'Ahır'];
        $production = ['kind' => ExpenseExtractor::KIND_PRODUCTION, 'items' => [['product_id' => $feed->id]]];

        $this->assertFalse(ProjectChoice::applies($draft), 'fırın: stoklu alışta yer sorulmaz');
        $this->assertNull(ProjectChoice::prepare($draft, 'whatsapp:+905550000002')['project_name'], 'fırın: söylenen şube silinir');
        $this->assertFalse(ProjectChoice::applies($production));

        $this->farm();
        $this->assertTrue(ProjectChoice::applies($draft), 'çiftlik: yer sorulur');
        $this->assertSame('Ahır', ProjectChoice::prepare($draft, 'whatsapp:+905550000002')['project_name'], 'çiftlik: söylenen yer kalır');
        $this->assertTrue(ProjectChoice::applies($production), 'çiftlik: üretimde de yer sorulur');
    }

    public function test_route_keeps_said_place_on_farm(): void
    {
        $feed = $this->feed();
        $barn = Project::create(['name' => 'Ahır']);
        $said = ['kind' => ExpenseExtractor::KIND_PURCHASE, 'items' => [['product_id' => $feed->id, 'description' => 'Besi yemi']],
            'project_id' => $barn->id, 'amount' => 10000];

        $this->assertNull(TradeDraft::route($said)['project_id'] ?? null, 'fırın: silinir');
        $this->farm();
        $this->assertSame($barn->id, TradeDraft::route($said)['project_id'], 'çiftlik: kalır');
    }

    public function test_season_result_counts_only_the_place_and_shows_general_apart(): void
    {
        $this->farm();
        $start = Carbon::parse('2026-03-01');
        $field = Project::create(['name' => 'Tarla 1 · 2026', 'start_date' => '2026-03-10']);
        $other = Project::create(['name' => 'Tarla 2 · 2026', 'start_date' => '2026-03-01']);
        $feed = $this->feed();
        $wheat = Product::create(['name' => 'Buğday', 'type' => Product::TYPE_PRODUCT]);

        // Sezon başından ÖNCE tarihli ama bu yere yazılmış alış da sayılır (yer sonradan açılmış).
        $this->buyFeed($field, $feed, '2026-03-05');                                    // 10.000 alış
        Expense::create(['project_id' => $field->id, 'expense_date' => '2026-04-01', 'amount' => 2000, 'payment_status' => 'paid', 'description' => 'mazot']);
        TradeDesk::saveSale(['mode' => 'cash', 'project_id' => $field->id, 'sale_date' => '2026-07-01', 'manual_amount' => '30.000,00']);
        StockMovement::create(['product_id' => $wheat->id, 'direction' => StockMovement::DIRECTION_IN, 'reason' => StockMovement::REASON_PRODUCTION,
            'quantity' => 12, 'project_id' => $field->id, 'movement_date' => '2026-07-01']);
        // Başka yer ve genel: bu sonuca girmez.
        $this->buyFeed($other, $feed, '2026-04-01');
        Expense::create(['expense_date' => '2026-05-01', 'amount' => 700, 'payment_status' => 'paid', 'description' => 'traktör sigortası']);

        $field->update(['end_date' => '2026-09-30', 'status' => 'completed']);
        $r = MonthlyResult::forPlace($field->fresh());

        $this->assertEquals(30000.0, $r['revenue']);
        $this->assertEquals(10000.0, $r['purchases']);
        $this->assertEquals(2000.0, $r['expenses']);
        $this->assertEquals(18000.0, $r['result']);
        $this->assertGreaterThanOrEqual(700.0, $r['general_unallocated'], 'genel gider ayrı, dağıtılmaz (deneme DB\'sinde başka genel gider de olabilir)');
        $this->assertTrue($r['closed']);
        $this->assertSame('Buğday', $r['production'][0]['name']);
        $this->assertEquals(12.0, $r['production'][0]['quantity']);

        $text = MonthlyResult::placeText($r);
        $this->assertStringContainsString('Tarla 1 · 2026 — sezon sonucu', $text);
        $this->assertStringContainsString('5 Mart 2026', $text, 'sezon ilk kayıttan başlar');
        $this->assertStringContainsString('Kalan: 18.000,00', $text);
        $this->assertStringContainsString('(bu sonuca dahil değil)', $text);
        $this->assertStringContainsString('12 Buğday', $text);
    }

    public function test_place_range_result_does_not_allocate_general_cost_on_farm_but_does_on_bakery(): void
    {
        $place = Project::create(['name' => 'Ahır']);
        TradeDesk::saveSale(['mode' => 'cash', 'project_id' => $place->id, 'sale_date' => now()->toDateString(), 'manual_amount' => '1.000,00']);
        Expense::create(['expense_date' => now()->toDateString(), 'amount' => 400, 'payment_status' => 'paid', 'description' => 'genel']);
        $from = now()->startOfMonth();
        $to = now()->endOfMonth();

        $bakery = MonthlyResult::forRange($from, $to, $place->id);
        // Pay = bu yerin ciro payı (deneme veritabanında başka satış da olabilir) — önemli olan dağıtılması.
        $this->assertGreaterThan(0.0, $bakery['allocated'], 'fırın: genel gider ciro payıyla dağıtılır (16.10)');
        $this->assertEquals($bakery['result'] + $bakery['allocated'], 1000.0);

        $this->farm();
        $farm = MonthlyResult::forRange($from, $to, $place->id);
        $this->assertArrayNotHasKey('allocated', $farm);
        $this->assertGreaterThanOrEqual(400.0, $farm['general_unallocated'], 'genel gider ayrı bilgi');
        $this->assertEquals(1000.0, $farm['result'], 'çiftlik: yerin sonucu yalnız kendi kayıtları');
    }

    public function test_whatsapp_place_question_without_period_gets_season(): void
    {
        $this->farm();
        $field = Project::create(['name' => 'Tarla 1']);
        TradeDesk::saveSale(['mode' => 'cash', 'project_id' => $field->id, 'sale_date' => now()->toDateString(), 'manual_amount' => '5.000,00']);

        $text = TradeDraft::answer(['kind' => ExpenseExtractor::KIND_MONTH_RESULT, 'project_id' => $field->id]);
        $this->assertStringContainsString('Tarla 1 — sezon sonucu', $text);

        $withMonth = TradeDraft::answer(['kind' => ExpenseExtractor::KIND_MONTH_RESULT, 'project_id' => $field->id, 'month' => now()->format('Y-m')]);
        $this->assertStringNotContainsString('sezon sonucu', $withMonth, 'ay söylendiyse ay sonucu');
    }
}
