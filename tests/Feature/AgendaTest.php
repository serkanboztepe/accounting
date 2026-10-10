<?php

namespace Tests\Feature;

use App\Models\Check;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Reminder;
use App\Support\Agenda;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Takvim: "yarın ne var?", "bu hafta?", "20 Ekim boş mu?" — hatırlatma + ödeme günü + çek. "Şimdi": 2031-05-10. */
class AgendaTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = '905558880000';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2031-05-10 09:00', 'Europe/Istanbul'));
        config(['modules.checks' => true, 'modules.expenses' => true, 'modules.contracts' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_week_combines_reminders_due_dates_and_checks_by_day(): void
    {
        Reminder::create(['phone' => self::PHONE, 'text' => 'Ajanda düğün çekimi', 'event_date' => '2031-05-12', 'event_time' => '14:00']);
        Reminder::create(['phone' => self::PHONE, 'text' => 'Ajanda kart ödemesi', 'event_date' => '2031-04-13', 'repeat' => 'monthly', 'anchor_day' => 13]);
        Reminder::create(['phone' => '905550001111', 'text' => 'Başkasının hatırlatması', 'event_date' => '2031-05-12']);
        $ali = Party::create(['name' => 'Ajanda Ali']);
        PartyLedgerEntry::create(['party_id' => $ali->id, 'entry_date' => '2031-05-01', 'due_date' => '2031-05-13', 'type' => PartyLedgerEntry::TYPE_SALE, 'amount' => 45000]);
        Check::create(['party_id' => $ali->id, 'due_date' => '2031-05-14', 'amount' => 20000, 'status' => 'issued', 'check_number' => 'AJ1']);

        $text = Agenda::text('2031-05-10', '2031-05-16', self::PHONE);

        $this->assertStringContainsString("*12 Mayıs Pazartesi*\n• 14:00 Ajanda düğün çekimi", $text);
        $this->assertStringContainsString('*13 Mayıs Salı*', $text);
        $this->assertStringContainsString('• Ajanda kart ödemesi', $text);            // aylık tekrar bu haftaya düştü
        $this->assertStringContainsString('• Tahsilat: Ajanda Ali 45.000,00 ₺', $text);
        $this->assertStringContainsString("*14 Mayıs Çarşamba*\n• Çek: Ajanda Ali 20.000,00 ₺", $text);
        $this->assertStringNotContainsString('Başkasının', $text);                 // WhatsApp'ta yalnız kendi hatırlatmaları
    }

    public function test_single_empty_day_says_free(): void
    {
        $this->assertSame('✅ 20 Mayıs Salı boş görünüyor (hatırlatma, ödeme ya da çek yok).', Agenda::text('2031-05-20', '2031-05-20', self::PHONE));
    }

    public function test_monthly_occurrences_between(): void
    {
        $r = new Reminder(['event_date' => '2031-01-31', 'repeat' => 'monthly', 'anchor_day' => 31]);

        $days = array_map(fn ($d) => $d->format('m-d'), $r->occurrencesBetween(Carbon::parse('2031-02-01'), Carbon::parse('2031-04-30')));

        $this->assertSame(['02-28', '03-31', '04-30'], $days);
    }
}
