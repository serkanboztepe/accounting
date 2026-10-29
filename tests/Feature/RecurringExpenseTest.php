<?php

namespace Tests\Feature;

use App\Filament\Pages\Calendar;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\ChatAssistant;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Düzenli gider (Oktay 18.10: "çobanlar günde 2 ekmek, 17,30 TL — her gün gider olarak ekle"). Ayrı tablo değil, tutarı olan
 * hatırlatma (kullanıcı kararı 19.10): günü gelince 08:00'de gider yazılır + "… gider olarak eklendi, geri almak için iptal yaz";
 * "iptal" o günün giderini siler, düzen sürer. Aynı gün iki kez yazılmaz. Panelde Takvim'den eklenir / düzeltilir / silinir.
 */
class RecurringExpenseTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905557770019';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'modules' => ModuleProfiles::resolve('trade', ['smart_assistant' => true, 'expenses' => true, 'projects' => true]), 'app.profile' => 'trade',
            'services.anthropic.api_key' => 'test', 'services.anthropic.chat_model' => 'claude-sonnet-5',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 777 00 19'],
            'services.whatsapp.reminder_content_sid' => null,
            'services.twilio.sid' => 'AC_test', 'services.twilio.token' => 'test', 'services.whatsapp.from' => '+14786665916',
        ]);
        ChatAssistant::$deadline = null;
        Http::fake();
        WhatsappMessage::create(['phone' => '905557770019', 'direction' => 'in', 'body' => 'önceki']);
    }

    private function ai(array $input): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory); // önceki sahte cevap kalmasın
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'propose_entry',
            'input' => $input + ['amount' => 0, 'is_new_entry' => true]]], 'usage' => []]), '*' => Http::response([], 200)]);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    private function today(): Carbon
    {
        return Carbon::now('Europe/Istanbul')->startOfDay();
    }

    private function bread(array $extra = []): Reminder
    {
        $r = new Reminder($extra + ['phone' => '905557770019', 'text' => 'Çobanlar ekmek', 'amount' => 34.60, 'event_date' => $this->today()->addDay()->toDateString(),
            'event_time' => '08:00', 'is_alarm' => true, 'repeat' => 'daily', 'status' => 'active']);
        $r->scheduleNext(now());
        $r->save();

        return $r;
    }

    /** Ertesi gün 08:01'de hatırlatma turu. */
    private function runAt(Carbon $istanbul): void
    {
        $this->travelTo($istanbul->copy()->setTimezone(config('app.timezone')));
        $this->artisan('reminders:send')->assertSuccessful();
    }

    public function test_bread_every_day_is_set_up_as_reminder_and_written_each_morning_with_note(): void
    {
        $this->ai(['kind' => 'recurring_expense', 'amount' => 34.60, 'description' => 'Çobanlar ekmek', 'category_name' => 'Çobanlar',
            'recurring_frequency' => 'daily']);

        $card = $this->send('Çobanlar günde iki ekmek yiyorlar ekmeğin fiyatı 17:30 TL her gün hesap hesaplarına gider olarak ekle');
        $this->assertStringContainsString('Düzenli gider — kontrol et', $card);
        $this->assertStringContainsString('Çobanlar ekmek — her gün 34,60 ₺', $card);
        $this->assertStringContainsString('İlk kayıt: ' . $this->today()->addDay()->format('d.m.Y'), $card);

        $this->assertStringContainsString('Düzenli gider kuruldu', $this->send('evet'));
        $r = Reminder::where('text', 'Çobanlar ekmek')->firstOrFail();
        $this->assertTrue($r->isExpense());
        $this->assertSame('Çobanlar', $r->category?->name);
        $this->assertSame(0, Expense::where('description', 'Çobanlar ekmek')->count(), 'bugün yazılmaz (söylenmedi)');

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake();
        $tomorrow = $this->today()->addDay();
        $this->runAt($tomorrow->copy()->setTime(8, 1));
        $e = Expense::where('description', 'Çobanlar ekmek')->sole();
        $this->assertEquals(34.60, (float) $e->amount);
        $this->assertSame($tomorrow->toDateString(), $e->expense_date->toDateString());
        $this->assertSame('paid', $e->payment_status);
        $this->assertSame($r->expense_category_id, $e->expense_category_id);
        $note = WhatsappMessage::where('direction', 'out')->where('kind', 'reminder')->latest('id')->value('body');
        $this->assertStringContainsString('34,60 ₺ Çobanlar ekmek gider olarak eklendi', $note);
        $this->assertStringContainsString('Geri almak için *iptal* yaz', $note);
        Http::assertSent(fn ($req) => str_contains((string) ($req['Body'] ?? ''), 'gider olarak eklendi'));

        $this->runAt($this->today()->setTime(8, 30));
        $this->assertSame(1, Expense::where('description', 'Çobanlar ekmek')->count(), 'aynı gün iki kez yazılmaz');
        $this->runAt($this->today()->addDay()->setTime(8, 1));
        $this->assertSame(2, Expense::where('description', 'Çobanlar ekmek')->count(), 'ertesi gün yine');
    }

    public function test_iptal_after_note_deletes_that_days_expense_and_keeps_schedule(): void
    {
        $project = Project::create(['name' => 'Ergenekon Test Şube']);
        $r = $this->bread(['text' => 'Kira', 'amount' => 20000, 'repeat' => 'monthly', 'anchor_day' => $this->today()->addDay()->day, 'project_id' => $project->id]);
        $this->runAt($this->today()->addDay()->setTime(8, 1));
        $this->assertSame(1, Expense::where('description', 'Kira')->where('project_id', $project->id)->count());
        $this->assertStringContainsString('Ergenekon Test Şube için 20.000,00 ₺ Kira gider olarak eklendi',
            WhatsappMessage::where('direction', 'out')->latest('id')->value('body'));

        $out = $this->send('iptal');
        $this->assertStringContainsString('Geri aldım', $out);
        $this->assertStringContainsString('kira giderini durdur', $out);
        $this->assertSame(0, Expense::where('description', 'Kira')->count());
        $this->assertSame('active', $r->fresh()->status, 'düzen sürer');

        $this->assertStringNotContainsString('Geri aldım', $this->send('iptal'), 'ikinci iptal başka şey silmez');
    }

    public function test_starting_today_writes_todays_now(): void
    {
        $this->ai(['kind' => 'recurring_expense', 'amount' => 50, 'description' => 'Süt kovası', 'recurring_frequency' => 'daily',
            'recurring_start' => $this->today()->toDateString()]);

        $this->send('bugünden başlayarak her gün süt kovası 50 lira gider yaz');
        $done = $this->send('evet');

        $this->assertStringContainsString('Bugünkü yazıldı: 50,00 ₺', $done);
        $this->assertSame(1, Expense::whereDate('expense_date', $this->today())->where('description', 'Süt kovası')->count());
        $this->assertSame($this->today()->addDay()->toDateString(),
            Reminder::where('text', 'Süt kovası')->sole()->next_fire_at->setTimezone('Europe/Istanbul')->toDateString(), 'sıradaki yarın');
    }

    /** Çiftlik 26.10: "her gün 16:00'da" — gider de mesaj da 16:00'da (eskiden hep 08:00). */
    public function test_said_time_is_when_expense_is_written(): void
    {
        $this->ai(['kind' => 'recurring_expense', 'amount' => 34.60, 'description' => 'Öğle ekmeği', 'recurring_frequency' => 'daily',
            'recurring_time' => '16:00']);

        $this->assertStringContainsString('her gün saat 16:00 kendiliğinden yazılır', $this->send("her gün öğle ekmeği 34,60 gider yaz saat 16:00'da"));
        $this->send('evet');
        $r = Reminder::where('text', 'Öğle ekmeği')->sole();
        $this->assertSame('16:00', substr((string) $r->event_time, 0, 5));

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake();
        $tomorrow = $this->today()->addDay();
        $this->runAt($tomorrow->copy()->setTime(8, 1));
        $this->assertSame(0, Expense::where('description', 'Öğle ekmeği')->count(), 'sabah yazılmaz');
        $this->runAt($tomorrow->copy()->setTime(16, 1));
        $this->assertSame(1, Expense::where('description', 'Öğle ekmeği')->count(), '16:00\'da yazılır');
    }

    public function test_monthly_on_31st_uses_last_day_of_short_month_and_weekly_label(): void
    {
        $r = $this->bread(['text' => 'Kira', 'amount' => 20000, 'repeat' => 'monthly', 'anchor_day' => 31, 'event_date' => '2031-01-31']);
        $this->assertSame('2031-02-28', $r->nextOccurrence(Carbon::parse('2031-01-31'))->toDateString());
        $this->assertSame("her ayın 31'i", $r->scheduleText());

        $w = $this->bread(['repeat' => 'weekly', 'event_date' => '2031-01-06']); // pazartesi
        $this->assertSame('her pazartesi', $w->scheduleText());
    }

    public function test_stop_and_list(): void
    {
        $r = $this->bread();

        $this->ai(['kind' => 'recurring_list']);
        $this->assertStringContainsString('Çobanlar ekmek — her gün 34,60 ₺', $this->send('düzenli giderlerim'));

        $this->ai(['kind' => 'recurring_stop', 'description' => 'ekmek']);
        $this->assertStringContainsString('durdurayım mı', $this->send('ekmek giderini durdur'));
        $this->assertStringContainsString('Durdurdum: Çobanlar ekmek', $this->send('evet'));
        $this->assertSame('cancelled', $r->fresh()->status);
        $this->runAt($this->today()->addDay()->setTime(8, 1));
        $this->assertSame(0, Expense::where('description', 'Çobanlar ekmek')->count());
    }

    public function test_missing_frequency_is_asked_not_guessed(): void
    {
        $this->ai(['kind' => 'recurring_expense', 'amount' => 34.60, 'description' => 'Ekmek']);

        $this->assertStringContainsString('Ne sıklıkla', $this->send('ekmek 34,60 düzenli yaz'));
        $this->assertSame(0, Reminder::where('amount', '>', 0)->where('text', 'Ekmek')->count());
    }

    public function test_plain_reminder_is_not_an_expense(): void
    {
        $r = $this->bread(['text' => 'Kredi kartı', 'amount' => null]);
        $this->runAt($this->today()->addDay()->setTime(8, 1));

        $this->assertSame(0, Expense::where('description', 'Kredi kartı')->count());
        $this->assertStringContainsString('Hatırlatma: ', WhatsappMessage::where('direction', 'out')->latest('id')->value('body'));
        $this->assertFalse($r->isExpense());
    }

    public function test_panel_calendar_lists_and_adds_recurring_expense(): void
    {
        config(['app.env' => 'local']);
        $this->actingAs(User::factory()->create());
        $this->bread();
        $cat = ExpenseCategory::firstOrCreate(['name' => 'Kira']);

        Livewire::test(Calendar::class)
            ->assertSee('Hatırlatmalar ve düzenli giderler')->assertSee('Çobanlar ekmek')->assertSee('Düzenli gider')
            ->callAction('addExpense', ['text' => 'Dükkan kirası', 'amount' => '20.000,00',
                'expense_category_id' => $cat->id, 'event_date' => $this->today()->addDays(3)->toDateString(), 'repeat' => 'monthly',
                'phone' => '905557770019'])
            ->assertHasNoActionErrors();

        $r = Reminder::where('text', 'Dükkan kirası')->sole();
        $this->assertEquals(20000, (float) $r->amount);
        $this->assertSame('monthly', $r->repeat);
        $this->assertSame($this->today()->addDays(3)->day, $r->anchor_day);
        $this->assertSame('08:00', substr((string) $r->event_time, 0, 5));

        Livewire::test(Calendar::class)
            ->callAction('addReminder', ['text' => 'Teslimat', 'event_date' => $this->today()->addDays(2)->toDateString(), 'event_time' => '14:00',
                'repeat' => '', 'phone' => '905557770019'])
            ->assertHasNoActionErrors();
        $plain = Reminder::where('text', 'Teslimat')->sole();
        $this->assertFalse($plain->isExpense());
        $this->assertNull($plain->repeat);
        $this->assertTrue($plain->is_alarm);
    }
}
