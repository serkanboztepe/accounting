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

    private function phone(?string $name, string $number = '0537 111 22 33'): HubPhone
    {
        $firm = HubFirm::create(['name' => 'Karşılama Şablon Test', 'url' => 'https://example.test']);

        return HubPhone::create(['phone' => $number, 'hub_firm_id' => $firm->id, 'name' => $name]);
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

    /** Gönderildiyse "Hoş geldin gönder" gizlenir; eski (karşılama öncesi) numaralarda hiç görünmez. */
    public function test_button_hidden_after_sending_and_for_old_numbers(): void
    {
        $phone = $this->phone('Burak Şimşek');
        $phone->forceFill(['created_at' => '2026-10-12 10:00:00'])->save();
        $this->assertTrue(WelcomeMessage::canSendTo($phone->fresh()));

        WelcomeMessage::send($phone);
        $this->assertNotNull($phone->fresh()->welcomed_at);
        $this->assertFalse(WelcomeMessage::canSendTo($phone->fresh()));

        $old = $this->phone('Eski Müşteri', '0537 999 88 77');
        $old->forceFill(['created_at' => '2026-10-08 10:00:00'])->save();
        $this->assertFalse(WelcomeMessage::canSendTo($old->fresh()));
    }

    public function test_approval_status_is_read_from_twilio(): void
    {
        Http::fake(['content.twilio.com/*' => Http::response(['whatsapp' => ['status' => 'pending']])]);

        $this->assertSame('pending', WelcomeMessage::approvalStatus());
    }

    public function test_not_configured_without_template_sid(): void
    {
        config(['services.whatsapp.welcome_content_sid' => null]);

        $this->assertFalse(WelcomeMessage::configured());
    }
}
