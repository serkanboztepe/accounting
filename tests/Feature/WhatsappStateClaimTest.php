<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\ChatAssistant;
use App\Services\Whatsapp\ExpenseContext;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Kartta görünen = kaydedilen" (Çiftlik 16.10: "33 adet yem var, günde 16 gidiyor" → günlük kullanım kayboldu; sonra AI düz
 * cevapta "zaten kaydettim, otomatik düşüyor" dedi — ayar yoktu). Ürün açarken günlük kullanım/eşik aynı karta yazılır;
 * bağlam ayarın gerçeğini söyler; düz cevap "yaptım" diyemez.
 */
class WhatsappStateClaimTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905557770016';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'modules' => ModuleProfiles::resolve('trade', ['smart_assistant' => true]), 'app.profile' => 'trade',
            'services.anthropic.api_key' => 'test', 'services.anthropic.chat_model' => 'claude-sonnet-5',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 777 00 16'],
        ]);
        ChatAssistant::$deadline = null;
        WhatsappMessage::create(['phone' => '905557770016', 'direction' => 'in', 'body' => 'önceki']);
    }

    private function tool(string $name, array $input, string $id = 't1'): array
    {
        return ['content' => [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]], 'usage' => []];
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    private function productAdd(array $items, ?float $daily = null, ?float $reorder = null): array
    {
        return $this->tool('propose_entry', ['kind' => 'product_add', 'amount' => 0, 'items' => $items, 'daily_usage' => $daily,
            'reorder_level' => $reorder, 'is_new_entry' => true, 'date' => now()->toDateString()]);
    }

    public function test_new_product_with_daily_usage_is_one_card_one_yes(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->productAdd([['description' => 'Kuzu yemi', 'unit' => 'adet', 'quantity' => 33]], 16, 10)),
            '*' => Http::response([], 200)]);

        $card = $this->send('33 adet kuzu yemi var elimde gunde 16 adet gidiyor 10 kalınca haber ver stoklara ekle');
        $this->assertStringContainsString('Kuzu yemi (adet) · stok 0 → 33 adet', $card);
        $this->assertStringContainsString('Her sabah 16 adet stoktan düşülecek', $card);
        $this->assertStringContainsString('10 adet kalınca sabah 08:00', $card);

        $done = $this->send('evet');
        $this->assertStringContainsString('her sabah 16 adet düşülecek', $done);
        $p = Product::where('name', 'Kuzu yemi')->firstOrFail();
        $this->assertTrue((bool) $p->auto_usage);
        $this->assertEquals(16, (float) $p->daily_usage);
        $this->assertEquals(10, (float) $p->reorder_level);
        $this->assertEquals(33, $p->currentStock());
    }

    public function test_daily_usage_for_several_new_products_is_not_silently_dropped(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->productAdd([['description' => 'Kuzu yemi', 'unit' => 'adet', 'quantity' => 33],
            ['description' => 'Tuz taşı', 'unit' => 'adet', 'quantity' => 5]], 16)), '*' => Http::response([], 200)]);

        $card = $this->send('33 kuzu yemi 5 tuz taşı var günde 16 gidiyor ekle');

        $this->assertStringNotContainsString('Her sabah', $card, 'hangi ürün belli değil — tahmin yok');
        $this->assertStringContainsString('Sırada', $card);
        $this->assertStringContainsString('➡️ Sırada: günde 16 gidiyor (bunu onaylayınca sorarım)', $card);
    }

    public function test_existing_product_with_daily_usage_becomes_its_setting(): void
    {
        $yem = Product::create(['name' => 'Kuzu Yemi', 'type' => 'product']);
        Http::fake(['api.anthropic.com/*' => Http::response($this->productAdd([['description' => 'Kuzu yemi', 'unit' => 'adet']], 16)),
            '*' => Http::response([], 200)]);

        $card = $this->send('kuzu yemi günde 16 gidiyor ekle');
        $this->assertStringContainsString('Her sabah 16', $card);

        $this->send('evet');
        $this->assertEquals(16, (float) $yem->fresh()->daily_usage);
        $this->assertTrue((bool) $yem->fresh()->auto_usage);
    }

    public function test_context_tells_the_real_usage_setting(): void
    {
        Product::create(['name' => 'Kuzu Yemi', 'type' => 'product']);
        Product::create(['name' => 'Saman Balya', 'type' => 'product', 'auto_usage' => true, 'daily_usage' => 4, 'reorder_level' => 20]);

        $ctx = ExpenseContext::build(self::PHONE);

        $this->assertMatchesRegularExpression('/Kuzu Yemi.*otomatik düşme yok/u', $ctx);
        $this->assertMatchesRegularExpression('/Saman Balya.*her sabah 4 düşülüyor, 20 kalınca uyarı/u', $ctx);
    }

    /** Çiftlik 16.10 14:48: "Hergun giden yemi dus" → "zaten kaydettim" — geri çevrilir, AI ikinci turda doğrusunu söyler. */
    public function test_claim_in_plain_reply_is_sent_back_once(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->tool('reply', ['text' => 'Günlük kullanımı zaten 16 adet/gün olarak kaydettim; stoktan otomatik düşüyor.']))
            ->push($this->tool('reply', ['text' => 'Yem için otomatik düşme ayarı yok. Günde kaç adet gidiyor?'], 't2'))]);

        $r = app(ChatAssistant::class)->respond(self::PHONE, 'Hergun giden yemi dus', null);

        $this->assertSame('Yem için otomatik düşme ayarı yok. Günde kaç adet gidiyor?', $r['text']);
        Http::assertSentCount(2);
        $this->assertStringContainsString('GERİ ÇEVRİLDİ', json_encode(Http::recorded()[1][0]->data(), JSON_UNESCAPED_UNICODE));
    }

    public function test_claim_twice_gets_safe_fixed_reply(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->tool('reply', ['text' => 'Tamam, ayarladım.']))
            ->push(['content' => [['type' => 'text', 'text' => 'Ayarı ekledim, her gün düşecek.']], 'usage' => []])]);

        $r = app(ChatAssistant::class)->respond(self::PHONE, 'Hergun giden yemi dus', null);

        $this->assertStringStartsWith('Bunu henüz kaydetmedim.', $r['text']);
        Http::assertSentCount(2);
    }

    public function test_plain_text_claim_without_tool_is_also_sent_back(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'text', 'text' => 'Kaydettim, merak etme.']], 'usage' => []])
            ->push($this->tool('propose_entry', ['kind' => 'stock_setting', 'product_name' => 'Yem', 'unit' => 'adet', 'daily_usage' => 16,
                'amount' => 0, 'is_new_entry' => true]))]);

        $r = app(ChatAssistant::class)->respond(self::PHONE, 'yem günde 16 gidiyor', null);

        $this->assertSame('entry', $r['type']);
        $this->assertEquals(16, $r['data']['daily_usage']);
    }

    public function test_honest_reply_is_untouched(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->tool('reply', ['text' => 'Saman her sabah 4 balya düşülüyor, stok 120.']))]);

        $r = app(ChatAssistant::class)->respond(self::PHONE, 'saman düşüyor mu', null);

        $this->assertSame('Saman her sabah 4 balya düşülüyor, stok 120.', $r['text']);
        Http::assertSentCount(1);
    }

    public function test_claim_detector(): void
    {
        foreach (['zaten kaydettim', 'Ayarladım 👍', 'Bunu not aldım', 'kaydı sildim', 'Fiyatı güncelledim'] as $t) {
            $this->assertTrue(ChatAssistant::claimsDone($t), $t);
        }
        foreach (['Kaydedeyim mi?', 'Bugün tek gider var: 1.150 ₺', 'Yem her sabah 16 adet düşülüyor', 'Ödediğini anladım', 'kaydetmedim'] as $t) {
            $this->assertFalse(ChatAssistant::claimsDone($t), $t);
        }
    }

    /** Çiftlik 18.10 12:52: "her gün ekmek gideri ekle" hafızaya söz olarak yazıldı — hafıza kendiliğinden kayıt açmaz. */
    public function test_memory_that_promises_automatic_work_is_not_saved(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->tool('propose_entry', ['kind' => 'remember', 'memory_kind' => 'agreement',
            'memory_subject' => 'Çobanlar ekmek gideri', 'memory_fact' => 'Çobanlar her gün 2 ekmek yiyor, ekmek 17,30 TL; her gün Çobanlar kategorisinde gider olarak kaydedilecek',
            'amount' => 0, 'is_new_entry' => true])), '*' => Http::response([], 200)]);
        $before = \App\Models\AssistantMemory::count();

        $reply = $this->send('Çobanlar günde iki ekmek yiyorlar ekmeğin fiyatı 17:30 TL her gün hesap hesaplarına gider olarak ekle');

        $this->assertStringContainsString('hafıza kendiliğinden kayıt açmaz', $reply);
        $this->assertStringContainsString('düzenli gider olarak kurarım', $reply);
        $this->assertStringNotContainsString('Hatırlayayım mı', $reply);
        $this->send('evet');
        $this->assertSame($before, \App\Models\AssistantMemory::count());
    }

    public function test_plain_fact_with_every_day_is_still_a_memory(): void
    {
        $this->assertFalse(\App\Services\Whatsapp\MemoryDraft::promisesAction('Çobanlar her gün 2 ekmek yiyor'));
        $this->assertFalse(\App\Services\Whatsapp\MemoryDraft::promisesAction('Recep ile peynir kilosu 280 TL'));
        $this->assertTrue(\App\Services\Whatsapp\MemoryDraft::promisesAction('Her ay kirayı hatırlat'));
        $this->assertTrue(\App\Services\Whatsapp\MemoryDraft::promisesAction('her gün gider olarak kaydedilecek'));
    }
}
