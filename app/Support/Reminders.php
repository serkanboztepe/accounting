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

    private const REPEAT_LABELS = ['daily' => 'her gün', 'weekly' => 'her hafta', 'monthly' => 'her ay', 'yearly' => 'her yıl'];

    /** Taslaktan (AI çıktısı) kaydedilmemiş hatırlatma — teyit özeti ve kayıt aynı hesabı kullansın. */
    public static function fromDraft(array $d, string $phone): Reminder
    {
        $reminder = new Reminder([
            'phone' => Phone::normalize($phone),
            'text' => trim((string) ($d['description'] ?? '')) ?: 'Hatırlatma',
            'party_id' => $d['party_id'] ?? null,
            'event_date' => $d['event_date'],
            'event_time' => $d['event_time'] ?? null,
            'notify_time' => empty($d['event_time']) ? ($d['notify_time'] ?? null) : null,
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

    /**
     * Kayıtlı hatırlatmayı DEĞİŞTİRME isteği (Ferhat 25.10: "Hatırlatmaları saat 10:00 da yap" — asistan "ayarlıyorum" deyip
     * hiçbir şey yapmamıştı): bu numaranın aktif hatırlatması; değilse null (yeni hatırlatma gibi davranılır).
     */
    public static function target(array $d, string $phone): ?Reminder
    {
        $id = (int) ($d['updates_reminder_id'] ?? 0);

        return $id > 0
            ? Reminder::whereKey($id)->where('phone', Phone::normalize($phone))->where('status', 'active')->first()
            : null;
    }

    /**
     * Değişiklik uygulanmış KAYDEDİLMEMİŞ kopya (kart ve kayıt aynı hesabı kullanır). Taslakta dolu alan değişir, boş alan
     * eskisi gibi kalır. Saatsiz olaya saat söylenirse ("10'da yap") olayın saati değil HATIRLATMA saati değişir.
     */
    public static function updated(Reminder $old, array $d): Reminder
    {
        $new = $old->replicate(['next_fire_at', 'attempts', 'last_sent_at', 'acknowledged_at']);
        $new->id = $old->id;
        $new->exists = true;
        if (! empty($d['description'])) {
            $new->text = trim((string) $d['description']);
        }
        if (! empty($d['event_date'])) {
            $new->event_date = $d['event_date'];
            if (in_array($new->repeat, ['monthly', 'yearly'], true)) {
                $new->anchor_day = Carbon::parse($d['event_date'])->day;
            }
        }
        if (! empty($d['lead_minutes'])) {
            $new->lead_minutes = (int) $d['lead_minutes'];
        }
        $time = $d['notify_time'] ?? $d['event_time'] ?? null;
        if ($time && $old->is_alarm) {
            // "Tam o saatte" hatırlatma ve düzenli gider: saat olayın kendisi — notify_time'a yazılınca 08:00 kalıyordu (Çiftlik 26.10).
            $new->event_time = $time;
        } elseif ($time) {
            $old->event_time && empty($d['notify_time']) ? $new->event_time = $time : $new->notify_time = $time;
        }
        if (! empty($d['repeat']) && in_array($d['repeat'], Reminder::REPEATS, true)) {
            $new->repeat = $d['repeat'];
            $new->anchor_day = in_array($new->repeat, ['monthly', 'yearly'], true) ? Carbon::parse($new->event_date)->day : null;
        }
        $new->status = 'active';
        $new->scheduleNext();

        return $new;
    }

    /** Değişiklik kartı: eski ve yeni hatırlatma anları yan yana. */
    public static function changeSummary(Reminder $old, Reminder $new): string
    {
        return implode("\n", array_filter([
            '⏰ *Hatırlatma değişiyor — kontrol et:*',
            '• Ne: ' . $new->text . ($new->text !== $old->text ? ' (önce: ' . $old->text . ')' : ''),
            $new->is_alarm ? null : '• Ne zaman: ' . self::when($new->eventAt(), (bool) $new->event_time),
            '• Şu an: ' . (self::alarmList($old) ?: '—'),
            '• Yeni: ' . (self::alarmList($new) ?: '—'),
        ]));
    }

    /** Sıradaki en çok 3 hatırlatma anı ("29 Ekim Perşembe 10:00 · 30 Ekim Cuma 10:00"). */
    private static function alarmList(Reminder $r): string
    {
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

        return implode(' · ', $alarms) . ($r->repeat && $alarms ? ' …' : '');
    }

    /**
     * AI bağlamı: bu numaranın aktif hatırlatmaları — "hatırlatmaları 10'da yap" hangisini kastediyor bilsin
     * (updates_reminder_id). Yoksa boş.
     */
    public static function contextBlock(?string $phone): string
    {
        if (! $phone) {
            return '';
        }
        $rows = Reminder::where('phone', Phone::normalize($phone))->where('status', 'active')
            ->whereNotNull('next_fire_at')->orderBy('next_fire_at')->limit(8)->get();
        if ($rows->isEmpty()) {
            return '';
        }

        return "\n\nAKTİF HATIRLATMALAR (kayıtlı; değiştirme isteğinde `updates_reminder_id` = id):\n"
            . $rows->map(fn (Reminder $r) => sprintf('  #%d: %s — olay %s · sıradaki hatırlatma %s',
                $r->id, $r->text, self::when($r->eventAt(), (bool) $r->event_time), self::when($r->next_fire_at)))->implode("\n");
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

        $lines[] = '• Hatırlatacağım: ' . self::alarmList($r);
        if ($r->repeat) {
            $lines[] = '• Tekrar: ' . self::REPEAT_LABELS[$r->repeat];
        }
        if ($r->party) {
            $lines[] = '• Hesap: ' . self::balanceNote($r->party);
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

        // Personelin maaş günü: bakiye yerine maaş − avans = ödenecek.
        $salary = $r->party && mb_stripos($r->text, 'maaş') !== false ? \App\Services\Whatsapp\EmployeeDraft::reminderNote($r->party) : null;
        $note = $r->party ? ' — ' . ($salary ?? self::balanceNote($r->party)) : '';

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

        if (\App\Support\Features::contracts()) {
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
