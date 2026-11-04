<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Sale;
use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ChatAssistant;
use App\Services\Whatsapp\ExpenseExtractor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Akkuş 03.11: "Akkuş ergenekon şube 22107 tl kart 2450 tl nakit 1400 tl gider" — K4 (kasa + ayrı gider) doğru ayırdı, ama
 * (1) süzgeç "1.400 gider ayrı mı?" diye sordu, (2) "Ödeme" cevabıyla gider taslaktan düştü ve "Sırada" oldu, (3) sıradaki kalem
 * asıl mesajı yeniden okuyup bütün satışı ikinci kez kurdu. Gider hiç kaydedilmedi, satış kopyası onay bekledi.
 */
class WhatsappTillExpenseChainTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905557771299';

    private const TILL = 'Akkuş ergenekon şube 22107 tl kart 2450 tl nakit 1400 tl gider';

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => \App\Support\ModuleProfiles::resolve('trade', ['smart_assistant' => true]), 'app.profile' => 'trade']);
        config([
            'services.twilio.verify_signature' => false,
            'services.twilio.allowed_phones' => ['0555 777 12 99'],
            'services.anthropic.api_key' => 'test-key',
            'services.anthropic.chat_assistant' => false,
            'modules.expenses' => true,
            'modules.projects' => false,
            'modules.smart_assistant' => true,
        ]);
        WhatsappMessage::create(['phone' => '905557771299', 'direction' => 'in', 'body' => 'önceki']);
    }

    private function till(array $o = []): array
    {
        return array_merge([
            'kind' => ExpenseExtractor::KIND_TRADE_SALE, 'items' => [], 'totals_side' => null, 'date_from' => null, 'date_to' => null,
            'payment_type' => null, 'cash_amount' => 2450, 'card_amount' => 22107, 'amount' => 24557, 'date' => now()->format('Y-m-d'),
            'due_date' => null, 'description' => 'Ergenekon şube günlük kasa', 'project_id' => null, 'project_name' => null,
            'party_id' => null, 'party_name' => null, 'category_id' => null, 'category_name' => null, 'paid' => true,
            'confidence' => 'high', 'question' => null, 'is_new_entry' => true, 'debt_side' => null, 'reply' => null,
            'payment_purpose' => null, 'settles_expense_id' => null, 'next_items' => null,
        ], $o);
    }

    private function chat(array ...$results): void
    {
        $mock = Mockery::mock(ChatAssistant::class);
        $mock->shouldReceive('respond')->andReturn(...$results);
        $this->app->instance(ChatAssistant::class, $mock);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    /** Süzgeç çelişki bulsa bile (HTTP sahte: same=false) K4 kasası sorulmaz; kart satış + ayrı gideri gösterir, tek evet ikisini yazar. */
    public function test_till_with_expense_skips_cross_check_question(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'tool_use', 'id' => 'tu', 'name' => 'check',
            'input' => ['same' => false, 'question' => "1.400 TL'lik gider ayrıca düşülecek mi, yoksa sadece kasa mı?"]]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1]])]);
        $this->chat(['type' => 'entry', 'data' => $this->till(), 'text' => '']);
        $sales = Sale::count();
        $expenses = Expense::count();

        $reply = $this->send(self::TILL);
        $this->assertStringNotContainsString('ayrıca düşülecek', $reply);
        $this->assertStringContainsString('24.557,00', $reply);
        $this->assertStringContainsString('+ Gider:* 1.400,00', $reply);

        $this->assertStringContainsString('Gider kaydedildi: 1.400,00', $this->send('evet'));
        $this->assertSame($sales + 1, Sale::count());
        $this->assertSame($expenses + 1, Expense::count());
    }

    /** Soru cevaplanınca ("Ödeme") AI gideri "Sırada"ya koysa da gider taslakta kalır; sıraya ikinci kez girmez. */
    public function test_answer_turn_keeps_extra_expense_and_drops_duplicate_next_item(): void
    {
        Http::fake();
        WhatsappPendingExpense::create(['phone' => self::PHONE, 'status' => 'awaiting_confirmation', 'summary' => '❓ soru',
            'extracted' => $this->till(['source_text' => self::TILL, 'question' => 'Gider ayrı mı?', 'cross_question' => 'Gider ayrı mı?',
                'extra_expense' => ['amount' => 1400, 'description' => 'Akkuş ergenekon şube gideri']])]);
        $this->chat(['type' => 'entry', 'data' => $this->till(['is_new_entry' => false, 'next_items' => '1400 TL gider']), 'text' => '']);
        $sales = Sale::count();
        $expenses = Expense::count();

        $reply = $this->send('Ödeme');
        $this->assertStringContainsString('+ Gider:* 1.400,00', $reply);
        $this->assertStringNotContainsString('Sırada', $reply);

        $done = $this->send('evet');
        $this->assertStringContainsString('Gider kaydedildi: 1.400,00', $done);
        $this->assertStringNotContainsString('Sıradaki', $done);
        $this->assertSame($sales + 1, Sale::count());
        $this->assertSame($expenses + 1, Expense::count());
        $this->assertSame(0, WhatsappPendingExpense::where('phone', self::PHONE)->where('status', 'awaiting_confirmation')->count());
    }

    /** Sıradaki kalem "1400 TL gider": asıl kasa mesajı yeniden satış kurmaz — sıradaki taslak yalnız 1.400 gider. */
    public function test_next_item_from_till_message_does_not_rebuild_the_sale(): void
    {
        Http::fake();
        WhatsappPendingExpense::create(['phone' => self::PHONE, 'status' => 'awaiting_confirmation', 'summary' => '🧾 kasa',
            'extracted' => $this->till(['source_text' => self::TILL, 'next_items' => '1400 TL gider'])]);
        $this->chat(['type' => 'entry', 'text' => '', 'data' => array_merge($this->till(), [
            'kind' => ExpenseExtractor::KIND_EXPENSE, 'cash_amount' => null, 'card_amount' => null, 'amount' => 1400,
            'description' => 'Ergenekon şube gideri'])]);
        $sales = Sale::count();

        $reply = $this->send('evet');
        $this->assertStringContainsString('Sıradaki', $reply);
        $this->assertSame($sales + 1, Sale::count());
        $next = WhatsappPendingExpense::where('phone', self::PHONE)->where('status', 'awaiting_confirmation')->latest('id')->first();
        $this->assertNotNull($next);
        $this->assertSame(ExpenseExtractor::KIND_EXPENSE, $next->extracted['kind']);
        $this->assertEquals(1400, $next->extracted['amount']);
        $this->assertEmpty($next->extracted['extra_expense'] ?? null);
    }
}
