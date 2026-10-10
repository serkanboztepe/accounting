<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Panel → Takvim: firmanın hatırlatmaları (herkesinki) + ödeme günleri, gün gün; aralık seçimi. */
class CalendarPageTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_shows_firm_reminders_and_due_dates(): void
    {
        Carbon::setTestNow(Carbon::parse('2031-05-10 09:00', 'Europe/Istanbul'));
        $this->actingAs(User::factory()->create());
        Reminder::create(['phone' => '905551112233', 'text' => 'Takvim test düğün', 'event_date' => '2031-05-12', 'event_time' => '14:00']);
        Reminder::create(['phone' => '905559998877', 'text' => 'Takvim test başkasının', 'event_date' => '2031-05-13']);
        $ali = Party::create(['name' => 'Takvim Test Ali']);
        PartyLedgerEntry::create(['party_id' => $ali->id, 'entry_date' => '2031-05-01', 'due_date' => '2031-05-14', 'type' => PartyLedgerEntry::TYPE_SALE, 'amount' => 45000]);

        $this->get('/admin/calendar')->assertOk()
            ->assertSeeInOrder(['12 Mayıs Pazartesi', '14:00', 'Takvim test düğün', '13 Mayıs Salı', 'Takvim test başkasının', '14 Mayıs Çarşamba', 'Takvim Test Ali']);
    }
}
