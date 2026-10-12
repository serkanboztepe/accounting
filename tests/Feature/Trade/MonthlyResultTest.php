<?php

namespace Tests\Feature\Trade;

use App\Filament\Pages\MonthlyResultReport;
use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\MonthlyResult;
use App\Support\TradeDesk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Ay Sonucu: her kaynak bir kez sayılır, ödeme/tahsilat sayılmaz. Örnek ay elle tutturulur. */
class MonthlyResultTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local', 'modules.stock' => true, 'modules.expenses' => true, 'modules.report_monthly' => true]);
        // Testin ayı boş başlasın (geliştirme veritabanında kayıt olabilir).
        Carbon::setTestNow('2031-03-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sample_month_adds_up_without_double_counting(): void
    {
        $flour = Product::create(['name' => 'Un', 'type' => Product::TYPE_PRODUCT]);
        $mill = Party::create(['name' => 'Ak Un']);
        $restaurant = Party::create(['name' => 'Lokanta']);

        // Ciro: peşin toplu 25.000 (10 nakit + 15 kart) + lokantaya veresiye 3.000 + caride basit satış 2.000 − iade 500
        TradeDesk::saveSale(['mode' => 'cash', 'payment' => 'mixed', 'cash_part' => '10000', 'manual_amount' => '25000']);
        $credit = TradeDesk::saveSale(['mode' => 'party', 'party_id' => $restaurant->id, 'manual_amount' => '3000']);
        $restaurant->ledgerEntries()->create(['type' => PartyLedgerEntry::TYPE_SALE, 'amount' => 2000, 'entry_date' => now()]);
        $credit->saleReturns()->create(['party_id' => $restaurant->id, 'return_date' => now(), 'total_amount' => 500]);

        // Alış: Alışlar 17.000 (peşin → alış + ödeme, ödeme sayılmaz) + caride basit alış 1.000 + Mal Girişi 400 + fiyatsız giriş
        TradeDesk::savePurchase(['mode' => 'cash', 'party_id' => $mill->id, 'lines' => [['product_id' => $flour->id, 'quantity' => 20, 'amount' => '17000']]]);
        $mill->ledgerEntries()->create(['type' => PartyLedgerEntry::TYPE_PURCHASE, 'amount' => 1000, 'entry_date' => now()]);
        StockMovement::create(['product_id' => $flour->id, 'direction' => 'in', 'reason' => 'purchase', 'quantity' => 1, 'amount' => 400, 'movement_date' => now()]);
        StockMovement::create(['product_id' => $flour->id, 'direction' => 'in', 'reason' => 'purchase', 'quantity' => 1, 'movement_date' => now()]);

        // Gider 2.500; tahsilat ve ödeme SAYILMAZ
        Expense::create(['expense_date' => now(), 'amount' => 2500, 'payment_status' => 'paid', 'description' => 'Kira']);
        $restaurant->ledgerEntries()->create(['type' => PartyLedgerEntry::TYPE_COLLECTION, 'amount' => 3000, 'entry_date' => now()]);
        $mill->ledgerEntries()->create(['type' => PartyLedgerEntry::TYPE_PAYMENT, 'amount' => 1000, 'entry_date' => now()]);

        $r = MonthlyResult::forMonth(now());

        $this->assertEqualsWithDelta(29500.0, $r['revenue'], 0.01);   // 25000 + 3000 + 2000 − 500
        $this->assertEqualsWithDelta(10000.0, $r['cash'], 0.01);
        $this->assertEqualsWithDelta(15000.0, $r['bank'], 0.01);
        $this->assertEqualsWithDelta(18400.0, $r['purchases'], 0.01); // 17000 + 1000 + 400
        $this->assertSame(1, $r['unpriced_goods']);
        $this->assertEqualsWithDelta(2500.0, $r['expenses'], 0.01);
        $this->assertEqualsWithDelta(8600.0, $r['result'], 0.01);     // 29500 − 18400 − 2500
        $this->assertStringContainsString('Kalan: 8.600,00', MonthlyResult::whatsappText($r));
    }

    public function test_other_months_not_included(): void
    {
        TradeDesk::saveSale(['mode' => 'cash', 'manual_amount' => '1000', 'sale_date' => '2031-02-28']);
        $this->assertSame(0.0, MonthlyResult::forMonth(now())['revenue']);
        $this->assertSame(1000.0, MonthlyResult::forMonth(now()->subMonth())['revenue']);
    }

    public function test_page_renders_and_is_gated(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/ay-sonucu')->assertOk()->assertSee('Ay Sonucu')->assertSee('Son 12 ay');

        config(['modules.report_monthly' => false]);
        $this->assertFalse(MonthlyResultReport::canAccess());
    }
}
