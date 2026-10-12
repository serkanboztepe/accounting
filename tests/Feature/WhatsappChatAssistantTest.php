<?php

namespace Tests\Feature;

use App\Models\PartyLedgerEntry;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\ChatAssistant;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Sohbet asistanı (firma bazında "Akıllı WhatsApp asistanı" modülü): varsayılan kapalı; açıkken
 * taslak/onay yine kontrolcüde, AI onayı mesajda onay kelimesi yoksa kayda dönüşmez. AI mock'lanır.
 */
class WhatsappChatAssistantTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905557771234';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.twilio.verify_signature' => false,
            'services.twilio.allowed_phones' => ['0555 777 12 34'],
            'services.anthropic.chat_assistant' => false,
            'modules.expenses' => true,
            'modules.cari_supplier' => true,
            'modules.projects' => false,
            'modules.smart_assistant' => true,
        ]);
        Http::fake();
        WhatsappMessage::create(['phone' => '905557771234', 'direction' => 'in', 'body' => 'önceki']);
    }

    private function sale(float $amount, string $party, ?string $next = null): array
    {
        return [
            'kind' => ExpenseExtractor::KIND_SALE, 'items' => [], 'totals_side' => null, 'date_from' => null, 'date_to' => null,
            'payment_type' => null, 'amount' => $amount, 'date' => now()->format('Y-m-d'), 'due_date' => null,
            'description' => 'İş', 'project_id' => null, 'project_name' => null, 'party_id' => null, 'party_name' => $party,
            'category_id' => null, 'category_name' => null, 'paid' => null, 'confidence' => 'high', 'question' => null,
            'is_new_entry' => true, 'debt_side' => null, 'reply' => null, 'payment_purpose' => null, 'settles_expense_id' => null,
            'next_items' => $next,
        ];
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

    public function test_module_is_off_by_default_in_every_profile(): void
    {
        foreach ([null, 'mimar', 'muteahhit', 'toptanci', 'alacak_verecek'] as $profile) {
            $this->assertFalse(ModuleProfiles::resolve($profile)['smart_assistant'], (string) $profile);
        }
        $this->assertTrue(ModuleProfiles::resolve('mimar', ['smart_assistant' => true])['smart_assistant']);
    }

    public function test_module_off_uses_old_extractor_and_never_calls_chat(): void
    {
        config(['modules.smart_assistant' => false]);
        $chat = Mockery::mock(ChatAssistant::class);
        $chat->shouldNotReceive('respond');
        $this->app->instance(ChatAssistant::class, $chat);
        $extractor = Mockery::mock(ExpenseExtractor::class);
        $extractor->shouldReceive('extract')->once()->andReturn($this->sale(30000, 'Eski Yol Ali'));
        $this->app->instance(ExpenseExtractor::class, $extractor);

        $reply = $this->send("Ali'ye 30 bin iş yaptım");

        $this->assertStringContainsString('Onaylamak için *evet*', $reply);
    }

    public function test_chat_entry_shows_summary_and_plain_evet_saves(): void
    {
        $this->chat(['type' => 'entry', 'data' => $this->sale(30000, 'Sohbet Ali'), 'text' => '']);

        $reply = $this->send("Ali'ye 30 bin iş yaptım");
        $this->assertStringContainsString('Kaydedeyim mi?', $reply);
        $this->assertSame(0, PartyLedgerEntry::whereHas('party', fn ($q) => $q->where('name', 'Sohbet Ali'))->count());

        $this->assertStringContainsString('Kaydedildi', $this->send('Evet'));
        $this->assertSame(1, PartyLedgerEntry::whereHas('party', fn ($q) => $q->where('name', 'Sohbet Ali'))->count());
    }

    public function test_ai_confirm_without_assent_word_does_not_save(): void
    {
        // Gerçekte ChatAssistant "Sılajcı yusuf" için confirm yerine ask_confirm döner (hasAssent).
        $this->chat(
            ['type' => 'entry', 'data' => $this->sale(67000, 'Onaysız Yusuf'), 'text' => ''],
            ['type' => 'ask_confirm'],
        );
        $this->send('Yusuf 67000');

        $reply = $this->send('Sılajcı yusuf');

        $this->assertStringContainsString('Kaydedeyim mi?', $reply);
        $this->assertSame(0, PartyLedgerEntry::whereHas('party', fn ($q) => $q->where('name', 'Onaysız Yusuf'))->count());
    }

    public function test_assent_words_and_typos(): void
    {
        foreach (['Evet', 'Evert', 'evt', 'Tmam', 'tmm', 'ok', 'Evet gider yaz', 'Ödendi evet', 'olur'] as $yes) {
            $this->assertTrue(ChatAssistant::hasAssent($yes), $yes);
        }
        foreach (['Sılajcı yusuf', 'Kg 600', 'hayır', 'iptal', 'ödendi'] as $no) {
            $this->assertFalse(ChatAssistant::hasAssent($no), $no);
        }
    }

    public function test_next_item_is_proposed_after_confirm(): void
    {
        $this->chat(
            ['type' => 'entry', 'data' => $this->sale(5400, 'Kalem Bir', '10.000 TL ustalık'), 'text' => ''],
            ['type' => 'entry', 'data' => $this->sale(10000, 'Kalem Bir'), 'text' => ''],
        );
        $this->send('5400 parça 10.000 ustalık');

        $reply = $this->send('Evet');

        $this->assertStringContainsString('Kaydedildi', $reply);
        $this->assertStringContainsString('Sıradaki', $reply);
        $this->assertStringContainsString('10.000,00', $reply);
    }
}
