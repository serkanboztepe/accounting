<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\Reminders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Kayıtlı hatırlatmayı değiştirme + hatırlatma saati (Ferhat 25.10: "İş bankası kredi kartları son ödeme tarihi 30 Ekim
 * hatırlatma yap" → evet → "Hatırlatmaları saat 10:00 da yap" → asistan "10:00'a ayarlıyorum" dedi, HİÇBİR ŞEY değişmedi).
 * AI sahte (gerçek AI ile denenmedi). "Şimdi": 25 Ekim 2026 17:43.
 */
class WhatsappReminderUpdateTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905556660001';

    private const NORM = '905556660001';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-25 17:43', Reminder::TIMEZONE));
        config([
            'services.twilio.verify_signature' => false,
            'services.twilio.allowed_phones' => ['0555 666 00 01'],
            'services.twilio.sid' => 'ACtest',
            'services.twilio.token' => 'tok',
            'services.whatsapp.from' => '+14786665916',
        ]);
        Http::fake(['*' => Http::response('', 200)]);
        WhatsappMessage::create(['phone' => self::NORM, 'direction' => 'in', 'body' => 'önceki']);
        Reminder::where('phone', self::NORM)->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeAi(array ...$responses): void
    {
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturn(...$responses);
        $this->app->instance(ExpenseExtractor::class, $mock);
    }

    private function entry(array $overrides): array
    {
        return array_merge([
            'kind' => ExpenseExtractor::KIND_REMINDER, 'items' => [], 'totals_side' => null, 'date_from' => null, 'date_to' => null,
            'payment_type' => null, 'amount' => 0.0, 'date' => '2026-10-25', 'due_date' => null, 'description' => '',
            'project_id' => null, 'project_name' => null, 'party_id' => null, 'party_name' => null, 'category_id' => null,
            'category_name' => null, 'paid' => null, 'confidence' => 'high', 'question' => null, 'is_new_entry' => false,
            'debt_side' => null, 'reply' => null, 'payment_purpose' => null, 'settles_expense_id' => null,
            'event_date' => null, 'event_time' => null, 'notify_time' => null, 'lead_minutes' => null, 'is_alarm' => false,
            'repeat' => null, 'updates_reminder_id' => null,
        ], $overrides);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    private function card(): Reminder
    {
        return Reminder::create(['phone' => self::NORM, 'text' => 'İş Bankası kredi kartı son ödeme tarihi', 'event_date' => '2026-10-30',
            'next_fire_at' => Carbon::parse('2026-10-29 08:00', Reminder::TIMEZONE)->setTimezone(config('app.timezone')), 'status' => 'active']);
    }

    private function local(?Carbon $at): string
    {
        return $at->copy()->setTimezone(Reminder::TIMEZONE)->format('Y-m-d H:i');
    }

    public function test_ferhat_change_time_updates_the_same_reminder_and_keeps_both_days(): void
    {
        $r = $this->card();
        // AI saatsiz olaya gelen saati event_time olarak da verse hatırlatma saati değişir (olayın saati değil).
        $this->fakeAi($this->entry(['updates_reminder_id' => $r->id, 'event_time' => '10:00']));

        $card = $this->send('Hatırlatmaları saat 10:00 da yap');
        $this->assertStringContainsString('Hatırlatma değişiyor', $card);
        $this->assertStringContainsString('Şu an: 29 Ekim Perşembe 08:00 · 30 Ekim Cuma 08:00', $card);
        $this->assertStringContainsString('Yeni: 29 Ekim Perşembe 10:00 · 30 Ekim Cuma 10:00', $card);
        $this->assertSame('2026-10-29 08:00', $this->local($r->fresh()->next_fire_at), 'onaydan önce değişmez');

        $done = $this->send('evet');
        $this->assertStringContainsString('Hatırlatma güncellendi', $done);
        $this->assertSame(1, Reminder::where('phone', self::NORM)->count(), 'yeni hatırlatma AÇILMAZ, aynısı değişir');
        $r->refresh();
        $this->assertSame('2026-10-29 10:00', $this->local($r->next_fire_at));
        $this->assertSame('10:00', substr((string) $r->notify_time, 0, 5));
        $this->assertNull($r->event_time, 'olay saatsiz kalır');
        // 29'u gönderildikten sonra sıradaki 30 Ekim 10:00.
        $r->scheduleNext(Carbon::parse('2026-10-29 10:01', Reminder::TIMEZONE));
        $this->assertSame('2026-10-30 10:00', $this->local($r->next_fire_at));
    }

    public function test_change_date(): void
    {
        $r = $this->card();
        $this->fakeAi($this->entry(['updates_reminder_id' => $r->id, 'event_date' => '2026-10-31']));

        $this->assertStringContainsString('Yeni: 30 Ekim Cuma 08:00 · 31 Ekim Cumartesi 08:00', $this->send('tarihi 31 yap'));
        $this->send('evet');

        $this->assertSame('2026-10-31', $r->fresh()->event_date->toDateString());
        $this->assertSame(1, Reminder::where('phone', self::NORM)->count());
    }

    public function test_cancel_keeps_old_reminder(): void
    {
        $r = $this->card();
        $this->fakeAi($this->entry(['updates_reminder_id' => $r->id, 'notify_time' => '10:00']));

        $this->send('hatırlatmaları 10da yap');
        $this->send('iptal');

        $this->assertNull($r->fresh()->notify_time);
        $this->assertSame('2026-10-29 08:00', $this->local($r->fresh()->next_fire_at));
    }

    public function test_new_day_reminder_with_notify_time(): void
    {
        $this->fakeAi($this->entry(['description' => 'Kredi kartı son ödeme', 'event_date' => '2026-10-30', 'notify_time' => '10:00']));

        $this->assertStringContainsString('Hatırlatacağım: 29 Ekim Perşembe 10:00 · 30 Ekim Cuma 10:00',
            $this->send("30 Ekim kredi kartı son ödeme, sabah 10'da hatırlat"));
        $this->send('evet');

        $this->assertSame('2026-10-29 10:00', $this->local(Reminder::where('phone', self::NORM)->sole()->next_fire_at));
    }

    public function test_another_phones_reminder_is_never_changed(): void
    {
        $other = Reminder::create(['phone' => '905559999999', 'text' => 'Başkasının', 'event_date' => '2026-10-30',
            'next_fire_at' => Carbon::parse('2026-10-29 08:00', Reminder::TIMEZONE), 'status' => 'active']);
        $this->fakeAi($this->entry(['updates_reminder_id' => $other->id, 'notify_time' => '10:00', 'description' => 'Kira', 'event_date' => '2026-11-01']));

        $card = $this->send('hatırlatmayı 10da yap');
        $this->assertStringNotContainsString('değişiyor', $card, 'başka numaranın hatırlatması hedef olamaz → yeni hatırlatma gibi');
        $this->send('evet');

        $this->assertNull($other->fresh()->notify_time);
        $this->assertSame('Kira', Reminder::where('phone', self::NORM)->sole()->text);
    }

    public function test_context_lists_only_this_phones_active_reminders(): void
    {
        $r = $this->card();
        Reminder::create(['phone' => '905559999999', 'text' => 'Başkasının', 'event_date' => '2026-10-30',
            'next_fire_at' => Carbon::parse('2026-10-29 08:00', Reminder::TIMEZONE), 'status' => 'active']);

        $block = Reminders::contextBlock(self::PHONE);
        $this->assertStringContainsString("#{$r->id}: İş Bankası kredi kartı son ödeme tarihi", $block);
        $this->assertStringNotContainsString('Başkasının', $block);
        $this->assertSame('', Reminders::contextBlock('whatsapp:+905550000000'));
    }
}
