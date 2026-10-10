<?php

namespace Tests\Feature;

use App\Models\HubFirm;
use App\Models\HubPhone;
use App\Support\WelcomeMessage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Hub'da numara eklenince "hos_geldin" şablonu ({{1}} = ilk ad) — kişi henüz yazmadığı için şablon şart. */
class WelcomeMessageTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.twilio.sid' => 'ACtest',
            'services.twilio.token' => 'tok',
            'services.whatsapp.from' => '+14786665916',
            'services.whatsapp.welcome_content_sid' => 'HXwelcome',
        ]);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    }

    private function phone(?string $name): HubPhone
    {
        $firm = HubFirm::create(['name' => 'Karşılama Şablon Test', 'url' => 'https://example.test']);

        return HubPhone::create(['phone' => '0537 111 22 33', 'hub_firm_id' => $firm->id, 'name' => $name]);
    }

    public function test_sends_welcome_template_with_first_name(): void
    {
        $this->assertTrue(WelcomeMessage::send($this->phone('Burak Şimşek')));

        Http::assertSent(fn (ClientRequest $r) => $r['To'] === 'whatsapp:+905371112233'
            && $r['ContentSid'] === 'HXwelcome'
            && json_decode($r['ContentVariables'], true) === ['1' => 'Burak']);
    }

    public function test_without_name_uses_firm_name(): void
    {
        WelcomeMessage::send($this->phone(null));

        Http::assertSent(fn (ClientRequest $r) => json_decode($r['ContentVariables'], true) === ['1' => 'Karşılama Şablon Test']);
    }

    public function test_not_configured_without_template_sid(): void
    {
        config(['services.whatsapp.welcome_content_sid' => null]);

        $this->assertFalse(WelcomeMessage::configured());
    }
}
