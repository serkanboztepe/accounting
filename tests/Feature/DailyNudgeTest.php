<?php

namespace Tests\Feature;

use App\Models\HubFirm;
use App\Models\HubPhone;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\WhatsappSender;
use App\Support\DailyNudge;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Akşam mesajı: kime gider, kime gitmez, düğme cevapları ve "bana yazma". */
class DailyNudgeTest extends TestCase
{
    use DatabaseTransactions;

    private const NUMBER = '905371112233';

    private HubFirm $firm;

    private string $approvalStatus = 'approved';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.twilio.sid' => 'ACtest',
            'services.twilio.token' => 'tok',
            'services.whatsapp.from' => '+14786665916',
            'services.whatsapp.nudge_content_sid' => 'HXnudge',
            'services.twilio.verify_signature' => false,
            'services.twilio.allowed_phones' => ['0537 111 22 33'],
        ]);
        Cache::flush();
        Http::fake([
            'content.twilio.com/*' => fn () => Http::response(['whatsapp' => ['status' => $this->approvalStatus]]),
            'api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-12 19:00', 'Europe/Istanbul'));
        $this->firm = HubFirm::create(['name' => 'Akşam Test', 'url' => 'https://example.test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** İstanbul saati → UTC an (veritabanına uygulama saat diliminde yazılsın). */
    private function at(string $istanbul): Carbon
    {
        return Carbon::parse($istanbul, 'Europe/Istanbul')->utc();
    }

    private function phone(array $attrs = []): HubPhone
    {
        return HubPhone::create($attrs + ['phone' => self::NUMBER, 'hub_firm_id' => $this->firm->id, 'name' => 'Erkan Yeşilyurt']);
    }

    private function wrote(string $at): void
    {
        WhatsappMessage::create(['phone' => self::NUMBER, 'direction' => 'in', 'body' => 'x'])
            ->forceFill(['created_at' => $this->at($at)])->save();
    }

    private function nudge(): array
    {
        return DailyNudge::run($this->firm->id, app(WhatsappSender::class));
    }

    private function sentCount(): int
    {
        return count(Http::recorded(fn (ClientRequest $r) => str_contains($r->url(), 'Messages.json')));
    }

    public function test_sends_template_with_first_name_and_counts_unanswered(): void
    {
        $phone = $this->phone();
        $this->wrote('2026-10-11 20:00');

        $this->nudge();

        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'Messages.json')
            && $r['To'] === 'whatsapp:+' . self::NUMBER
            && $r['ContentSid'] === 'HXnudge'
            && json_decode($r['ContentVariables'], true) === ['1' => 'Erkan']);
        $this->assertSame(1, $phone->fresh()->nudge_unanswered);
        $this->assertNotNull($phone->fresh()->nudge_sent_at);
        $this->assertTrue(WhatsappMessage::where('kind', 'nudge')->exists());
    }

    public function test_not_sent_if_wrote_today_or_disabled_or_already_sent(): void
    {
        $this->phone();
        $this->wrote('2026-10-12 09:30');
        $this->nudge();
        $this->assertSame(0, $this->sentCount());

        WhatsappMessage::query()->delete();
        HubPhone::where('phone', self::NUMBER)->update(['nudge_enabled' => false]);
        $this->nudge();
        $this->assertSame(0, $this->sentCount());

        HubPhone::where('phone', self::NUMBER)->update(['nudge_enabled' => true]);
        $this->nudge();
        $this->nudge(); // aynı akşam ikinci çalışma
        $this->assertSame(1, $this->sentCount());
    }

    /** 3 kez cevapsız → haftada bir; yazınca sayaç sıfırlanır (günlüğe döner). */
    public function test_three_unanswered_slows_to_weekly_and_answer_resets(): void
    {
        $phone = $this->phone(['nudge_unanswered' => 3, 'nudge_sent_at' => $this->at('2026-10-09 19:00')]);
        $this->nudge();
        $this->assertSame(0, $this->sentCount());

        $phone->forceFill(['nudge_sent_at' => $this->at('2026-10-05 19:00')])->save();
        $this->nudge();
        $this->assertSame(1, $this->sentCount());

        DailyNudge::answered($phone->fresh());
        $this->assertSame(0, $phone->fresh()->nudge_unanswered);
        $this->assertSame('Günlük', DailyNudge::status($phone->fresh()));
    }

    /** Şablon onaysızken yalnız son 24 saatte yazana düz metin (düğmesiz, "bugün yok yaz" ipucu). */
    public function test_unapproved_template_sends_plain_text_only_inside_window(): void
    {
        Cache::flush();
        $this->approvalStatus = 'pending';
        $this->phone();

        $this->nudge(); // hiç yazmamış → pencere dışı
        $this->assertSame(0, $this->sentCount());

        $this->wrote('2026-10-11 21:00');
        $this->nudge();
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'Messages.json')
            && ! isset($r['ContentSid']) && str_contains($r['Body'], 'bugün yok'));
    }

    private function send(string $body, array $extra = []): string
    {
        return $this->post('/whatsapp/webhook', ['From' => 'whatsapp:+' . self::NUMBER, 'Body' => $body, 'NumMedia' => 0] + $extra)
            ->assertOk()->getContent();
    }

    public function test_button_replies_without_ai(): void
    {
        $this->phone();

        $this->assertStringContainsString('iyi akşamlar', $this->send('Bugün yok', ['ButtonPayload' => DailyNudge::BUTTON_NONE]));
        $this->assertStringContainsString('ne oldu?', $this->send('Kayıt gireceğim'));
        $this->assertStringContainsString('ne zaman hatırlatayım', $this->send('Hatırlatma kur', ['ButtonPayload' => DailyNudge::BUTTON_REMINDER]));
    }

    public function test_bana_yazma_turns_off_and_can_turn_back_on(): void
    {
        $phone = $this->phone();

        $this->assertStringContainsString('kapattım', $this->send('Bana yazma'));
        $this->assertFalse($phone->fresh()->nudge_enabled);
        $this->assertSame('Kapalı', DailyNudge::status($phone->fresh()));

        $this->assertStringContainsString('açtım', $this->send('akşam mesajı aç'));
        $this->assertTrue($phone->fresh()->nudge_enabled);
    }
}
