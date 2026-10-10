<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Reminder;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\ExpenseExtractor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/** WhatsApp hatırlatmaları: kur → teyit → gönder → "tamam" / "aldım" ile kapan. "Şimdi": 10 Ekim 2026 12:00. */
class WhatsappReminderFlowTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905556660000';

    private const NORM = '905556660000';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00', Reminder::TIMEZONE));
        config([
            'services.twilio.verify_signature' => false,
            'services.twilio.allowed_phones' => ['0555 666 00 00'],
            'services.twilio.sid' => 'ACtest',
            'services.twilio.token' => 'tok',
            'services.whatsapp.from' => '+14786665916',
            'services.whatsapp.reminder_content_sid' => 'HXreminder',
        ]);
        Http::fake([
            'content.twilio.com/*' => Http::response(['whatsapp' => ['status' => 'pending']]),
            'api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201),
            '*' => Http::response('', 200),
        ]);
        WhatsappMessage::create(['phone' => self::NORM, 'direction' => 'in', 'body' => 'önceki']); // karşılama araya girmesin
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
            'payment_type' => null, 'amount' => 0.0, 'date' => '2026-10-10', 'due_date' => null, 'description' => '',
            'project_id' => null, 'project_name' => null, 'party_id' => null, 'party_name' => null, 'category_id' => null,
            'category_name' => null, 'paid' => null, 'confidence' => 'high', 'question' => null, 'is_new_entry' => false,
            'debt_side' => null, 'reply' => null, 'payment_purpose' => null, 'settles_expense_id' => null,
            'event_date' => null, 'event_time' => null, 'lead_minutes' => null, 'is_alarm' => false, 'repeat' => null,
        ], $overrides);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    public function test_event_reminder_is_confirmed_with_computed_times_and_saved(): void
    {
        $this->fakeAi($this->entry(['description' => 'Düğün çekimi', 'event_date' => '2026-10-20', 'lead_minutes' => 2880]));

        $summary = $this->send("20 Ekim'de düğün çekimim var, 2 gün önceden hatırlat");
        $this->assertStringContainsString('Ne: Düğün çekimi', $summary);
        $this->assertStringContainsString('Hatırlatacağım: 18 Ekim Pazar 08:00 · 20 Ekim Salı 08:00', $summary);
        $this->assertSame(0, Reminder::where('phone', self::NORM)->count()); // teyitten önce kayıt yok

        $this->assertStringContainsString('İlk hatırlatma: 18 Ekim Pazar 08:00', $this->send('evet'));
        $r = Reminder::where('phone', self::NORM)->sole();
        $this->assertSame('Düğün çekimi', $r->text);
        $this->assertSame('2026-10-18 08:00', $r->next_fire_at->copy()->setTimezone(Reminder::TIMEZONE)->format('Y-m-d H:i'));
    }

    public function test_past_time_asks_again_and_number_reply_is_not_an_amount(): void
    {
        $this->fakeAi($this->entry(['description' => 'Toplantı', 'event_date' => '2026-10-09', 'event_time' => '10:00']));

        $this->assertStringContainsString('geçmiş görünüyor', $this->send('dün 10da toplantı'));
        $reply = $this->send('evet');
        $this->assertStringContainsString('geçmiş görünüyor', $reply);
        $this->assertStringNotContainsString('tutar', $reply); // hatırlatmada tutar sorulmaz
        $this->assertSame(0, Reminder::where('phone', self::NORM)->count());
    }

    public function test_list_and_cancel_by_number(): void
    {
        Reminder::create(['phone' => self::NORM, 'text' => 'Kira', 'event_date' => '2026-11-01', 'next_fire_at' => '2026-10-31 08:00']);
        Reminder::create(['phone' => self::NORM, 'text' => 'Kredi kartı', 'event_date' => '2026-10-15', 'next_fire_at' => '2026-10-14 08:00']);

        $list = $this->send('hatırlatmalarım');
        $this->assertMatchesRegularExpression('/1\) Kredi kartı.*\n2\) Kira/u', $list);

        $this->assertStringContainsString('silindi: Kira', $this->send('iptal 2'));
        $this->assertSame('cancelled', Reminder::where('text', 'Kira')->where('phone', self::NORM)->sole()->status);
    }

    public function test_send_logs_message_sets_context_and_tamam_cancels_rest_of_event(): void
    {
        $r = Reminder::create(['phone' => self::NORM, 'text' => 'Düğün çekimi', 'event_date' => '2026-10-11']);
        $r->scheduleNext();
        $r->save(); // 11.10 08:00 (1 gün önce = bugün 08:00 geçti)

        Carbon::setTestNow(Carbon::parse('2026-10-11 08:01', Reminder::TIMEZONE));
        $this->artisan('reminders:send')->assertSuccessful();

        // Şablon onaysız → serbest metin, "Bugün: Düğün çekimi"
        Http::assertSent(fn (ClientRequest $q) => str_contains($q->url(), 'api.twilio.com') && str_contains((string) $q['Body'], 'Bugün: Düğün çekimi'));
        $this->assertSame('reminder', WhatsappMessage::where('phone', self::NORM)->where('direction', 'out')->latest('id')->first()->kind);
        $r->refresh();
        $this->assertNotNull($r->last_sent_at);
        $this->assertSame('done', $r->status); // tek olay, başka hatırlatma kalmadı

        $this->fakeAi(); // "tamam" AI'a gitmez
        $this->assertStringContainsString('• Düğün çekimi', $this->send('tamam'));
        $this->assertNotNull($r->fresh()->acknowledged_at);
    }

    /** İki hatırlatmalı olay: ilki gidince kayıt AKTİF kalmalı, ertesi gün ikincisi de gitmeli. */
    public function test_event_with_two_alarms_sends_both(): void
    {
        $r = Reminder::create(['phone' => self::NORM, 'text' => 'Sıvacı gelecek', 'event_date' => '2026-10-13']);
        $r->scheduleNext();
        $r->save(); // 12.10 08:00 + 13.10 08:00

        Carbon::setTestNow(Carbon::parse('2026-10-12 08:01', Reminder::TIMEZONE));
        $this->artisan('reminders:send')->assertSuccessful();
        $r->refresh();
        $this->assertSame('active', $r->status);
        $this->assertSame('13.10 08:00', $r->next_fire_at->copy()->setTimezone(Reminder::TIMEZONE)->format('d.m H:i'));

        Carbon::setTestNow(Carbon::parse('2026-10-13 08:01', Reminder::TIMEZONE));
        $this->artisan('reminders:send')->assertSuccessful();
        $this->assertSame('done', $r->fresh()->status);
        Http::assertSent(fn (ClientRequest $q) => str_contains($q->url(), 'api.twilio.com') && str_contains((string) $q['Body'], 'Yarın (13 Ekim): Sıvacı gelecek'));
        Http::assertSent(fn (ClientRequest $q) => str_contains($q->url(), 'api.twilio.com') && str_contains((string) $q['Body'], 'Bugün: Sıvacı gelecek'));
    }

    /** Aynı anda iki hatırlatma + "tamam": ikisi de görüldü, Ali'nin ertesi günkü hatırlatması İPTAL OLMAZ. */
    public function test_tamam_marks_all_seen_and_keeps_remaining_alarms(): void
    {
        $ali = Reminder::create(['phone' => self::NORM, 'text' => "Ali'den parayı al", 'event_date' => '2026-10-12']);
        $ali->scheduleNext();
        $ali->save(); // 11.10 08:00 + 12.10 08:00
        $hair = Reminder::create(['phone' => self::NORM, 'text' => 'Kuaför', 'event_date' => '2026-10-11', 'event_time' => '11:00', 'lead_minutes' => 120]);
        $hair->scheduleNext();
        $hair->save(); // 11.10 09:00

        Carbon::setTestNow(Carbon::parse('2026-10-11 09:00', Reminder::TIMEZONE));
        $this->artisan('reminders:send')->assertSuccessful();
        $this->fakeAi();
        $reply = $this->send('tamam');

        $this->assertStringContainsString("• Ali'den parayı al (yine hatırlatacağım: 12 Ekim Pazartesi 08:00)", $reply);
        $this->assertStringContainsString('• Kuaför', $reply);
        $this->assertSame('active', $ali->fresh()->status);
        $this->assertSame('done', $hair->fresh()->status);
    }

    public function test_aldim_after_reminder_records_collection_and_closes_reminder(): void
    {
        $ali = Party::create(['name' => 'Hatırlatma Test Ali']);
        PartyLedgerEntry::create(['party_id' => $ali->id, 'entry_date' => '2026-10-01', 'type' => PartyLedgerEntry::TYPE_SALE, 'amount' => 45000]);
        $r = Reminder::create(['phone' => self::NORM, 'text' => "Ali'den parayı al", 'party_id' => $ali->id, 'event_date' => '2026-10-11', 'is_alarm' => true]);
        $r->scheduleNext();
        $r->save();

        Carbon::setTestNow(Carbon::parse('2026-10-11 08:01', Reminder::TIMEZONE));
        $this->artisan('reminders:send')->assertSuccessful();
        Http::assertSent(fn (ClientRequest $q) => str_contains($q->url(), 'api.twilio.com')
            && str_contains((string) $q['Body'], 'Hatırlatma Test Ali: sana borcu 45.000,00 ₺'));

        // "aldım" → AI'a son hatırlatma + cari bağlamı gider
        $seen = new \ArrayObject;
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturnUsing(function ($t, $i = null, $p = null, $ctx = null) use ($seen, $ali) {
            $seen[] = $ctx;

            return $this->entry(['kind' => ExpenseExtractor::KIND_COLLECTION, 'party_id' => $ali->id, 'amount' => 45000]);
        });
        $this->app->instance(ExpenseExtractor::class, $mock);

        $this->assertStringContainsString('Hatırlatma Test Ali → *sana*: 45.000,00 ₺', $this->send('aldım'));
        $this->assertSame("Ali'den parayı al", $seen[0]['reminder']);
        $this->assertSame($ali->id, $seen[0]['party_id']);

        $this->send('evet');
        $this->assertNotNull($r->fresh()->acknowledged_at);
    }
}
