<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Reminder;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\ModuleProfiles;
use App\Support\MonthlyResult;
use App\Support\PartyStatement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/** Personel (Akkuş, 15.10): kart ayarı + maaş günü hatırlatması; maaş − açık avans = ödenen; ay sonucunda "Personel". AI mock. */
class WhatsappEmployeeTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905558880005';

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('alacak_verecek', ['report_monthly' => true]), 'app.profile' => 'alacak_verecek',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 888 00 05'],
            'services.anthropic.chat_assistant' => false]);
        Http::fake();
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
    }

    private function draft(array $o): array
    {
        return array_merge(['items' => [], 'amount' => 0, 'paid' => null, 'date' => now()->format('Y-m-d'), 'description' => '',
            'party_id' => null, 'party_name' => null, 'project_id' => null, 'project_name' => null, 'confidence' => 'high',
            'question' => null, 'is_new_entry' => true], $o);
    }

    private function ai(array ...$r): void
    {
        $m = Mockery::mock(ExpenseExtractor::class);
        $m->shouldReceive('extract')->andReturn(...$r);
        $this->app->instance(ExpenseExtractor::class, $m);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    public function test_employee_setting_updates_card_and_creates_monthly_reminder(): void
    {
        $this->ai($this->draft(['kind' => ExpenseExtractor::KIND_EMPLOYEE_SETTING, 'party_name' => 'Personel Testi Ali',
            'salary_amount' => 50000, 'salary_day' => 15]));

        $reply = $this->send("Ali personelim maaşı 50 bin ayın 15'i");
        $this->assertStringContainsString('Aylık maaş: 50.000,00', $reply);
        $this->assertStringContainsString("her ayın 15'i", $reply);
        $this->assertStringContainsString('Maaş günü hatırlatması', $this->send('evet'));

        $party = Party::where('name', 'Personel Testi Ali')->firstOrFail();
        $this->assertTrue($party->is_employee);
        $this->assertSame(50000.0, $party->salary_amount);
        $this->assertSame(15, $party->salary_day);
        $r = Reminder::where('party_id', $party->id)->firstOrFail();
        $this->assertSame('monthly', $r->repeat);
        $this->assertSame(15, (int) $r->anchor_day);
        $this->assertNotNull($r->next_fire_at);
    }

    public function test_salary_deducts_open_advance_and_closes_account(): void
    {
        $party = Party::create(['name' => 'Personel Testi Veli', 'is_employee' => true, 'salary_amount' => 50000, 'salary_day' => 1]);
        PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => now()->subDays(5), 'type' => PartyLedgerEntry::TYPE_PAYMENT,
            'description' => 'Avans', 'amount' => 20000]);
        $this->ai($this->draft(['kind' => ExpenseExtractor::KIND_SALARY, 'party_id' => $party->id, 'paid' => true]));

        $reply = $this->send('Velinin maaşını verdim');
        $this->assertStringContainsString('maaş', $reply);
        $this->assertStringContainsString('Avanstan düşülen: 20.000,00', $reply);
        $this->assertStringContainsString('Ödenen: 30.000,00', $reply);
        $this->assertStringContainsString('hesap kapalı', $this->send('evet'));

        $this->assertEqualsWithDelta(0.0, PartyStatement::build($party)['balance'], 0.01);
        $this->assertSame(30000.0, (float) PartyLedgerEntry::where('party_id', $party->id)->where('type', PartyLedgerEntry::TYPE_PAYMENT)
            ->where('description', 'like', 'Maaş%')->value('amount'));

        // Reminder note: avans kapandı → maaşın tamamı
        $this->assertStringContainsString('maaş 50.000,00 ₺ ödeyeceksin', \App\Services\Whatsapp\EmployeeDraft::reminderNote($party->fresh()));
    }

    public function test_reminder_note_shows_net_after_advance(): void
    {
        $party = Party::create(['name' => 'Personel Testi Can', 'is_employee' => true, 'salary_amount' => 50000]);
        PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => now(), 'type' => PartyLedgerEntry::TYPE_PAYMENT,
            'description' => 'Avans', 'amount' => 20000]);

        $this->assertStringContainsString('maaş 50.000,00 ₺ − avans 20.000,00 = 30.000,00 ₺',
            \App\Services\Whatsapp\EmployeeDraft::reminderNote($party));
    }

    public function test_month_result_counts_salary_as_personnel_once(): void
    {
        $before = MonthlyResult::forMonth(now());
        $party = Party::create(['name' => 'Personel Testi Ayşe', 'is_employee' => true]);
        PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => now(), 'type' => PartyLedgerEntry::TYPE_PURCHASE,
            'description' => 'Maaş — test', 'amount' => 40000]);

        $after = MonthlyResult::forMonth(now());
        $this->assertEqualsWithDelta($before['personnel'] + 40000, $after['personnel'], 0.01);
        $this->assertEqualsWithDelta($before['purchases'], $after['purchases'], 0.01, 'maaş alışa girmez');
        $this->assertEqualsWithDelta($before['result'] - 40000, $after['result'], 0.01);
        $this->assertStringContainsString('Personel: ', MonthlyResult::whatsappText($after));
    }

    public function test_salary_without_known_amount_asks(): void
    {
        $this->ai($this->draft(['kind' => ExpenseExtractor::KIND_SALARY, 'party_name' => 'Personel Testi Yeni', 'paid' => true]));

        $this->assertStringContainsString('Maaşı ne kadar?', $this->send('Yeni personelin maaşını verdim'));
        $this->send('evet');
        $this->assertFalse(Party::where('name', 'Personel Testi Yeni')->exists());
    }

    public function test_daily_wage_is_earned_not_a_debt(): void
    {
        $this->ai($this->draft(['kind' => ExpenseExtractor::KIND_SALARY, 'party_name' => 'Personel Testi Hasan', 'amount' => 2000,
            'paid' => true, 'description' => 'yevmiye']));

        $this->assertStringContainsString('yevmiye', $this->send("İşçi Hasan'a 2000 yevmiye verdim"));
        $this->send('evet');
        $party = Party::where('name', 'Personel Testi Hasan')->firstOrFail();
        $this->assertEqualsWithDelta(0.0, PartyStatement::build($party)['balance'], 0.01, 'yevmiye ödendi → hesap kapalı, borçlu görünmez');
        $this->assertTrue($party->is_employee);
        $this->assertNull($party->salary_amount, 'yevmiye aylık maaşa yazılmaz');
    }

    public function test_unclear_payment_to_employee_is_not_saved_with_plain_yes(): void
    {
        $party = Party::create(['name' => 'Personel Testi Emre', 'is_employee' => true, 'salary_amount' => 50000]);
        $this->ai($this->draft(['kind' => ExpenseExtractor::KIND_PAYMENT, 'party_id' => $party->id, 'amount' => 15000, 'paid' => true,
            'question' => 'Maaş mı, avans mı?']));
        $before = PartyLedgerEntry::where('party_id', $party->id)->count();

        $this->assertStringContainsString('Maaş mı, avans mı?', $this->send('Emreye 15 bin verdim'));
        $this->send('evet');
        $this->assertSame($before, PartyLedgerEntry::where('party_id', $party->id)->count());
    }

    /** "Personelim Fatih'e 20 bin avans verdim" (Serkan 16.10): yeni cari personel olarak açılır, avans = maaş avansı. */
    public function test_staff_word_marks_new_party_as_employee(): void
    {
        $this->ai($this->draft(['kind' => ExpenseExtractor::KIND_PAYMENT, 'party_name' => 'Personel Testi Fatih', 'amount' => 20000,
            'paid' => true, 'payment_purpose' => 'advance', 'description' => 'Avans', 'is_staff' => true]));

        $reply = $this->send("Personelim Fatih e 20\nBin avans verdim");
        $this->assertStringContainsString('yeni personel', $reply);
        $this->assertStringContainsString('Maaş avansı', $reply);
        $this->assertStringNotContainsString('iş sonra yapılacak', $reply);
        $this->send('evet');

        $this->assertTrue(Party::where('name', 'Personel Testi Fatih')->firstOrFail()->is_employee);
    }

    /** Pozitif kontrol: personel sözü yoksa usta avansı eskisi gibi. */
    public function test_plain_advance_is_unchanged(): void
    {
        $this->ai($this->draft(['kind' => ExpenseExtractor::KIND_PAYMENT, 'party_name' => 'Usta Testi Ali', 'amount' => 20000,
            'paid' => true, 'payment_purpose' => 'advance', 'description' => 'Avans']));

        $reply = $this->send('Ali ustaya 20 bin avans verdim');
        $this->assertStringContainsString('iş sonra yapılacak', $reply);
        $this->send('evet');
        $this->assertFalse(Party::where('name', 'Usta Testi Ali')->firstOrFail()->is_employee);
    }

    /** Akkuş 19.10: "Afgan Apo maaşı ödendi" → AI hatırlatmadaki net 49.500'ü maaş sandı; avans iki kez düşüldü (49.500 / 44.000). */
    public function test_salary_without_number_uses_card_salary_not_net(): void
    {
        $apo = Party::create(['name' => 'Afgan Apo', 'is_employee' => true, 'salary_amount' => 55000, 'salary_day' => 19]);
        PartyLedgerEntry::create(['party_id' => $apo->id, 'type' => PartyLedgerEntry::TYPE_PAYMENT, 'amount' => 5500,
            'entry_date' => now()->subDays(3)->toDateString(), 'description' => 'Avans']);
        $this->ai($this->draft(['kind' => ExpenseExtractor::KIND_SALARY, 'party_id' => $apo->id, 'amount' => 49500, 'paid' => true,
            'description' => 'Afgan Apo maaşı (avans düşülmüş)']));

        $card = $this->send('Afgan Apo maaşı ödendi');
        $this->assertStringContainsString('maaş', $card);
        $this->assertStringContainsString('55.000,00', $card);
        $this->assertStringContainsString('Avanstan düşülen: 5.500,00', $card);
        $this->assertStringContainsString('Ödenen: 49.500,00', $card);
        $this->send('evet');

        $this->assertEquals(55000, (float) PartyLedgerEntry::where('party_id', $apo->id)->where('type', PartyLedgerEntry::TYPE_PURCHASE)->value('amount'));
        $this->assertEquals(49500, (float) PartyLedgerEntry::where('party_id', $apo->id)->where('type', PartyLedgerEntry::TYPE_PAYMENT)
            ->where('description', 'like', 'Maaş ödemesi%')->value('amount'));
        $this->assertEqualsWithDelta(0, PartyStatement::build($apo)['balance'], 0.01);
    }

    public function test_net_amount_written_by_ai_is_turned_into_full_salary_but_partial_payment_stays(): void
    {
        $apo = Party::create(['name' => 'Afgan Apo', 'is_employee' => true, 'salary_amount' => 55000]);
        PartyLedgerEntry::create(['party_id' => $apo->id, 'type' => PartyLedgerEntry::TYPE_PAYMENT, 'amount' => 5500,
            'entry_date' => now()->subDays(3)->toDateString(), 'description' => 'Avans']);
        $base = ['kind' => ExpenseExtractor::KIND_SALARY, 'party_id' => $apo->id, 'paid' => true];

        $this->assertEquals(55000, \App\Services\Whatsapp\EmployeeDraft::guardSalaryAmount($base + ['amount' => 49500], 'Apoya 49.500 maaş verdim')['amount']);
        $this->assertEquals(30000, \App\Services\Whatsapp\EmployeeDraft::guardSalaryAmount($base + ['amount' => 30000], 'Apoya 30 bin maaş verdim')['amount'], 'kısmi ödeme');
        $this->assertEquals(2000, \App\Services\Whatsapp\EmployeeDraft::guardSalaryAmount($base + ['amount' => 2000, 'description' => 'yevmiye'], 'Apo yevmiye')['amount'], 'yevmiye');
    }
}
