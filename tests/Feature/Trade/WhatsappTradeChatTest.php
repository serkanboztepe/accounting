<?php

namespace Tests\Feature\Trade;

use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\ChatAssistant;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Stoklu satış + akıllı asistan (2026-10-13 tüm firmalarda açıldı): sohbet asistanının önerisi de aynı
 * yönlendirmeden geçer — ürün satışı satış belgesi, katalog malı alış belgesi; adet sorusu her iki
 * cevap yolunda (yazı → asistan, sadece sayı → eski çıkarıcı) çalışır. AI taklit edilir.
 */
class WhatsappTradeChatTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905550000001';

    private Product $flour;

    private Product $bread;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.twilio.verify_signature' => false,
            'services.twilio.allowed_phones' => ['0555 000 00 01'],
            'services.anthropic.chat_assistant' => false,
            'modules' => ModuleProfiles::resolve('trade', ['smart_assistant' => true]),
            'app.profile' => 'trade',
        ]);
        Http::fake();
        WhatsappMessage::create(['phone' => '905550000001', 'direction' => 'in', 'body' => 'önceki']);

        $this->flour = Product::create(['name' => 'Un', 'type' => Product::TYPE_PRODUCT, 'default_price' => 850]);
        $this->bread = Product::create(['name' => 'Ekmek', 'type' => Product::TYPE_PRODUCT, 'default_price' => 10]);
        StockMovement::create(['product_id' => $this->bread->id, 'direction' => 'in', 'reason' => 'adjustment', 'quantity' => 100, 'movement_date' => now()]);
    }

    private function entry(array $o): array
    {
        return array_merge([
            'kind' => ExpenseExtractor::KIND_EXPENSE, 'items' => [], 'payment_type' => null, 'amount' => 0.0,
            'date' => now()->format('Y-m-d'), 'due_date' => null, 'description' => '', 'project_id' => null,
            'project_name' => null, 'party_id' => null, 'party_name' => null, 'category_id' => null,
            'category_name' => null, 'paid' => null, 'confidence' => 'high', 'question' => null, 'is_new_entry' => true,
            'product_id' => null, 'counted_quantity' => null, 'daily_usage' => null, 'reorder_level' => null,
            'cash_amount' => null, 'card_amount' => null, 'month' => null, 'next_items' => null,
        ], $o);
    }

    private function chat(array ...$entries): void
    {
        $mock = Mockery::mock(ChatAssistant::class);
        $mock->shouldReceive('respond')->andReturn(...array_map(fn ($e) => ['type' => 'entry', 'data' => $e, 'text' => ''], $entries));
        $this->app->instance(ChatAssistant::class, $mock);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    public function test_chat_cash_sale_writes_sale_document(): void
    {
        $sales = Sale::count();
        $this->chat($this->entry(['kind' => 'trade_sale', 'description' => '30 ekmek',
            'items' => [['description' => 'ekmek', 'product_id' => $this->bread->id, 'quantity' => 30, 'unit_price' => null, 'amount' => null]]]));

        $this->assertStringContainsString('Peşin satış', $this->send('30 ekmek sattım'));
        $this->assertStringContainsString('Satış kaydedildi', $this->send('evet'));

        $this->assertSame($sales + 1, Sale::count());
        $this->assertNull(Sale::latest('id')->first()->party_id);
        $this->assertSame(70.0, $this->bread->currentStock());
    }

    public function test_chat_flour_as_expense_becomes_purchase_and_quantity_answer_in_words(): void
    {
        $expenses = Expense::count();
        $purchases = Purchase::count();
        $this->chat(
            $this->entry(['kind' => ExpenseExtractor::KIND_EXPENSE, 'amount' => 17000, 'description' => 'un', 'paid' => true]),
            $this->entry(['kind' => 'trade_purchase', 'amount' => 17000, 'paid' => true, 'is_new_entry' => false,
                'items' => [['description' => 'un', 'product_id' => $this->flour->id, 'quantity' => 20, 'unit_price' => null, 'amount' => 17000]]]),
        );

        $this->assertStringContainsString('Kaç', $this->send('un aldım 17 bin ödedim'));
        $this->send('20 çuval');
        $this->send('evet');

        $this->assertSame($expenses, Expense::count(), 'gidere düşmedi');
        $this->assertSame($purchases + 1, Purchase::count());
        $this->assertSame(20.0, $this->flour->currentStock());
    }

    public function test_quantity_answer_as_bare_number_uses_old_path(): void
    {
        $purchases = Purchase::count();
        $this->chat($this->entry(['kind' => 'trade_purchase', 'amount' => 17000, 'paid' => true,
            'items' => [['description' => 'un', 'product_id' => $this->flour->id, 'quantity' => null, 'unit_price' => null, 'amount' => 17000]]]));
        $old = Mockery::mock(ExpenseExtractor::class)->makePartial();
        $old->shouldReceive('extract')->andReturn($this->entry(['kind' => 'trade_purchase', 'amount' => 17000, 'paid' => true, 'is_new_entry' => false,
            'items' => [['description' => 'un', 'product_id' => $this->flour->id, 'quantity' => 20, 'unit_price' => null, 'amount' => 17000]]]));
        $this->app->instance(ExpenseExtractor::class, $old);

        $this->assertStringContainsString('Kaç', $this->send('un aldım 17 bin ödedim'));
        $reply = $this->send('20');
        $this->send('evet');

        $this->assertSame($purchases + 1, Purchase::count(), 'Cevap: ' . $reply);
        $this->assertSame(20.0, $this->flour->currentStock());
    }
}
