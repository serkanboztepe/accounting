<?php

namespace Tests\Feature;

use App\Models\Reminder;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Hatırlatma kuralları (kullanıcı kararı 2026-10-10) — her senaryo konuşmadaki bir örnek.
 * "Şimdi": 10 Ekim 2026 Cumartesi 12:00 İstanbul.
 */
class ReminderScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00', Reminder::TIMEZONE));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<string> sıradaki $n hatırlatma (İstanbul, "d.m H:i") */
    private function fires(array $attrs, int $n = 2): array
    {
        $r = new Reminder($attrs + ['text' => 'test', 'phone' => '905550000000']);
        if (in_array($r->repeat, ['monthly', 'yearly'], true)) {
            $r->anchor_day ??= Carbon::parse($attrs['event_date'])->day;
        }
        $out = [];
        $after = now();
        for ($i = 0; $i < $n; $i++) {
            $next = $r->nextAlarmAfter($after);
            if (! $next) {
                break;
            }
            $out[] = $next[0]->copy()->setTimezone(Reminder::TIMEZONE)->format('d.m H:i');
            $after = $next[0];
        }

        return $out;
    }

    public function test_day_only_event_reminds_one_day_before_and_same_day_at_eight(): void
    {
        // "20 Ekim düğün çekimim var"
        $this->assertSame(['19.10 08:00', '20.10 08:00'], $this->fires(['event_date' => '2026-10-20']));
    }

    public function test_day_event_with_lead_days(): void
    {
        // "20 Ekim düğün, 2 gün önceden hatırlat"
        $this->assertSame(['18.10 08:00', '20.10 08:00'], $this->fires(['event_date' => '2026-10-20', 'lead_minutes' => 2 * 1440]));
    }

    public function test_timed_event_reminds_one_hour_before_only(): void
    {
        // "yarın saat 11'de randevum var"
        $this->assertSame(['11.10 10:00'], $this->fires(['event_date' => '2026-10-11', 'event_time' => '11:00'], 3));
    }

    public function test_timed_event_with_lead_hours(): void
    {
        // "yarın 11'de randevum var, 2 saat önce hatırlat" (yola çıkma payı)
        $this->assertSame(['11.10 09:00'], $this->fires(['event_date' => '2026-10-11', 'event_time' => '11:00', 'lead_minutes' => 120], 3));
    }

    public function test_alarm_fires_exactly_at_time(): void
    {
        // "2 saat sonra hatırlat" / "saat 14'te hatırlat"
        $this->assertSame(['10.10 14:00'], $this->fires(['event_date' => '2026-10-10', 'event_time' => '14:00', 'is_alarm' => true], 3));
    }

    public function test_past_alarms_are_skipped(): void
    {
        // "yarın Ahmet'i aramamı hatırlat" (yalnız gün): 1 gün önce = bugün 08:00 geçti → yalnız yarın 08:00
        $this->assertSame(['11.10 08:00'], $this->fires(['event_date' => '2026-10-11'], 3));
        // Tamamen geçmiş olay → hiç
        $this->assertSame([], $this->fires(['event_date' => '2026-10-09']));
    }

    public function test_monthly_credit_card_on_the_tenth(): void
    {
        // "Kredi kartına her ayın 10'unda ödemem var" — bugün 10'u 12:00, bu ayınki geçti
        $this->assertSame(['09.11 08:00', '10.11 08:00', '09.12 08:00'],
            $this->fires(['event_date' => '2026-10-10', 'repeat' => 'monthly'], 3));
    }

    public function test_monthly_on_31st_keeps_anchor_day(): void
    {
        $this->assertSame(['31.10 08:00', '30.11 08:00', '31.12 08:00'],
            $this->fires(['event_date' => '2026-10-31', 'repeat' => 'monthly', 'is_alarm' => true], 3));
    }

    public function test_weekly_and_yearly(): void
    {
        // "Her pazartesi 10'da toplantı" → 1 saat önce
        $this->assertSame(['12.10 09:00', '19.10 09:00'],
            $this->fires(['event_date' => '2026-10-12', 'event_time' => '10:00', 'repeat' => 'weekly']));
        // "Kasko her yıl 3 Mart, 1 hafta önce"
        $this->assertSame(['24.02 08:00', '03.03 08:00'],
            $this->fires(['event_date' => '2027-03-03', 'lead_minutes' => 7 * 1440, 'repeat' => 'yearly']));
    }

    public function test_acknowledge_cancels_rest_of_occurrence(): void
    {
        $r = new Reminder(['text' => 'test', 'phone' => '905550000000', 'event_date' => '2026-10-20', 'repeat' => 'monthly', 'anchor_day' => 20]);
        $r->scheduleNext();
        $this->assertSame('19.10 08:00', $r->next_fire_at->copy()->setTimezone(Reminder::TIMEZONE)->format('d.m H:i'));

        // "tamam" → 20 Ekim'deki ikinci hatırlatma gitmez, Kasım olayına geçer
        $r->event_date = Carbon::parse('2026-10-20');
        $r->acknowledged_at = now();
        $r->event_date = $r->nextOccurrence($r->event_date)->toDateString();
        $r->scheduleNext();
        $this->assertSame('19.11 08:00', $r->next_fire_at->copy()->setTimezone(Reminder::TIMEZONE)->format('d.m H:i'));
    }
}
