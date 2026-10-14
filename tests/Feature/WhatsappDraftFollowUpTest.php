<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ExpenseExtractor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Yarım kalan kayıt takibi: yeni kayıt eskisini sessizce kapatmaz; iş bitince ya da 30 dk
 * sessizlikte sorulur (en fazla 2 kez, gece yok, 24 saat penceresi dışında yok). AI mock'lanır.
 */
class WhatsappDraftFollowUpTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905558880000';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.twilio.verify_signature' => false,
            'services.twilio.allowed_phones' => ['0555 888 00 00'],
            'services.twilio.sid' => 'AC_test', 'services.twilio.token' => 'x', 'services.whatsapp.from' => '+14786665916',
            'modules.smart_assistant' => false, 'services.anthropic.chat_assistant' => false,
            'modules.expenses' => true, 'modules.projects' => false,
        ]);
        Http::fake();
        WhatsappPendingExpense::where('status', 'awaiting_confirmation')->update(['status' => 'superseded']);
    }

    private function entry(array $o): array
    {
        return array_merge([
            'kind' => ExpenseExtractor::KIND_EXPENSE, 'items' => [], 'payment_type' => null, 'amount' => 0.0,
            'date' => now()->format('Y-m-d'), 'due_date' => null, 'description' => '', 'project_id' => null,
            'project_name' => null, 'party_id' => null, 'party_name' => null, 'category_id' => null,
            'category_name' => null, 'paid' => true, 'confidence' => 'high', 'question' => null, 'is_new_entry' => true,
        ], $o);
    }

    private function fakeAi(array ...$responses): void
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

    private function draft(array $o = [], array $attrs = []): WhatsappPendingExpense
    {
        $p = WhatsappPendingExpense::create($attrs + ['phone' => self::PHONE, 'status' => 'awaiting_confirmation',
            'extracted' => $this->entry($o + ['amount' => 4500, 'description' => 'Yem']), 'summary' => 'özet']);

        return $p;
    }

    private function inbound(Carbon $at): void
    {
        $m = WhatsappMessage::create(['phone' => '905558880000', 'direction' => 'in', 'body' => 'Yem 4500']);
        $m->forceFill(['created_at' => $at->copy()->utc()])->save();
    }

    private function sent(): int
    {
        return collect(Http::recorded())->filter(fn ($r) => str_contains($r[0]->url(), '/Messages.json'))->count();
    }

    /** Oktay: "Yem 4500" onaylanmadan başka kayıt yazıldı → o bitince yem sorulur, "evet" ile kaydedilir. */
    public function test_new_entry_keeps_old_draft_and_asks_after_commit(): void
    {
        $this->fakeAi(
            $this->entry(['amount' => 4500, 'description' => 'Yem Takip']),
            $this->entry(['amount' => 2000, 'description' => 'Mazot Takip']),
        );
        $before = Expense::count();

        $this->send('Yem 4500');
        $this->send('Mazot 2000');
        $reply = $this->send('evet');

        $this->assertStringContainsString('Bir de yarım kalan var', $reply);
        $this->assertStringContainsString('Gider 4.500,00 ₺, Yem Takip', $reply);
        $this->assertStringContainsString('Onay vermediğin için kaydedemedim', $reply);

        $this->send('evet');
        $this->assertSame($before + 2, Expense::count());
        $this->assertSame(1, Expense::where('description', 'Yem Takip')->count());
    }

    public function test_same_record_retyped_and_saved_is_not_asked_again(): void
    {
        $this->fakeAi(
            $this->entry(['amount' => 4500, 'description' => 'Yem Kopya']),
            $this->entry(['amount' => 4500, 'description' => 'Yem kopya düzeltilmiş']),
        );
        $this->send('Yem 4500');
        $this->send('yem 4500 tl');

        $this->assertStringNotContainsString('yarım kalan', $this->send('evet'));
        $this->assertSame(0, WhatsappPendingExpense::where('phone', self::PHONE)->where('status', 'awaiting_confirmation')->count());
    }

    public function test_command_sends_after_30_minutes_then_next_day_then_stops(): void
    {
        $start = Carbon::parse('2026-10-14 14:00', 'Europe/Istanbul');
        $this->travelTo($start);
        $this->inbound($start);
        $draft = $this->draft();

        $this->travelTo($start->copy()->addMinutes(20));
        $this->artisan('drafts:follow-up')->assertSuccessful();
        $this->assertSame(0, $this->sent(), '30 dk dolmadan gitmez');

        $this->travelTo($start->copy()->addMinutes(35));
        $this->artisan('drafts:follow-up')->assertSuccessful();
        $this->assertSame(1, $this->sent());
        $this->assertSame(1, $draft->fresh()->follow_ups);
        $this->assertSame('draft_followup', WhatsappMessage::where('phone', '905558880000')->latest('id')->value('kind'));

        $this->travelTo($start->copy()->addHours(3));
        $this->artisan('drafts:follow-up')->assertSuccessful();
        $this->assertSame(1, $this->sent(), 'aynı gün ikinci kez gitmez');

        $this->travelTo(Carbon::parse('2026-10-15 09:00', 'Europe/Istanbul'));
        $this->artisan('drafts:follow-up')->assertSuccessful();
        $this->assertSame(2, $this->sent(), 'ertesi gün bir kez daha');

        $this->travelTo(Carbon::parse('2026-10-16 09:00', 'Europe/Istanbul'));
        $this->artisan('drafts:follow-up')->assertSuccessful();
        $this->assertSame(2, $this->sent(), 'en fazla 2');
    }

    public function test_command_waits_for_morning_and_skips_cancelled(): void
    {
        $start = Carbon::parse('2026-10-14 22:50', 'Europe/Istanbul');
        $this->travelTo($start);
        $this->inbound($start);
        $draft = $this->draft();
        $this->draft(['description' => 'İptal edilen'], ['status' => 'cancelled']);

        $this->travelTo($start->copy()->addMinutes(40)); // 23:30
        $this->artisan('drafts:follow-up')->assertSuccessful();
        $this->assertSame(0, $this->sent(), 'gece gitmez');

        $this->travelTo(Carbon::parse('2026-10-15 08:05', 'Europe/Istanbul'));
        $this->artisan('drafts:follow-up')->assertSuccessful();
        $this->assertSame(1, $this->sent(), 'sabah gider');
        $this->assertSame(1, $draft->fresh()->follow_ups);
    }

    public function test_command_skips_when_user_still_writing_or_window_closed(): void
    {
        $start = Carbon::parse('2026-10-14 10:00', 'Europe/Istanbul');
        $this->travelTo($start);
        $this->draft();
        $this->inbound($start->copy()->addMinutes(30)); // başka bir şey yazıyor

        $this->travelTo($start->copy()->addMinutes(40));
        $this->artisan('drafts:follow-up')->assertSuccessful();
        $this->assertSame(0, $this->sent(), 'kişi 30 dk içinde yazdıysa bekle');

        WhatsappMessage::where('phone', '905558880000')->update(['created_at' => $start->copy()->subDay()->utc()]);
        $this->artisan('drafts:follow-up')->assertSuccessful();
        $this->assertSame(0, $this->sent(), '24 saat penceresi kapalı');
    }

    /** Hatırlatılan taslak yeniden etkin: eksik tutar → "8500" ona bağlanır. */
    public function test_reminded_amount_gap_says_why_and_reply_attaches(): void
    {
        $start = Carbon::parse('2026-10-14 14:00', 'Europe/Istanbul');
        $this->travelTo($start);
        $this->inbound($start);
        $draft = $this->draft(['amount' => 0, 'description' => 'Kira Takip']);

        $this->travelTo($start->copy()->addMinutes(35));
        $this->artisan('drafts:follow-up')
            ->expectsOutputToContain('Tutar eksik olduğu için kaydedemedim')
            ->assertSuccessful();

        $this->fakeAi($this->entry(['amount' => 8500, 'description' => 'Kira Takip', 'is_new_entry' => false]));
        $this->assertStringContainsString('8.500,00', $this->send('8500'));
        $this->send('evet');
        $this->assertSame('confirmed', $draft->fresh()->status);
        $this->assertSame(1, Expense::where('description', 'Kira Takip')->count());
    }

    /** Doktor: hatırlatılan, eksiği yalnız tutar olan kayıt — saatler sonra gelen tutar tek adımda kaydedilir. */
    public function test_followed_up_amount_reply_hours_later_saves_in_one_step(): void
    {
        $draft = $this->draft(['kind' => ExpenseExtractor::KIND_SALE, 'amount' => 0, 'paid' => false,
            'party_name' => 'Doktor Tek Adım', 'description' => '10 kg peynir, 2 kavurma, mumbar']);
        \App\Services\Whatsapp\DraftFollowUp::markSent($draft);

        $this->travel(5)->hours();
        $reply = $this->send('8500');

        $this->assertStringContainsString('Kaydedildi', $reply);
        $this->assertStringContainsString('son kaydı sil', $reply);
        $this->assertSame('confirmed', $draft->fresh()->status);
        $party = \App\Models\Party::where('name', 'Doktor Tek Adım')->sole();
        $this->assertSame(8500.0, \App\Support\PartyStatement::build($party)['balance']);
    }

    public function test_normal_draft_amount_reply_still_asks_confirm(): void
    {
        $this->draft(['kind' => ExpenseExtractor::KIND_SALE, 'amount' => 0, 'paid' => false, 'party_name' => 'Normal Onay']);

        $this->assertStringNotContainsString('Kaydedildi', $this->send('8500'));
        $this->assertSame(0, \App\Models\Party::where('name', 'Normal Onay')->count());
    }
}
