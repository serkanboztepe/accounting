<?php

namespace Tests\Feature;

use App\Filament\Resources\RecurringExpenses\Pages\ListRecurringExpenses;
use App\Models\Expense;
use App\Models\RecurringExpense;
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
 * Düzenli gider (Oktay 18.10: "çobanlar günde 2 ekmek, 17,30 TL — her gün gider olarak ekle" hafızaya söz olarak yazıldı,
 * hiç girilmedi). Onaylı kart → her sabah kendiliğinden yazılır; aynı gün iki kez yazılmaz; kaçan gün telafi edilir.
 */
class RecurringExpenseTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905557770019';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'modules' => ModuleProfiles::resolve('trade', ['smart_assistant' => true, 'expenses' => true]), 'app.profile' => 'trade',
            'services.anthropic.api_key' => 'test', 'services.anthropic.chat_model' => 'claude-sonnet-5',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 777 00 19'],
        ]);
        ChatAssistant::$deadline = null;
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

    public function test_bread_every_day_is_set_up_and_written_each_morning(): void
    {
        $this->ai(['kind' => 'recurring_expense', 'amount' => 34.60, 'description' => 'Çobanlar ekmek', 'category_name' => 'Çobanlar',
            'recurring_frequency' => 'daily']);

        $card = $this->send('Çobanlar günde iki ekmek yiyorlar ekmeğin fiyatı 17:30 TL her gün hesap hesaplarına gider olarak ekle');
        $this->assertStringContainsString('Düzenli gider — kontrol et', $card);
        $this->assertStringContainsString('Çobanlar ekmek — her gün 34,60 ₺', $card);
        $this->assertStringContainsString('İlk kayıt: ' . $this->today()->addDay()->format('d.m.Y'), $card);

        $done = $this->send('evet');
        $this->assertStringContainsString('Düzenli gider kuruldu', $done);
        $r = RecurringExpense::where('description', 'Çobanlar ekmek')->firstOrFail();
        $this->assertSame('Çobanlar', $r->category?->name);
        $this->assertSame(0, Expense::where('recurring_expense_id', $r->id)->count(), 'bugün yazılmaz (söylenmedi)');

        $this->assertSame(1, $r->post($this->today()->addDay()));
        $this->assertSame(0, $r->post($this->today()->addDay()), 'aynı gün iki kez yazılmaz');
        $this->assertSame(3, $r->post($this->today()->addDays(4)), 'kaçan günler telafi');
        $e = Expense::where('recurring_expense_id', $r->id)->orderBy('expense_date')->first();
        $this->assertEquals(34.60, (float) $e->amount);
        $this->assertSame('paid', $e->payment_status);
        $this->assertSame($r->expense_category_id, $e->expense_category_id);
    }

    public function test_starting_today_writes_todays_now(): void
    {
        $this->ai(['kind' => 'recurring_expense', 'amount' => 50, 'description' => 'Süt kovası', 'recurring_frequency' => 'daily',
            'recurring_start' => $this->today()->toDateString()]);

        $this->send('bugünden başlayarak her gün süt kovası 50 lira gider yaz');
        $done = $this->send('evet');

        $this->assertStringContainsString('Bugünkü yazıldı: 50,00 ₺', $done);
        $this->assertSame(1, Expense::whereDate('expense_date', $this->today())->where('description', 'Süt kovası')->count());
    }

    public function test_monthly_on_31st_uses_last_day_of_short_month(): void
    {
        $r = RecurringExpense::create(['description' => 'Kira', 'amount' => 20000, 'frequency' => 'monthly', 'day_of_month' => 31,
            'start_date' => '2031-02-01', 'paid' => false]);

        $this->assertSame(2, $r->post(Carbon::parse('2031-03-31', 'Europe/Istanbul')));
        $dates = Expense::where('recurring_expense_id', $r->id)->orderBy('expense_date')->pluck('expense_date')->map->toDateString()->all();
        $this->assertSame(['2031-02-28', '2031-03-31'], $dates);
        $this->assertSame('unpaid', Expense::where('recurring_expense_id', $r->id)->value('payment_status'));
        $this->assertSame("her ayın 31'i 20.000,00 ₺", $r->label());
    }

    public function test_weekly_on_monday(): void
    {
        $r = RecurringExpense::create(['description' => 'Pazar', 'amount' => 500, 'frequency' => 'weekly', 'weekday' => 1, 'start_date' => '2031-01-01']);

        $r->post(Carbon::parse('2031-01-31', 'Europe/Istanbul')); // Ocak 2031: pazartesiler 6, 13, 20, 27
        $this->assertSame(4, Expense::where('recurring_expense_id', $r->id)->count());
        $this->assertSame('her pazartesi 500,00 ₺', $r->label());
    }

    public function test_stop_and_list(): void
    {
        $r = RecurringExpense::create(['description' => 'Çobanlar ekmek', 'amount' => 34.60, 'frequency' => 'daily', 'start_date' => $this->today()->toDateString()]);

        $this->ai(['kind' => 'recurring_list']);
        $this->assertStringContainsString('Çobanlar ekmek — her gün 34,60 ₺', $this->send('düzenli giderlerim'));

        $this->ai(['kind' => 'recurring_stop', 'description' => 'ekmek']);
        $this->assertStringContainsString('durdurayım mı', $this->send('ekmek giderini durdur'));
        $this->assertStringContainsString('Durdurdum: Çobanlar ekmek', $this->send('evet'));
        $this->assertFalse($r->fresh()->is_active);
        $this->assertSame(0, $r->fresh()->post($this->today()->addDays(3)));
    }

    public function test_missing_frequency_is_asked_not_guessed(): void
    {
        $this->ai(['kind' => 'recurring_expense', 'amount' => 34.60, 'description' => 'Ekmek']);

        $this->assertStringContainsString('Ne sıklıkla', $this->send('ekmek 34,60 düzenli yaz'));
        $this->assertSame(0, RecurringExpense::count());
    }

    public function test_command_writes_due_expenses(): void
    {
        $r = RecurringExpense::create(['description' => 'Çobanlar ekmek', 'amount' => 34.60, 'frequency' => 'daily',
            'start_date' => $this->today()->subDays(2)->toDateString()]);

        $this->artisan('expenses:recurring')->assertSuccessful();

        $this->assertSame(3, Expense::where('recurring_expense_id', $r->id)->count());
    }

    public function test_panel_list_shows_recurring_expenses(): void
    {
        $this->actingAs(User::factory()->create());
        $r = RecurringExpense::create(['description' => 'Çobanlar ekmek', 'amount' => 34.60, 'frequency' => 'daily', 'start_date' => $this->today()->toDateString()]);

        Livewire::test(ListRecurringExpenses::class)->assertCanSeeTableRecords([$r])->assertSee('Her gün');
    }
}
