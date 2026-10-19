<?php

namespace Tests\Feature;

use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ChatAssistant;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/** Akkuş 18.10 22:46: Ergenekon numarası kasayı girmiş (onay bekliyor), Emre "Ergenekon ciro ne kadar" → "henüz kasa yok" dendi. */
class WhatsappOtherPhonePendingTest extends TestCase
{
    use DatabaseTransactions;

    private const EMRE = 'whatsapp:+905557770023';

    private const SUBE = 'whatsapp:+905557770024';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'modules' => ModuleProfiles::resolve('trade', ['smart_assistant' => true, 'report_monthly' => true]), 'app.profile' => 'trade',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 777 00 23', '0555 777 00 24'],
        ]);
        Http::fake();
        foreach (['905557770023', '905557770024'] as $p) {
            WhatsappMessage::create(['phone' => $p, 'direction' => 'in', 'body' => 'önceki']);
        }
        WhatsappPendingExpense::create(['phone' => self::SUBE, 'status' => 'awaiting_confirmation', 'summary' => 'x',
            'extracted' => ['kind' => ExpenseExtractor::KIND_TRADE_SALE, 'amount' => 27361, 'description' => 'Ergenekon kasa', 'paid' => true]]);
    }

    private function chat(array $result): void
    {
        $mock = Mockery::mock(ChatAssistant::class);
        $mock->shouldReceive('respond')->andReturn($result);
        $this->app->instance(ChatAssistant::class, $mock);
    }

    private function send(string $from, string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => $from, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    public function test_chat_answer_mentions_other_phones_pending_till(): void
    {
        $this->chat(['type' => 'reply', 'text' => 'Bugün Ergenekon için kasa girişi yok.']);

        $reply = $this->send(self::EMRE, 'Ergenekon ciro ne kadar');

        $this->assertStringContainsString('Diğer numaradan onay bekleyen', $reply);
        $this->assertStringContainsString('…0024: Satış 27.361,00 ₺', $reply);
    }

    public function test_result_answer_mentions_it_too(): void
    {
        $this->chat(['type' => 'entry', 'text' => '', 'data' => ['kind' => ExpenseExtractor::KIND_MONTH_RESULT, 'amount' => 0, 'items' => [],
            'date_from' => now()->toDateString(), 'date_to' => now()->toDateString(), 'is_new_entry' => true, 'paid' => null, 'party_id' => null,
            'party_name' => null, 'project_id' => null, 'project_name' => null, 'description' => '', 'question' => null, 'date' => now()->toDateString()]]);

        $this->assertStringContainsString('…0024: Satış 27.361,00 ₺', $this->send(self::EMRE, 'bugünkü ciro'));
    }

    public function test_own_pending_and_old_drafts_are_not_listed(): void
    {
        WhatsappPendingExpense::where('phone', self::SUBE)->update(['updated_at' => now()->subHour()]);
        $this->chat(['type' => 'reply', 'text' => 'Kasa yok.']);

        $this->assertStringNotContainsString('Diğer numaradan', $this->send(self::EMRE, 'ciro ne kadar'));
        $this->assertStringNotContainsString('Diğer numaradan', $this->send(self::SUBE, 'ciro ne kadar'), 'kendi kaydı ayrıca söylenmez');
    }
}
