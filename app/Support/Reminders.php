<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\Party;
use App\Models\Reminder;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * WhatsApp hatırlatma metinleri: teyit özeti, gönderilen hatırlatma, "hatırlatmalarım" listesi.
 */
class Reminders
{
    public const TIMEZONE = Reminder::TIMEZONE;

    private const REPEAT_LABELS = ['weekly' => 'her hafta', 'monthly' => 'her ay', 'yearly' => 'her yıl'];

    /** Taslaktan (AI çıktısı) kaydedilmemiş hatırlatma — teyit özeti ve kayıt aynı hesabı kullansın. */
    public static function fromDraft(array $d, string $phone): Reminder
    {
        $reminder = new Reminder([
            'phone' => Phone::normalize($phone),
            'text' => trim((string) ($d['description'] ?? '')) ?: 'Hatırlatma',
            'party_id' => $d['party_id'] ?? null,
            'event_date' => $d['event_date'],
            'event_time' => $d['event_time'] ?? null,
            'lead_minutes' => $d['lead_minutes'] ?? null,
            'is_alarm' => (bool) ($d['is_alarm'] ?? false),
            'repeat' => $d['repeat'] ?? null,
        ]);
        if (in_array($reminder->repeat, ['monthly', 'yearly'], true)) {
            $reminder->anchor_day = Carbon::parse($d['event_date'])->day;
        }
        $reminder->scheduleNext();

        return $reminder;
    }

    /** "20 Ekim Salı 14:00" / "20 Ekim Salı" (İstanbul). */
    public static function when(CarbonInterface $at, bool $withTime = true): string
    {
        $at = Carbon::instance($at)->setTimezone(Reminder::TIMEZONE)->locale('tr');

        return $at->translatedFormat($withTime ? 'j F l H:i' : 'j F l');
    }

    /** Teyit özeti: ne, ne zaman, hangi anlarda hatırlatılacak (kullanıcı kuralı görsün). */
    public static function summary(Reminder $r): string
    {
        $lines = ['⏰ *Hatırlatma — kontrol et:*'];
        $lines[] = '• Ne: ' . $r->text;
        if (! $r->is_alarm) {
            $lines[] = '• Ne zaman: ' . self::when($r->eventAt(), (bool) $r->event_time);
        }

        $alarms = [];
        $after = now();
        for ($i = 0; $i < 3; $i++) {
            $next = $r->nextAlarmAfter($after);
            if (! $next) {
                break;
            }
            $alarms[] = self::when($next[0]);
            $after = $next[0];
        }
        $lines[] = '• Hatırlatacağım: ' . implode(' · ', $alarms) . ($r->repeat ? ' …' : '');
        if ($r->repeat) {
            $lines[] = '• Tekrar: ' . self::REPEAT_LABELS[$r->repeat];
        }
        if ($r->party) {
            $lines[] = '• Cari: ' . self::balanceNote($r->party);
        }

        return implode("\n", $lines);
    }

    /**
     * Gönderilen hatırlatma (şablonun {{1}}'i — satır sonu olamaz):
     * "Yarın (20 Ekim): düğün çekimi" / "2 saat sonra (11:00): kuaför" / "Ali'den parayı al — Ali: sana borcu 45.000 ₺".
     */
    public static function fireText(Reminder $r, CarbonInterface $firedAt): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $r->text) ?? $r->text);
        $event = $r->eventAt();
        $fired = Carbon::instance($firedAt)->setTimezone(Reminder::TIMEZONE);

        $prefix = '';
        if (! $r->is_alarm) {
            $minutes = (int) round($fired->diffInMinutes($event, false));
            $days = (int) $fired->copy()->startOfDay()->diffInDays($event->copy()->startOfDay(), false);
            $prefix = match (true) {
                $r->event_time && $minutes > 0 && $minutes < 24 * 60 => self::duration($minutes) . ' sonra (' . $event->format('H:i') . '): ',
                $days === 0 => 'Bugün' . ($r->event_time ? ' ' . $event->format('H:i') : '') . ': ',
                $days === 1 => 'Yarın (' . $event->locale('tr')->translatedFormat('j F') . ($r->event_time ? ' ' . $event->format('H:i') : '') . '): ',
                $days > 1 => $days . ' gün sonra (' . $event->locale('tr')->translatedFormat('j F l') . '): ',
                default => '',
            };
        }

        $note = $r->party ? ' — ' . self::balanceNote($r->party) : '';

        return $prefix . $text . $note;
    }

    private static function duration(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return trim(($h ? "{$h} saat " : '') . ($m ? "{$m} dk" : ''));
    }

    /**
     * Carinin güncel durumu tek satır. Sözleşmeli caride cari ekstresi sözleşme ödemesini içermez →
     * kalan sözleşme ödemesi ayrıca.
     */
    public static function balanceNote(Party $party): string
    {
        $note = PartyBalances::line($party->name, PartyStatement::build($party)['balance']);

        if (config('modules.contracts')) {
            $remaining = Contract::query()->where('party_id', $party->id)->where('status', 'active')->get()
                ->sum(fn (Contract $c) => $c->remainingPaymentAmount());
            if ($remaining >= 0.01) {
                $note = rtrim($note, '.') . '; sözleşmede kalan ödemen ' . Money::format($remaining) . ' ₺';
            }
        }

        return rtrim($note, '.');
    }

    /** "hatırlatmalarım" — numaralar "iptal N" ile aynı sırada. */
    public static function listText(string $phone, int $limit = 15): string
    {
        $reminders = Reminder::upcomingFor(Phone::normalize($phone))->take($limit)->get();

        if ($reminders->isEmpty()) {
            return "Bekleyen hatırlatman yok. ⏰\nÖrnek: \"20 Ekim'de düğün çekimim var\", \"her ayın 10'unda kredi kartı ödemesi\"";
        }

        $lines = $reminders->values()->map(fn (Reminder $r, int $i) => ($i + 1) . ') ' . $r->text
            . ' — ' . ($r->is_alarm ? self::when($r->eventAt()) : self::when($r->eventAt(), (bool) $r->event_time))
            . ($r->repeat ? ' (' . self::REPEAT_LABELS[$r->repeat] . ')' : ''));

        return "⏰ *Hatırlatmaların*\n" . $lines->implode("\n") . "\n\nSilmek için *iptal 2* gibi numarasını yaz.";
    }
}
