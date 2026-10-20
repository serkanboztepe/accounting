<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\ChatAssistant;
use App\Support\ExpenseSummary;
use App\Support\ModuleProfiles;
use App\Support\MonthlyResult;
use App\Support\PartyStatement;
use App\Support\StockReporting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * İade (kullanıcı 19.10): "yem sattım, 3 çuvalını geri aldım" / "Oktay'dan aldığım sütün 20 litresini geri verdim". İptalden ayrı:
 * asıl kayıt durur, yanına iade yazılır; yön bulunan kayıttan gelir. Satış iadesi: stok +, onun borcu −, ciro −. Alış iadesi:
 * stok −, bizim borcumuz −, alış −. "Son kaydı sil" iadeyi geri alır. Alış iptali (sil) de kişiyle çalışır.
 */
class WhatsappReturnTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905557770019';

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        // Sabit saat (öğlen): gece yarısından sonra İstanbul günü ile UTC günü ayrışıp "bugünkü iade" aralık dışında kalıyordu.
        $this->travelTo(now('Europe/Istanbul')->setTime(12, 0)->setTimezone(config('app.timezone')));
        config([
            'modules' => ModuleProfiles::resolve('trade', ['smart_assistant' => true]), 'app.profile' => 'trade',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 777 00 19'],
        ]);
        Http::fake();
        WhatsappMessage::create(['phone' => '905557770019', 'direction' => 'in', 'body' => 'önceki']);
        $this->unit = Unit::firstOrCreate(['code' => 'çuval'], ['name' => 'Çuval']);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    private function ai(array $result): void
    {
        $mock = Mockery::mock(ChatAssistant::class);
        $mock->shouldReceive('respond')->andReturn($result);
        $this->app->instance(ChatAssistant::class, $mock);
    }

    private function returnItems(array $find): void
    {
        $this->ai(['type' => 'return', 'find' => $find]);
    }

    private function product(string $name): Product
    {
        return Product::create(['name' => $name, 'type' => 'product', 'unit_id' => $this->unit->id]);
    }

    private function sale(Party $party, Product $product, float $qty, float $price, ?string $date = null): Sale
    {
        $date ??= now()->subDay()->toDateString();
        $sale = Sale::create(['party_id' => $party->id, 'sale_date' => $date, 'total_amount' => $qty * $price]);
        StockMovement::create(['product_id' => $product->id, 'direction' => StockMovement::DIRECTION_OUT, 'reason' => StockMovement::REASON_SALE,
            'quantity' => $qty, 'unit_price' => $price, 'amount' => $qty * $price, 'movement_date' => $date, 'sale_id' => $sale->id, 'party_id' => $party->id]);
        PartyLedgerEntry::create(['party_id' => $party->id, 'type' => PartyLedgerEntry::TYPE_SALE, 'amount' => $qty * $price,
            'entry_date' => $date, 'description' => 'Satış', 'sale_id' => $sale->id]);

        return $sale;
    }

    private function purchase(Party $party, Product $product, float $qty, float $price, bool $paid = false): Purchase
    {
        $purchase = Purchase::create(['party_id' => $party->id, 'purchase_date' => now()->subDay()->toDateString(), 'paid' => $paid]);
        $purchase->rebuildFromLines([['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => $price]]);

        return $purchase->fresh();
    }

    private function monthPurchases(): float
    {
        return MonthlyResult::forRange(now()->subDays(3), now())['purchases'];
    }

    public function test_sale_return_puts_stock_back_and_lowers_debt_and_revenue(): void
    {
        $ahmet = Party::create(['name' => 'Testahmet Yemci']);
        $yem = $this->product('Testyem');
        $sale = $this->sale($ahmet, $yem, 10, 1000);
        $revenueBefore = MonthlyResult::forRange(now()->subDays(3), now())['revenue'];
        $this->returnItems(['party' => 'Testahmet', 'text' => 'yem', 'side' => 'sale', 'quantity' => 3]);

        $card = $this->send("Testahmet'e sattığım yemin 3 çuvalını geri aldım");
        $this->assertStringContainsString('Satış iadesi', $card);
        $this->assertStringContainsString('3 çuval Testyem — 3.000,00 ₺', $card);
        $this->assertStringContainsString('sana borcu 7.000,00', $card);
        $this->assertSame(0, SaleReturn::where('sale_id', $sale->id)->count(), 'onaysız yazılmaz');

        $this->assertStringContainsString('İade kaydedildi', $this->send('evet'));
        $this->assertNotNull(Sale::find($sale->id), 'satış silinmez');
        $this->assertEqualsWithDelta(3000, (float) SaleReturn::where('sale_id', $sale->id)->sum('total_amount'), 0.01);
        $this->assertEqualsWithDelta(-7, $yem->fresh()->currentStock(), 0.01);
        $this->assertEqualsWithDelta(7000, PartyStatement::build($ahmet)['balance'], 0.01);
        $this->assertEqualsWithDelta($revenueBefore - 3000, MonthlyResult::forRange(now()->subDays(3), now())['revenue'], 0.01);
    }

    public function test_purchase_return_takes_stock_out_and_lowers_our_debt_and_purchases(): void
    {
        $oktay = Party::create(['name' => 'Testoktay Çiftlik']);
        $sut = $this->product('Testsüt');
        $purchase = $this->purchase($oktay, $sut, 100, 30);
        $purchasesBefore = $this->monthPurchases();
        $salesSummaryBefore = collect(StockReporting::salesSummary())->firstWhere('id', $oktay->id);
        $this->returnItems(['party' => 'Testoktay', 'text' => 'süt', 'side' => 'purchase', 'quantity' => 20]);

        $card = $this->send("Testoktay'dan aldığım sütün 20 litresini geri verdim");
        $this->assertStringContainsString('Alış iadesi', $card);
        $this->assertStringContainsString('600,00 ₺', $card);
        $this->assertStringContainsString('borcun 2.400,00', $card);

        $this->send('evet');
        $this->assertNotNull(Purchase::find($purchase->id), 'alış silinmez');
        $this->assertSame(1, PurchaseReturn::where('purchase_id', $purchase->id)->count());
        $this->assertEqualsWithDelta(80, $sut->fresh()->currentStock(), 0.01);
        $this->assertEqualsWithDelta(-2400, PartyStatement::build($oktay)['balance'], 0.01);
        $this->assertEqualsWithDelta($purchasesBefore - 600, $this->monthPurchases(), 0.01);
        $this->assertEquals($salesSummaryBefore, collect(StockReporting::salesSummary())->firstWhere('id', $oktay->id), 'alış iadesi satış raporuna girmez');
    }

    public function test_simple_ledger_purchase_return_lowers_purchases(): void
    {
        $kaya = Party::create(['name' => 'Testkaya Nalbur']);
        PartyLedgerEntry::create(['party_id' => $kaya->id, 'type' => PartyLedgerEntry::TYPE_PURCHASE, 'amount' => 5000,
            'entry_date' => now()->subDay()->toDateString(), 'description' => 'Boya']);
        $purchasesBefore = $this->monthPurchases();
        $summaryBefore = ExpenseSummary::build(null, null)['purchases'];
        $this->returnItems(['party' => 'Testkaya', 'side' => 'purchase', 'amount' => 1200]);

        $this->assertStringContainsString('1.200,00 ₺', $this->send('Testkaya nalburdan aldığım boyanın 1200 liralığını geri verdim'));
        $this->send('evet');
        $this->assertEqualsWithDelta(-3800, PartyStatement::build($kaya)['balance'], 0.01);
        $this->assertEqualsWithDelta($purchasesBefore - 1200, $this->monthPurchases(), 0.01);
        $this->assertEqualsWithDelta($summaryBefore - 1200, ExpenseSummary::build(null, null)['purchases'], 0.01);
    }

    public function test_simple_ledger_sale_return_lowers_revenue(): void
    {
        $veli = Party::create(['name' => 'Testveli Market']);
        PartyLedgerEntry::create(['party_id' => $veli->id, 'type' => PartyLedgerEntry::TYPE_SALE, 'amount' => 4000,
            'entry_date' => now()->subDay()->toDateString(), 'description' => 'Peynir']);
        $before = MonthlyResult::forRange(now()->subDays(3), now())['revenue'];
        $this->returnItems(['party' => 'Testveli', 'side' => 'sale']);

        $this->assertStringContainsString('4.000,00 ₺', $this->send('Testveli peynirin hepsini geri getirdi'));
        $this->send('evet');
        $this->assertEqualsWithDelta(0, PartyStatement::build($veli)['balance'], 0.01);
        $this->assertEqualsWithDelta($before - 4000, MonthlyResult::forRange(now()->subDays(3), now())['revenue'], 0.01);
    }

    public function test_party_with_both_sale_and_purchase_asks_which_when_side_unknown(): void
    {
        $oktay = Party::create(['name' => 'Testoktay İkiyön']);
        $this->sale($oktay, $this->product('Testekmek'), 10, 20);
        $this->purchase($oktay, $this->product('Testsüt2'), 50, 30);
        $this->returnItems(['party' => 'Testoktay İkiyön']);

        $out = $this->send('Testoktay iade');
        $this->assertStringContainsString('hangisinden iade', $out);
        $this->assertSame(0, SaleReturn::where('party_id', $oktay->id)->count() + PurchaseReturn::where('party_id', $oktay->id)->count());
    }

    public function test_more_than_sold_is_refused(): void
    {
        $ahmet = Party::create(['name' => 'Testahmet Fazla']);
        $this->sale($ahmet, $this->product('Testyem3'), 10, 1000);
        $this->returnItems(['party' => 'Testahmet Fazla', 'side' => 'sale', 'quantity' => 12]);

        $this->assertStringContainsString('en çok 10 çuval', $this->send('12 çuval geri geldi'));
    }

    public function test_money_back_keeps_balance(): void
    {
        $oktay = Party::create(['name' => 'Testoktay Peşin']);
        $sut = $this->product('Testsüt3');
        $this->purchase($oktay, $sut, 100, 30, paid: true);
        $this->returnItems(['party' => 'Testoktay Peşin', 'side' => 'purchase', 'quantity' => 20, 'money_back' => true]);

        $this->assertStringContainsString('bakiye değişmez', $this->send('20 litre geri verdim parasını aldım'));
        $this->send('evet');
        $this->assertEqualsWithDelta(0, PartyStatement::build($oktay)['balance'], 0.01);
        $this->assertEqualsWithDelta(80, $sut->fresh()->currentStock(), 0.01);
    }

    public function test_undo_last_removes_the_return(): void
    {
        $ahmet = Party::create(['name' => 'Testahmet Geri']);
        $yem = $this->product('Testyem4');
        $sale = $this->sale($ahmet, $yem, 10, 1000);
        $this->returnItems(['party' => 'Testahmet Geri', 'side' => 'sale', 'quantity' => 3]);
        $this->send('3 çuval geri geldi');
        $this->send('evet');

        $this->ai(['type' => 'undo', 'replacement' => null, 'find' => null]);
        $this->assertStringContainsString('Şu kaydı sileceğim', $this->send('son kaydı sil'));
        $this->send('evet');
        $this->assertSame(0, SaleReturn::where('sale_id', $sale->id)->count());
        $this->assertEqualsWithDelta(-10, $yem->fresh()->currentStock(), 0.01);
        $this->assertEqualsWithDelta(10000, PartyStatement::build($ahmet)['balance'], 0.01);
        $this->assertNotNull(Sale::find($sale->id));
    }

    public function test_cancelling_a_purchase_by_party_deletes_it_with_its_rows(): void
    {
        $oktay = Party::create(['name' => 'Testoktay İptal']);
        $sut = $this->product('Testsüt5');
        $purchase = $this->purchase($oktay, $sut, 50, 30);
        $this->ai(['type' => 'undo', 'replacement' => null, 'find' => ['party' => 'Testoktay İptal', 'latest' => true]]);

        $card = $this->send("Testoktay'dan aldığım son alışı iptal et");
        $this->assertStringContainsString('Alış — Testoktay İptal', $card);
        $this->send('evet');
        $this->assertNull(Purchase::find($purchase->id));
        $this->assertEqualsWithDelta(0, $sut->fresh()->currentStock(), 0.01);
        $this->assertEqualsWithDelta(0, PartyStatement::build($oktay)['balance'], 0.01);
    }
}
