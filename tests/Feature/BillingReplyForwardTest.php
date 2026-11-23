<?php

namespace Tests\Feature;

use App\Models\WhatsappMessage;
use App\Services\Agent\AgentContext;
use App\Support\ModuleProfiles;
use App\Support\Phone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Ücret mesajına (kind billing) gelen cevabın kopyası yöneticiye gider (kullanıcı 23.11, Akkuş ücretlendirme). */
class BillingReplyForwardTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905558880088';

    private const ADMIN = '905551112233';

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('trade', []), 'app.profile' => 'trade',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 888 00 88'],
            'services.anthropic.chat_assistant' => false,
            'services.twilio.sid' => 'AC_test', 'services.twilio.token' => 'tok', 'services.whatsapp.from' => '905550000000',
            'services.whatsapp.admin_phones' => [self::ADMIN]]);
        Http::fake();
    }

    private function billing(int $hoursAgo, string $kind = 'billing'): void
    {
        $m = WhatsappMessage::create(['phone' => Phone::normalize(self::PHONE), 'direction' => 'out', 'body' => 'Ücret mesajı', 'kind' => $kind]);
        $m->forceFill(['created_at' => now()->subHours($hoursAgo)])->save();
    }

    private function send(string $body): void
    {
        $this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])->assertOk();
    }

    private function forwarded(): \Illuminate\Support\Collection
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'api.twilio.com') && ($r->data()['To'] ?? null) === 'whatsapp:+' . self::ADMIN);
    }

    public function test_reply_within_72_hours_is_copied_to_admin(): void
    {
        $this->billing(5);
        $this->send('Tamam yıllık ödeyeceğim');

        $copies = $this->forwarded();
        $this->assertCount(1, $copies);
        $this->assertStringContainsString('ücret mesajına cevap yazdı', $copies->first()->data()['Body']);
        $this->assertStringContainsString('«Tamam yıllık ödeyeceğim»', $copies->first()->data()['Body']);
    }

    public function test_old_billing_or_other_messages_are_not_copied(): void
    {
        $this->billing(73);
        $this->billing(1, 'announcement');
        $this->send('Dünkü ciro');

        $this->assertCount(0, $this->forwarded());
    }

    public function test_agent_core_has_the_fee_rule(): void
    {
        $core = AgentContext::core();
        $this->assertStringContainsString("Hesap Asistanım'ın kendi ücreti", $core);
        $this->assertStringContainsString("Serkan Bey'le iletişime geçebilirsiniz", $core);
    }
}
