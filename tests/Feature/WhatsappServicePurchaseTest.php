<?php

namespace Tests\Feature;

use App\Models\WhatsappMessage;
use App\Services\Whatsapp\ChatAssistant;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/** Yeni Yapı 19.10 22:37: "Nakliyeciye 20 bin ödedim" → hizmet; stoklu firmada "📦 Ne aldın? stoğa girer" satırı çıkmaz (mal alışında çıkar). */
class WhatsappServicePurchaseTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905557770025';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'modules' => ModuleProfiles::resolve('trade', ['smart_assistant' => true]), 'app.profile' => 'trade',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 777 00 25'],
        ]);
        Http::fake();
        WhatsappMessage::create(['phone' => '905557770025', 'direction' => 'in', 'body' => 'önceki']);
    }

    private function card(?bool $service): string
    {
        $mock = Mockery::mock(ChatAssistant::class);
        $mock->shouldReceive('respond')->andReturn(['type' => 'entry', 'text' => '', 'data' => [
            'kind' => ExpenseExtractor::KIND_PURCHASE, 'amount' => 20000, 'party_name' => 'Testnakliyeci', 'description' => 'Nakliye ödemesi',
            'paid' => true, 'is_service' => $service, 'is_new_entry' => true, 'confidence' => 'high', 'items' => [], 'date' => now()->toDateString()]]);
        $this->app->instance(ChatAssistant::class, $mock);

        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => 'Nakliyeciye 20 bin ödedim', 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    public function test_service_purchase_does_not_ask_what_was_bought(): void
    {
        $card = $this->card(true);
        $this->assertStringContainsString('20.000,00', $card);
        $this->assertStringNotContainsString('Ne aldın', $card);
    }

    public function test_goods_or_unclear_purchase_still_asks(): void
    {
        $this->assertStringContainsString('Ne aldın', $this->card(null));
    }
}
