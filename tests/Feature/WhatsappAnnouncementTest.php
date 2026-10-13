<?php

namespace Tests\Feature;

use App\Models\HubAnnouncement;
use App\Models\HubFirm;
use App\Models\HubPhone;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\WhatsappSender;
use App\Support\Announcements;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Bekleyen duyuru: kişi bir sonraki yazdığında, işi bitince (onay bekleyen taslak yokken) cevabın
 * arkasından BİR KEZ gider; numaranın ilk mesajında hoş geldin gider, duyuru iptal olur (ikisi birden asla).
 */
class WhatsappAnnouncementTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905557774321';

    private const BODY = 'Merhaba Test Bey 👋 Asistanınız artık daha akıllı.';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.twilio.verify_signature' => false,
            'services.twilio.allowed_phones' => ['0555 777 43 21'],
            'modules.expenses' => true,
            'modules.cari_supplier' => true,
            'modules.projects' => false,
            'modules.smart_assistant' => false,
        ]);
        Http::fake();
    }

    private function entry(array $overrides): array
    {
        return array_merge([
            'kind' => ExpenseExtractor::KIND_EXPENSE, 'items' => [], 'totals_side' => null, 'date_from' => null, 'date_to' => null,
            'payment_type' => null, 'amount' => 0.0, 'date' => now()->format('Y-m-d'), 'due_date' => null, 'description' => '',
            'project_id' => null, 'project_name' => null, 'party_id' => null, 'party_name' => null, 'category_id' => null,
            'category_name' => null, 'paid' => null, 'confidence' => 'high', 'question' => null, 'is_new_entry' => false,
            'debt_side' => null, 'reply' => null, 'payment_purpose' => null, 'settles_expense_id' => null,
        ], $overrides);
    }

    private function ai(array ...$responses): void
    {
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturn(...$responses);
        $this->app->instance(ExpenseExtractor::class, $mock);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    private function announce(): HubAnnouncement
    {
        return HubAnnouncement::create(['phone' => '0555 777 43 21', 'body' => self::BODY]);
    }

    private function oldUser(): void
    {
        WhatsappMessage::create(['phone' => '905557774321', 'direction' => 'in', 'body' => 'önceki']);
    }

    public function test_sent_once_after_finished_reply(): void
    {
        $this->oldUser();
        $a = $this->announce();
        $this->ai(
            $this->entry(['kind' => ExpenseExtractor::KIND_HELP, 'reply' => 'Merhaba!']),
            $this->entry(['kind' => ExpenseExtractor::KIND_HELP, 'reply' => 'Tekrar merhaba!']),
        );

        $first = $this->send('selam');
        $this->assertStringContainsString(self::BODY, $first);
        $this->assertSame(2, substr_count($first, '<Message>'), 'cevap + duyuru ayrı balon');
        $this->assertSame('sent', $a->fresh()->status);

        $this->assertStringNotContainsString(self::BODY, $this->send('selam'), 'bir kez gider');
    }

    public function test_waits_while_draft_awaits_confirmation_then_sends_after_evet(): void
    {
        $this->oldUser();
        $a = $this->announce();
        $this->ai($this->entry(['amount' => 500, 'description' => 'Çay', 'paid' => true]));

        $this->assertStringNotContainsString(self::BODY, $this->send('500 tl çay'), 'iş bitmeden araya girmez');
        $this->assertSame('pending', $a->fresh()->status);

        $done = $this->send('evet');
        $this->assertStringContainsString('Kaydedildi', $done);
        $this->assertStringContainsString(self::BODY, $done);
        $this->assertSame('sent', $a->fresh()->status);
    }

    public function test_first_contact_gets_welcome_and_announcement_is_cancelled(): void
    {
        $firm = HubFirm::create(['name' => 'Duyuru Test', 'url' => 'https://example.test']);
        HubPhone::create(['phone' => '0555 777 43 21', 'hub_firm_id' => $firm->id])
            ->forceFill(['created_at' => '2026-10-12 10:00:00'])->save();
        $a = $this->announce();
        $this->ai($this->entry(['kind' => ExpenseExtractor::KIND_HELP, 'reply' => 'Merhaba!']));

        $reply = $this->send('selam');

        $this->assertStringContainsString('Hoş geldin', $reply);
        $this->assertStringNotContainsString(self::BODY, $reply);
        $this->assertSame('cancelled', $a->fresh()->status);
    }

    public function test_no_announcement_no_extra_message(): void
    {
        $this->oldUser();
        $this->ai($this->entry(['kind' => ExpenseExtractor::KIND_HELP, 'reply' => 'Merhaba!']));

        $this->assertSame(1, substr_count($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => 'selam', 'NumMedia' => 0])->getContent(), '<Message>'));
    }

    // ------------------------------------------------ "yenilikler" şablonu: yazmayana da ulaşsın

    private function sender(): \Mockery\MockInterface
    {
        $sender = Mockery::mock(WhatsappSender::class);
        $this->app->instance(WhatsappSender::class, $sender);

        return $sender;
    }

    public function test_push_sends_text_inside_24h_window(): void
    {
        $this->oldUser();
        $firm = HubFirm::create(['name' => 'Duyuru Push', 'url' => 'https://example.test']);
        $a = HubAnnouncement::create(['hub_firm_id' => $firm->id, 'phone' => '0555 777 43 21', 'body' => self::BODY]);
        $this->sender()->shouldReceive('sendText')->once()->with('905557774321', self::BODY)->andReturn(true);

        $lines = Announcements::push($firm->id, app(WhatsappSender::class));

        $this->assertStringContainsString('metin ✓', implode("\n", $lines));
        $this->assertSame('sent', $a->fresh()->status);
    }

    public function test_push_sends_template_outside_window_then_button_shows_body_once(): void
    {
        WhatsappMessage::create(['phone' => '905557774321', 'direction' => 'in', 'body' => 'eski'])
            ->forceFill(['created_at' => now()->subDays(3)])->save();
        $firm = HubFirm::create(['name' => 'Duyuru Push', 'url' => 'https://example.test']);
        HubPhone::create(['phone' => '0555 777 43 21', 'hub_firm_id' => $firm->id, 'name' => 'Ferhat Yıldız']);
        $a = HubAnnouncement::create(['hub_firm_id' => $firm->id, 'phone' => '0555 777 43 21', 'body' => self::BODY]);
        config(['services.whatsapp.news_content_sid' => 'HXnews']);
        Cache::put('wa-template-approval:HXnews', 'approved', 600);
        $this->sender()->shouldReceive('sendTemplate')->once()->with('905557774321', 'HXnews', ['1' => 'Ferhat'], 'yenilikler')->andReturn(true);

        Announcements::push($firm->id, app(WhatsappSender::class));
        $this->assertSame('notified', $a->fresh()->status);

        // Düğme cevabı (ButtonPayload) → metnin kendisi, AI'sız.
        $reply = html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => 'Yenilikleri göster', 'ButtonPayload' => Announcements::BUTTON, 'NumMedia' => 0])->getContent(), ENT_QUOTES | ENT_XML1);
        $this->assertStringContainsString(self::BODY, $reply);
        $this->assertSame(1, substr_count($reply, self::BODY), 'aynı mesajda iki kez değil');
        $this->assertSame('sent', $a->fresh()->status);

        // Tekrar "yenilikler" → yine en son duyuru (bilgi), yeni gönderim sayılmaz.
        $this->assertStringContainsString(self::BODY, $this->send('yenilikler'));
    }

    public function test_push_without_approved_template_waits_for_user(): void
    {
        WhatsappMessage::create(['phone' => '905557774321', 'direction' => 'in', 'body' => 'eski'])
            ->forceFill(['created_at' => now()->subDays(3)])->save();
        $firm = HubFirm::create(['name' => 'Duyuru Push', 'url' => 'https://example.test']);
        $a = HubAnnouncement::create(['hub_firm_id' => $firm->id, 'phone' => '0555 777 43 21', 'body' => self::BODY]);
        config(['services.whatsapp.news_content_sid' => 'HXnews2']);
        Cache::put('wa-template-approval:HXnews2', 'pending', 600);
        $this->sender()->shouldNotReceive('sendTemplate', 'sendText');

        $this->assertStringContainsString('yazınca gidecek', implode("\n", Announcements::push($firm->id, app(WhatsappSender::class))));
        $this->assertSame('pending', $a->fresh()->status);
    }

    public function test_yenilikler_without_any_announcement(): void
    {
        $this->oldUser();
        $this->assertStringContainsString('yeni bir duyuru yok', $this->send('yenilikler'));
    }
}
