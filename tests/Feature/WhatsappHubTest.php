<?php

namespace Tests\Feature;

use App\Models\HubFirm;
use App\Models\HubPhone;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\HubSignature;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * WhatsApp hub'ı: tek numaraya gelen mesaj telefona göre firmaya iletilir;
 * firma sadece Twilio'dan ya da imzası doğru hub'dan gelen isteği kabul eder.
 */
class WhatsappHubTest extends TestCase
{
    use DatabaseTransactions;

    private const TWIML_OK = '<?xml version="1.0" encoding="UTF-8"?><Response><Message>firmadan cevap</Message></Response>';

    protected function setUp(): void
    {
        parent::setUp();
        // Twilio imzası kendi testinde; burada hub tarafı test ediliyor.
        config(['services.twilio.verify_signature' => false]);
    }

    private function hubRole(): void
    {
        config(['app.role' => 'hub']);
    }

    private function firm(array $attrs = []): HubFirm
    {
        return HubFirm::create(array_merge(['name' => 'Yıldız', 'url' => 'https://yildiz.test/'], $attrs));
    }

    public function test_hub_rejects_unknown_phone_without_forwarding(): void
    {
        $this->hubRole();
        Http::fake();

        $res = $this->post('/whatsapp/webhook', ['From' => 'whatsapp:+905001112233', 'Body' => 'merhaba'])
            ->assertOk()->getContent();

        $this->assertStringContainsString('kayıtlı değil', $res);
        Http::assertNothingSent();
    }

    public function test_hub_forwards_to_phone_firm_signed_and_returns_its_reply(): void
    {
        $this->hubRole();
        $firm = $this->firm();
        HubPhone::create(['phone' => '0532 123 45 67', 'hub_firm_id' => $firm->id, 'name' => 'Ferhat']);
        Http::fake(['yildiz.test/*' => Http::response(self::TWIML_OK, 200, ['Content-Type' => 'text/xml'])]);

        $params = ['From' => 'whatsapp:+905321234567', 'Body' => 'Ahmet 30 bin gönderdi', 'NumMedia' => '0', 'MessageSid' => 'SM1'];
        $res = $this->post('/whatsapp/webhook', $params)->assertOk();

        $this->assertSame(self::TWIML_OK, $res->getContent());
        Http::assertSent(function (ClientRequest $r) use ($firm, $params) {
            return $r->url() === 'https://yildiz.test/whatsapp/webhook'
                && $r['Body'] === $params['Body']
                && HubSignature::verify($r->data(), $firm->secret, $r->header(HubSignature::HEADER)[0], $r->header(HubSignature::TIMESTAMP_HEADER)[0]);
        });
    }

    public function test_hub_skips_inactive_phone_and_firm(): void
    {
        $this->hubRole();
        $firm = $this->firm(['is_active' => false]);
        HubPhone::create(['phone' => '05321234567', 'hub_firm_id' => $firm->id]);
        Http::fake();

        $res = $this->post('/whatsapp/webhook', ['From' => 'whatsapp:+905321234567', 'Body' => 'x'])->getContent();

        $this->assertStringContainsString('kayıtlı değil', $res);
        Http::assertNothingSent();
    }

    public function test_hub_answers_politely_when_firm_is_down(): void
    {
        $this->hubRole();
        $firm = $this->firm();
        HubPhone::create(['phone' => '05321234567', 'hub_firm_id' => $firm->id]);
        Http::fake(['yildiz.test/*' => Http::response('boom', 500)]);

        $res = $this->post('/whatsapp/webhook', ['From' => 'whatsapp:+905321234567', 'Body' => 'x'])->getContent();

        $this->assertStringContainsString('Şu an cevap veremiyorum', $res);
    }

    public function test_firm_accepts_valid_hub_signature_even_without_local_allowlist(): void
    {
        config([
            'services.twilio.verify_signature' => true,
            'services.twilio.token' => 'twilio-token',
            'services.twilio.allowed_phones' => [],
            'services.hub.secret' => 'firma-sirri',
        ]);
        Http::fake();
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->once()->andReturn(['kind' => ExpenseExtractor::KIND_BALANCE_QUERY, 'party_id' => null, 'party_name' => null, 'amount' => 0.0, 'items' => [], 'question' => null, 'is_new_entry' => false]);
        $this->app->instance(ExpenseExtractor::class, $mock);

        $params = ['From' => 'whatsapp:+905321234567', 'Body' => 'borcum ne', 'NumMedia' => '0'];
        $res = $this->withHeaders(HubSignature::headers($params, 'firma-sirri'))
            ->post('/whatsapp/webhook', $params)
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('kayıtlı değil', $res);
    }

    public function test_firm_rejects_wrong_or_stale_hub_signature(): void
    {
        config(['services.twilio.verify_signature' => true, 'services.hub.secret' => 'firma-sirri']);
        $params = ['From' => 'whatsapp:+905321234567', 'Body' => 'toplam alacağım'];

        $this->withHeaders(HubSignature::headers($params, 'baska-sir'))
            ->post('/whatsapp/webhook', $params)->assertForbidden();

        $this->withHeaders(HubSignature::headers($params, 'firma-sirri', now()->subMinutes(10)->timestamp))
            ->post('/whatsapp/webhook', $params)->assertForbidden();
    }

    public function test_phone_is_stored_normalized(): void
    {
        $firm = $this->firm();
        $phone = HubPhone::create(['phone' => '+90 (532) 123 45 67', 'hub_firm_id' => $firm->id]);

        $this->assertSame('905321234567', $phone->phone);
        $this->assertSame('https://yildiz.test', $firm->url);
        $this->assertSame(48, strlen($firm->secret));
    }
}
