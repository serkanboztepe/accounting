<?php

namespace Tests\Feature\Trade;

use App\Models\Expense;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Unit;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\ModuleProfiles;
use App\Support\PartyStatement;
use App\Support\TradeDesk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Fırın WhatsApp'tan (bilgisayarsız): hammadde alışı + stok + akşam ciro. Kullanıcı senaryoları (2026-10-13):
 * "Boztepe'den 10 un geldi" (fiyat yok → son alış fiyatı, satış fiyatı DEĞİL), "unlar geldi, 35 çuvalın
 * parası 900 olmuş" (tedarikçi yok → son aldığı yer, ödeme yok → veresiye, fiyat değişti), kartta olmayan
 * mal → ürün kartı açılır (ya da "gider"), akşam ciroda günün özeti. AI taklit edilir.
 */
class WhatsappBakeryFlowTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905550000002';

    private Product $flour;

    private Party $boztepe;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.twilio.verify_signature' => false,
            'services.twilio.allowed_phones' => ['0555 000 00 02'],
            'modules' => ModuleProfiles::resolve('trade'),
            'app.profile' => 'trade',
        ]);
        Http::fake();

        $sack = Unit::firstOrCreate(['code' => 'cuval-test'], ['name' => 'çuval']);
        // Satış fiyatı 5 (kasıtlı): alışta ASLA kullanılmamalı.
        $this->flour = Product::create(['name' => 'Un', 'type' => Product::TYPE_PRODUCT, 'unit_id' => $sack->id, 'default_price' => 5]);
        $this->boztepe = Party::create(['name' => 'Boztepe Un']);
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
            'purchase_price' => null, 'product_name' => null, 'unit' => null,
            'cash_amount' => null, 'card_amount' => null, 'month' => null,
        ], $o);
    }

    private function item(string $name, ?float $qty, ?float $price = null, ?float $amount = null, ?int $productId = null, ?string $unit = null): array
    {
        return ['description' => $name, 'product_id' => $productId, 'quantity' => $qty, 'unit_price' => $price, 'amount' => $amount, 'unit' => $unit];
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    /** Daha önce Boztepe'den 20 çuval × 850 alınmış. */
    private function earlierPurchase(): void
    {
        TradeDesk::savePurchase(['mode' => 'party', 'party_id' => $this->boztepe->id, 'purchase_date' => now()->subDays(5)->toDateString(),
            'lines' => [['product_id' => $this->flour->id, 'quantity' => 20, 'unit_price' => '850', 'amount' => null]]]);
    }

    public function test_purchase_without_price_uses_last_purchase_price_not_sale_price(): void
    {
        $this->earlierPurchase();
        $this->fakeAi($this->entry(['kind' => 'trade_purchase', 'party_id' => $this->boztepe->id, 'paid' => false,
            'items' => [$this->item('un', 10, productId: $this->flour->id)]]));

        $summary = $this->send("Boztepe'den 10 un geldi");
        $this->assertStringContainsString('8.500,00', $summary);
        $this->send('evet');

        $this->assertSame('8500.00', Purchase::latest('id')->first()->total_amount);
    }

    public function test_flour_arrived_no_supplier_no_payment_new_price(): void
    {
        $this->earlierPurchase();
        $this->fakeAi($this->entry(['kind' => 'trade_purchase', 'paid' => null,
            'items' => [$this->item('un', 35, price: 900, productId: $this->flour->id)]]));

        $summary = $this->send('unlar geldi son aldığım 35 çuvalın parası 900 olmuş');
        $this->assertStringContainsString('Boztepe Un (son aldığın yer)', $summary);
        $this->assertStringContainsString('ona borçlanırsın', $summary);
        $this->assertStringContainsString('Fiyat değişti: 850,00 → 900,00', $summary);
        $this->assertStringContainsString('veresiye yazılır', $summary);
        $this->assertStringContainsString('stok 20 → 55', $summary);
        $this->send('evet');

        $purchase = Purchase::latest('id')->first();
        $this->assertSame([$this->boztepe->id, false, '31500.00'], [$purchase->party_id, $purchase->paid, $purchase->total_amount]);
        $this->assertSame(55.0, $this->flour->currentStock());
        $this->assertSame('900.00', $this->flour->fresh()->purchase_price);
        $this->assertEqualsWithDelta(-(17000 + 31500), PartyStatement::build($this->boztepe)['balance'], 0.01);
    }

    public function test_said_paid_without_supplier_is_cash_purchase_without_party(): void
    {
        $this->earlierPurchase();
        $this->fakeAi($this->entry(['kind' => 'trade_purchase', 'paid' => true,
            'items' => [$this->item('un', 5, productId: $this->flour->id)]]));

        $this->send('5 çuval un aldım nakit ödedim');
        $this->send('evet');

        $purchase = Purchase::latest('id')->first();
        $this->assertTrue($purchase->paid);
        $this->assertNull($purchase->party_id);
        $this->assertSame('4250.00', $purchase->total_amount);
    }

    public function test_price_change_shown_when_ai_gives_both_price_and_total(): void
    {
        $this->earlierPurchase();
        $this->fakeAi($this->entry(['kind' => 'trade_purchase', 'party_id' => $this->boztepe->id, 'paid' => false,
            'items' => [$this->item('un', 35, price: 900, amount: 31500, productId: $this->flour->id)]]));

        $this->assertStringContainsString('Fiyat değişti: 850,00 → 900,00', $this->send('unlar geldi 35 çuval 900 olmuş'));
    }

    public function test_suspicious_price_is_flagged(): void
    {
        $this->earlierPurchase();
        $this->fakeAi($this->entry(['kind' => 'trade_purchase', 'party_id' => $this->boztepe->id,
            'items' => [$this->item('un', 35, price: 9000, productId: $this->flour->id)]]));

        $this->assertStringContainsString('⚠️ Birim fiyat 9.000,00 ₺ — son alış 850,00', $this->send('35 çuval un 9000'));
    }

    public function test_first_purchase_without_price_or_supplier_asks(): void
    {
        $purchases = Purchase::count();
        $this->fakeAi($this->entry(['kind' => 'trade_purchase', 'items' => [$this->item('un', 10, productId: $this->flour->id)]]));

        $reply = $this->send('10 çuval un geldi');
        $this->assertStringContainsString('Kimden aldın', $reply);
        $this->send('evet');
        $this->assertSame($purchases, Purchase::count(), 'eksik bilgiyle kayıt olmaz');
    }

    public function test_purchase_price_setting_then_used(): void
    {
        $this->fakeAi(
            $this->entry(['kind' => 'stock_setting', 'product_id' => $this->flour->id, 'purchase_price' => 850]),
            $this->entry(['kind' => 'trade_purchase', 'party_id' => $this->boztepe->id, 'items' => [$this->item('un', 10, productId: $this->flour->id)]]),
        );

        $this->assertStringContainsString('Alış fiyatı: 850,00', $this->send("unu çuvalı 850'den alıyorum"));
        $this->send('evet');
        $this->assertSame('850.00', $this->flour->fresh()->purchase_price);

        $this->assertStringContainsString('8.500,00', $this->send("Boztepe'den 10 un geldi"));
    }

    public function test_unknown_good_offers_new_product_card(): void
    {
        $this->fakeAi($this->entry(['kind' => 'trade_purchase', 'party_id' => $this->boztepe->id, 'paid' => false,
            'items' => [$this->item('maya', 10, amount: 500, unit: 'paket')]]));
        $expenses = Expense::count();

        $summary = $this->send("Boztepe'den 10 paket maya aldım 500");
        $this->assertStringContainsString('🆕 yeni ürün, birim paket', $summary);
        $this->assertStringContainsString('*gider* yaz', $summary);
        $this->assertStringContainsString('Ürün kartı açıldı: Maya', $this->send('evet'));

        $yeast = Product::where('name', 'Maya')->firstOrFail();
        $this->assertSame('paket', mb_strtolower($yeast->unit->name), 'var olan birim kullanılır ya da açılır');
        $this->assertSame(10.0, $yeast->currentStock());
        $this->assertSame('50.00', $yeast->purchase_price);
        $this->assertSame($expenses, Expense::count());
    }

    public function test_unknown_good_gider_reply_writes_expense(): void
    {
        $this->fakeAi($this->entry(['kind' => 'trade_purchase', 'paid' => true,
            'items' => [$this->item('poşet', 3, amount: 600, unit: 'paket')]]));
        $expenses = Expense::count();

        $this->send('3 paket poşet aldım 600 ödedim');
        $this->assertStringContainsString('Gider', $this->send('gider'));
        $this->send('evet');

        $this->assertSame($expenses + 1, Expense::count());
        $this->assertSame('600.00', Expense::latest('id')->first()->amount);
        $this->assertFalse(Product::where('name', 'Poşet')->exists());
    }

    public function test_product_add_creates_cards_once(): void
    {
        $this->fakeAi(
            $this->entry(['kind' => 'product_add', 'items' => [$this->item('un', null, unit: 'çuval'), $this->item('maya', null, unit: 'paket'), $this->item('tuz', null, unit: 'kg')]]),
            $this->entry(['kind' => 'product_add', 'items' => [$this->item('un', null), $this->item('maya', null)]]),
        );

        $summary = $this->send('un, maya, tuz kullanıyorum');
        $this->assertStringNotContainsString('• Un', $summary, 'Un zaten kartta');
        $this->assertStringContainsString('• Maya (paket)', $summary);
        $this->send('evet');
        $this->assertTrue(Product::where('name', 'Tuz')->exists());

        // Hepsi kartta: yeni kart açılmaz, stok listesi gösterilir.
        $this->assertStringContainsString('Stok', $this->send('un ve maya kullanıyorum'));
        $this->assertSame(1, Product::where('name', 'Maya')->count());
    }

    public function test_count_of_unknown_product_creates_card_with_stock(): void
    {
        $this->fakeAi($this->entry(['kind' => 'stock_count', 'product_name' => 'şeker', 'unit' => 'kg', 'counted_quantity' => 25]));

        $this->assertStringContainsString('🆕 Ürün kartı açılacak', $this->send('elimde 25 kg şeker var'));
        $this->send('evet');

        $this->assertSame(25.0, Product::where('name', 'Şeker')->firstOrFail()->currentStock());
    }

    public function test_evening_revenue_reply_includes_day_summary(): void
    {
        $this->earlierPurchase();
        $this->flour->update(['auto_usage' => true, 'daily_usage' => 3, 'reorder_level' => 6]);
        $this->fakeAi($this->entry(['kind' => 'trade_sale', 'amount' => 25000, 'cash_amount' => 10000, 'card_amount' => 15000, 'paid' => true]));

        $this->send('10 bin nakit 15 bin kart');
        $reply = $this->send('evet');

        $this->assertStringContainsString('📊 *Bugün:* ciro 25.000,00', $reply);
        $this->assertStringContainsString('Un 20 çuval (~6 gün)', $reply);
    }

    public function test_old_dated_purchase_edit_does_not_override_card_price(): void
    {
        $this->earlierPurchase();
        TradeDesk::savePurchase(['mode' => 'party', 'party_id' => $this->boztepe->id, 'purchase_date' => now()->toDateString(),
            'lines' => [['product_id' => $this->flour->id, 'quantity' => 10, 'unit_price' => '900', 'amount' => null]]]);
        $this->assertSame('900.00', $this->flour->fresh()->purchase_price);

        $old = Purchase::orderBy('purchase_date')->orderBy('id')->where('party_id', $this->boztepe->id)->first();
        $old->rebuildFromLines([['product_id' => $this->flour->id, 'quantity' => 20, 'unit_price' => '800', 'amount' => null]]);

        $this->assertSame('900.00', $this->flour->fresh()->purchase_price);
    }
}
