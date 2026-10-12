<?php

namespace Tests\Feature\Trade;

use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * "Kaydetme kaymasın" (kullanıcı, 2026-10-12): WhatsApp mesajı modüle göre DOĞRU tabloya gider.
 * Matris: firma paketi × mesaj → beklenen kayıt (satış belgesi / alış belgesi / gider / basit cari / sayım).
 * AI taklit edilir (bazen bilerek YANLIŞ tür döndürür); yönlendirmeyi kod düzeltmeli.
 */
class WhatsappTradeRoutingTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905550000000';

    private Product $flour;

    private Product $bread;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 000 00 00']]);
        Http::fake();
        $this->useProfile('trade');

        $this->flour = Product::create(['name' => 'Un', 'type' => Product::TYPE_PRODUCT, 'default_price' => 850]);
        $this->bread = Product::create(['name' => 'Ekmek', 'type' => Product::TYPE_PRODUCT, 'default_price' => 10]);
        StockMovement::create(['product_id' => $this->bread->id, 'direction' => 'in', 'reason' => 'adjustment', 'quantity' => 100, 'movement_date' => now()]);
    }

    private function useProfile(string $profile, array $overrides = []): void
    {
        config(['modules' => ModuleProfiles::resolve($profile, $overrides), 'app.profile' => $profile]);
    }

    private function fakeAi(array ...$responses): void
    {
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturn(...$responses);
        $this->app->instance(ExpenseExtractor::class, $mock);
    }

    private function entry(array $o): array
    {
        return array_merge([
            'kind' => ExpenseExtractor::KIND_EXPENSE, 'items' => [], 'payment_type' => null, 'amount' => 0.0,
            'date' => now()->format('Y-m-d'), 'due_date' => null, 'description' => '', 'project_id' => null,
            'project_name' => null, 'party_id' => null, 'party_name' => null, 'category_id' => null,
            'category_name' => null, 'paid' => null, 'confidence' => 'high', 'question' => null, 'is_new_entry' => false,
            'product_id' => null, 'counted_quantity' => null, 'daily_usage' => null, 'reorder_level' => null,
            'cash_amount' => null, 'card_amount' => null, 'month' => null,
        ], $o);
    }

    private function send(string $body): string
    {
        return $this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])->assertOk()->getContent();
    }

    /** Kayıt sayıları: [satış, alış, gider, belgesiz cari satırı]. */
    private function counts(): array
    {
        return [Sale::count(), Purchase::count(), Expense::count(), PartyLedgerEntry::whereNull('sale_id')->whereNull('purchase_id')->count()];
    }

    private function assertDelta(array $before, array $delta, string $why): void
    {
        $after = $this->counts();
        $this->assertSame(array_map(fn ($b, $d) => $b + $d, $before, $delta), $after, $why . ' [satış, alış, gider, basit cari]');
    }

    // ------------------------------------------------------------ Stoklu satış firması

    public function test_cash_product_sale_goes_to_sale_document_not_ledger_or_expense(): void
    {
        $this->fakeAi($this->entry(['kind' => 'trade_sale', 'amount' => 0, 'description' => '30 ekmek',
            'items' => [['description' => 'ekmek', 'product_id' => $this->bread->id, 'quantity' => 30, 'unit_price' => null, 'amount' => null]]]));
        $before = $this->counts();

        $this->assertStringContainsString('Peşin satış', $this->send('30 ekmek sattım'));
        $this->assertStringContainsString('Satış kaydedildi: 300,00', $this->send('evet'));

        $this->assertDelta($before, [1, 0, 0, 0], 'Peşin ürünlü satış');
        $this->assertNull(Sale::latest('id')->first()->party_id);
        $this->assertSame(70.0, $this->bread->currentStock());
    }

    public function test_daily_revenue_cash_and_card(): void
    {
        $this->fakeAi($this->entry(['kind' => 'trade_sale', 'amount' => 25000, 'cash_amount' => 10000, 'card_amount' => 15000, 'description' => 'Günlük ciro']));
        $before = $this->counts();

        $this->assertStringContainsString('Nakit 10.000,00 + Kart 15.000,00', $this->send('bugün 10.000 nakit 15.000 kart'));
        $this->send('evet');

        $this->assertDelta($before, [1, 0, 0, 0], 'Toplu ciro');
        $sale = Sale::latest('id')->first();
        $this->assertSame(['10000.00', '15000.00', '25000.00'], [$sale->cash_amount, $sale->bank_amount, $sale->manual_amount]);
    }

    public function test_credit_sale_to_party_writes_sale_document_with_debt(): void
    {
        $restaurant = Party::create(['name' => 'Lokanta']);
        $this->fakeAi($this->entry(['kind' => 'trade_sale', 'party_id' => $restaurant->id, 'paid' => false,
            'items' => [['description' => 'ekmek', 'product_id' => $this->bread->id, 'quantity' => 50, 'unit_price' => null, 'amount' => null]]]));
        $before = $this->counts();

        $this->assertStringContainsString('Veresiye satış', $this->send('Lokantaya 50 ekmek veresiye'));
        $this->send('evet');

        $this->assertDelta($before, [1, 0, 0, 0], 'Veresiye satış basit cari satırına düşmez');
        $this->assertSame('500.00', Sale::latest('id')->first()->ledgerEntry->amount);
    }

    public function test_ai_says_debt_note_for_bread_on_credit_still_sale_document(): void
    {
        $restaurant = Party::create(['name' => 'Lokanta']);
        $this->fakeAi($this->entry(['kind' => ExpenseExtractor::KIND_DEBT_NOTE, 'debt_side' => 'receivable', 'party_id' => $restaurant->id,
            'amount' => 500, 'description' => '50 ekmek veresiye']));
        $before = $this->counts();

        $this->send('Lokantaya 50 ekmeği veresiye verdim 500');
        $this->send('evet');

        $this->assertDelta($before, [1, 0, 0, 0], 'Alacak sanılan ekmek satışı → satış belgesi');
    }

    public function test_ai_says_expense_for_catalog_flour_becomes_purchase_document(): void
    {
        $mill = Party::create(['name' => 'Ak Un']);
        $this->fakeAi($this->entry(['kind' => ExpenseExtractor::KIND_EXPENSE, 'party_id' => $mill->id, 'amount' => 17000,
            'description' => '20 çuval un', 'category_name' => 'Malzeme',
            'items' => [['description' => 'un', 'product_id' => $this->flour->id, 'quantity' => 20, 'unit_price' => null, 'amount' => 17000]]]));
        $before = $this->counts();

        $this->assertStringContainsString('Alış', $this->send("Ak Un'dan 20 çuval un aldım 17 bin"));
        $this->assertStringContainsString('Alış kaydedildi', $this->send('evet'));

        $this->assertDelta($before, [0, 1, 0, 0], 'Katalogdaki un gider değil alış');
        $this->assertSame(20.0, $this->flour->currentStock());
    }

    public function test_flour_without_quantity_asks_how_many(): void
    {
        $this->fakeAi($this->entry(['kind' => ExpenseExtractor::KIND_EXPENSE, 'amount' => 17000, 'description' => 'un aldım', 'paid' => true]));
        $before = $this->counts();

        $this->assertStringContainsString('Kaç', $this->send('17 bin un aldım nakit'));
        $this->assertStringContainsString('❓', $this->send('evet'));
        $this->assertDelta($before, [0, 0, 0, 0], 'Adetsiz alış kaydedilmez, sorulur');
    }

    public function test_non_catalog_purchase_goes_to_expense(): void
    {
        $this->fakeAi($this->entry(['kind' => 'trade_purchase', 'amount' => 2000, 'description' => 'mazot', 'paid' => true,
            'items' => [['description' => 'mazot', 'product_id' => null, 'quantity' => null, 'unit_price' => null, 'amount' => 2000]]]));
        $before = $this->counts();

        $this->send('2 bin mazot aldım');
        $this->send('evet');

        $this->assertDelta($before, [0, 0, 1, 0], 'Katalogda olmayan mazot → gider');
    }

    public function test_plain_expense_stays_expense(): void
    {
        $this->fakeAi($this->entry(['kind' => ExpenseExtractor::KIND_EXPENSE, 'amount' => 20000, 'description' => 'Kira', 'paid' => true]));
        $before = $this->counts();

        $this->send('kira 20 bin ödendi');
        $this->send('evet');

        $this->assertDelta($before, [0, 0, 1, 0], 'Kira gider kalır');
    }

    public function test_collection_stays_simple_ledger(): void
    {
        $restaurant = Party::create(['name' => 'Lokanta']);
        $this->fakeAi($this->entry(['kind' => ExpenseExtractor::KIND_COLLECTION, 'party_id' => $restaurant->id, 'amount' => 500]));
        $before = $this->counts();

        $this->send('Lokanta 500 ödedi');
        $this->send('evet');

        $this->assertDelta($before, [0, 0, 0, 1], 'Tahsilat cari satırı');
    }

    public function test_stock_count_query_and_setting(): void
    {
        $this->fakeAi(
            $this->entry(['kind' => 'stock_count', 'product_id' => $this->bread->id, 'counted_quantity' => 90]),
            $this->entry(['kind' => 'stock_query', 'product_id' => $this->bread->id]),
            $this->entry(['kind' => 'stock_setting', 'product_id' => $this->flour->id, 'daily_usage' => 3, 'reorder_level' => 6]),
        );

        $this->assertStringContainsString('sistemde 100', $this->send('ekmek 90 kaldı'));
        $this->send('evet');
        $this->assertSame(90.0, $this->bread->currentStock());

        $this->assertStringContainsString('Ekmek: 90', $this->send('ekmek ne kadar kaldı'));

        $this->assertStringContainsString('6 çuval kalınca', str_replace('tane', 'çuval', $this->send('unu günde 3 kullanıyorum 6 kala haber ver')));
        $this->send('evet');
        $this->flour->refresh();
        $this->assertTrue($this->flour->auto_usage);
        $this->assertSame('6.00', $this->flour->reorder_level);
    }

    public function test_month_result_answer(): void
    {
        $this->fakeAi($this->entry(['kind' => 'month_result']));
        $this->assertStringContainsString('sonucu (yaklaşık)', $this->send('bu ay ne kazandım'));
    }

    public function test_undo_trade_sale_restores_stock(): void
    {
        $this->fakeAi($this->entry(['kind' => 'trade_sale', 'items' => [['description' => 'ekmek', 'product_id' => $this->bread->id, 'quantity' => 10, 'unit_price' => null, 'amount' => null]]]));
        $this->send('10 ekmek sattım');
        $this->send('evet');
        $this->assertSame(90.0, $this->bread->currentStock());

        $pending = \App\Models\WhatsappPendingExpense::latest('id')->first();
        $this->assertContains(['sale', Sale::latest('id')->value('id')], $pending->extracted['created_records']);
        Sale::latest('id')->first()->delete(); // geri alma ile aynı silme (cascade)
        $this->assertSame(100.0, $this->bread->currentStock());
    }

    // ------------------------------------------------------------ Eski firmalar: hiçbir şey değişmez

    public function test_old_profiles_have_no_trade_kinds(): void
    {
        foreach (['mimar', 'muteahhit', 'alacak_verecek'] as $profile) {
            $this->useProfile($profile);
            $this->assertSame([], array_values(array_intersect(ExpenseExtractor::TRADE_KINDS, ExpenseExtractor::allowedKinds())), $profile);
        }
    }

    public function test_stock_without_trade_package_expense_stays_expense(): void
    {
        // Stok açık ama Satışlar/Alışlar ekranı kapalı: "un aldım" eskisi gibi gider.
        $this->useProfile('alacak_verecek', ['stock' => true, 'direct_sales' => true]);
        $this->fakeAi($this->entry(['kind' => ExpenseExtractor::KIND_EXPENSE, 'amount' => 17000, 'description' => '20 çuval un', 'paid' => true]));
        $before = $this->counts();

        $this->send('20 çuval un aldım 17 bin');
        $this->send('evet');

        $this->assertDelta($before, [0, 0, 1, 0], 'Eski firmada yönlendirme değişmez');
        $this->assertSame(0.0, $this->flour->currentStock());
    }

    public function test_alacak_verecek_sale_stays_simple_ledger(): void
    {
        $this->useProfile('alacak_verecek');
        $ali = Party::create(['name' => 'Ali']);
        $this->fakeAi($this->entry(['kind' => ExpenseExtractor::KIND_SALE, 'party_id' => $ali->id, 'amount' => 600, 'description' => '10 ekmek', 'paid' => false]));
        $before = $this->counts();

        $this->send("Ali'ye 10 ekmek sattım 600");
        $this->send('evet');

        $this->assertDelta($before, [0, 0, 0, 1], 'Basit Satış Yaptım aynen');
    }
}
