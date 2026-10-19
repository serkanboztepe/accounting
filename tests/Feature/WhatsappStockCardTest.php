<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\ChatAssistant;
use App\Services\Whatsapp\ExpenseContext;
use App\Support\ModuleProfiles;
use App\Support\StockUsage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Birleşik stok kartı (Oktay 18.10: "32 besi yemi, 17 ezme, 10 buzağı yemi var, güncelle" → yalnız ilki önerildi).
 * Tek mesaj, tek kart, tek evet; her ürün bir satır (miktar + günlük kullanım + eşik); belirsiz "ekle" sorulur.
 */
class WhatsappStockCardTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905557770018';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'modules' => ModuleProfiles::resolve('trade', ['smart_assistant' => true]), 'app.profile' => 'trade',
            'services.anthropic.api_key' => 'test', 'services.anthropic.chat_model' => 'claude-sonnet-5',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 777 00 18'],
        ]);
        ChatAssistant::$deadline = null;
        WhatsappMessage::create(['phone' => '905557770018', 'direction' => 'in', 'body' => 'önceki']);
    }

    private function stocked(string $name, float $qty): Product
    {
        $p = Product::create(['name' => $name, 'type' => 'product']);
        $p->countTo($qty);

        return $p;
    }

    private function ai(array $rows, string $kind = 'stock_count'): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'propose_entry',
            'input' => ['kind' => $kind, 'amount' => 0, 'stock_items' => $rows, 'is_new_entry' => true]]], 'usage' => []]),
            '*' => Http::response([], 200)]);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    public function test_three_products_one_card_one_yes(): void
    {
        $besi = $this->stocked('Besi Yemi', 33);
        $ezme = $this->stocked('Ezme', 5);
        $this->ai([
            ['product_id' => $besi->id, 'product_name' => 'besi yemi', 'quantity' => 32, 'quantity_mode' => 'set'],
            ['product_id' => $ezme->id, 'product_name' => 'ezme', 'quantity' => 17, 'quantity_mode' => 'set'],
            ['product_name' => 'buzağı yemi', 'unit' => 'adet', 'quantity' => 10, 'quantity_mode' => 'set'],
        ]);

        $card = $this->send("Elimde 32 adet besi yemi \n17 adet ezme \n10 adet buzağı yemi var \nBunlara gore stokları guncelle");
        $this->assertStringContainsString('• Besi Yemi: 33 → 32', $card);
        $this->assertStringContainsString('• Ezme: 5 → 17', $card);
        $this->assertStringContainsString('• Buzağı yemi 🆕: 0 → 10 adet', $card);

        $done = $this->send('Evet');
        $this->assertStringContainsString('Stok güncellendi', $done);
        $this->assertEquals(32, $besi->fresh()->currentStock());
        $this->assertEquals(17, $ezme->fresh()->currentStock());
        $this->assertEquals(10, Product::where('name', 'Buzağı yemi')->firstOrFail()->currentStock());
    }

    public function test_quantity_and_usage_in_one_line(): void
    {
        $besi = $this->stocked('Besi Yemi', 33);
        $this->ai([['product_id' => $besi->id, 'product_name' => 'besi yemi', 'quantity' => 32, 'quantity_mode' => 'set',
            'usage_amount' => 12, 'usage_every_days' => 1, 'reorder_level' => 15]]);

        $card = $this->send('Elimde 32 besi yemi var günde 12 gidiyor 15 kalınca haber ver');
        $this->assertStringContainsString('• Besi Yemi: 33 → 32 · her sabah 12 düşülür (yarından itibaren) · 15 kalınca sabah 08:00 uyarı', $card);

        $this->send('Evet');
        $besi->refresh();
        $this->assertEquals(32, $besi->currentStock());
        $this->assertTrue($besi->auto_usage);
        $this->assertEquals(12, (float) $besi->daily_usage);
        $this->assertEquals(15, (float) $besi->reorder_level);
    }

    public function test_setting_only_leaves_stock_untouched(): void
    {
        $ezme = $this->stocked('Ezme', 17);
        $this->ai([['product_id' => $ezme->id, 'product_name' => 'ezme', 'usage_amount' => 4, 'reorder_level' => 5]], 'stock_setting');

        $this->assertStringContainsString('• Ezme: stok 17 · her sabah 4 düşülür', $this->send('Günlük 4 adet ezme tüketiyorum hergun dus 5 adet kalinca haber ver'));
        $this->send('Evet');
        $this->assertEquals(17, $ezme->fresh()->currentStock());
        $this->assertEquals(4, (float) $ezme->fresh()->daily_usage);
    }

    /** Oktay 18.10: "Buzağı yemi 3 günde 1 adet gidiyor" → 0,33/gün yazılmıştı; artık her 3 günde 1. */
    public function test_every_three_days_one(): void
    {
        $buz = $this->stocked('Buzağı Yemi', 9);
        $this->ai([['product_id' => $buz->id, 'product_name' => 'buzağı yemi', 'usage_amount' => 1, 'usage_every_days' => 3, 'reorder_level' => 2]], 'stock_setting');

        $this->assertStringContainsString('her 3 günde 1 düşülür', $this->send('Buzağı yemi 3 gunde 1 adet gidiyor 2 adet kalinca haber ver'));
        $this->send('Evet');
        $buz->refresh();
        $this->assertSame(3, $buz->usage_every_days);
        $this->assertEquals(1, (float) $buz->daily_usage);
        $this->assertSame('her 3 günde 1', $buz->usageLabel());

        $start = Carbon::parse($buz->usage_booked_through->toDateString(), 'Europe/Istanbul');
        StockUsage::book($start->copy()->addDays(2));
        $this->assertEquals(9, $buz->fresh()->currentStock(), '2. gün düşmez');
        StockUsage::book($start->copy()->addDays(3));
        $this->assertEquals(8, $buz->fresh()->currentStock(), '3. gün 1 düşer');
        StockUsage::book($start->copy()->addDays(5));
        $this->assertEquals(8, $buz->fresh()->currentStock(), '5. gün düşmez');
        StockUsage::book($start->copy()->addDays(9));
        $this->assertEquals(6, $buz->fresh()->currentStock(), 'kaçan 6. ve 9. gün telafi');
        $this->assertSame(3, StockMovement::where('product_id', $buz->id)->where('reason', StockMovement::REASON_USAGE)->count());
    }

    public function test_daily_product_still_deducts_every_day(): void
    {
        $saman = Product::create(['name' => 'Saman', 'type' => 'product', 'auto_usage' => true, 'daily_usage' => 2]);
        $saman->countTo(10);
        $start = Carbon::parse($saman->fresh()->usage_booked_through->toDateString(), 'Europe/Istanbul');

        StockUsage::book($start->copy()->addDays(3));

        $this->assertEquals(4, $saman->fresh()->currentStock());
    }

    /** "17 adet ezme ekle" — ezmenin stoğu 5: 17 mi olsun, 22 mi? Sorulur; sayı cevabı çözülür. */
    public function test_unclear_add_is_asked_and_number_answer_resolves(): void
    {
        $ezme = $this->stocked('Ezme', 5);
        $this->ai([['product_id' => $ezme->id, 'product_name' => 'ezme', 'quantity' => 17, 'quantity_mode' => 'unclear']]);

        $q = $this->send('17 adet ezme ekle');
        $this->assertStringContainsString('Ezme 17 mi olsun, yoksa 5 + 17 = 22 mi?', $q);
        $this->send('evet');
        $this->assertEquals(5, $ezme->fresh()->currentStock(), 'soru cevaplanmadan kaydedilmez');

        $card = $this->send('22');
        $this->assertStringContainsString('• Ezme: 5 + 17 = 22', $card);
        $this->send('evet');
        $this->assertEquals(22, $ezme->fresh()->currentStock());
    }

    public function test_unclear_answer_with_the_said_number_sets(): void
    {
        $ezme = $this->stocked('Ezme', 5);
        $this->ai([['product_id' => $ezme->id, 'product_name' => 'ezme', 'quantity' => 17, 'quantity_mode' => 'unclear']]);

        $this->send('17 adet ezme ekle');
        $this->assertStringContainsString('• Ezme: 5 → 17', $this->send('17'));
        $this->send('evet');
        $this->assertEquals(17, $ezme->fresh()->currentStock());
    }

    public function test_add_to_new_or_empty_product_is_not_asked(): void
    {
        $this->ai([['product_name' => 'kuzu yemi', 'unit' => 'adet', 'quantity' => 10, 'quantity_mode' => 'unclear']]);

        $card = $this->send('10 adet kuzu yemi ekle');
        $this->assertStringNotContainsString('mi olsun', $card);
        $this->assertStringContainsString('• Kuzu yemi 🆕: 0 → 10 adet', $card);
    }

    public function test_similar_existing_card_is_pointed_out(): void
    {
        $this->stocked('Besi Yemi', 33);
        $this->ai([['product_name' => 'buzağı yemi', 'quantity' => 10, 'quantity_mode' => 'set']]);

        $card = $this->send('10 buzağı yemi var');
        $this->assertStringContainsString('• Buzağı yemi 🆕', $card);
        $this->assertStringContainsString('Stokta *Besi Yemi* de var', $card);
    }

    public function test_context_shows_every_days_label(): void
    {
        Product::create(['name' => 'Buzağı Yemi', 'type' => 'product', 'auto_usage' => true, 'daily_usage' => 1, 'usage_every_days' => 3]);

        $this->assertMatchesRegularExpression('/Buzağı Yemi.*her 3 günde 1 düşülüyor/u', ExpenseContext::build(self::PHONE));
    }
}
