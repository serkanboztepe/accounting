<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * WhatsApp hatırlatması. Kurallar (kullanıcı kararı 2026-10-10):
 *   - "15'te hatırlat" / "2 saat sonra hatırlat" (is_alarm) → tam o an, tek.
 *   - Saatli olay ("yarın 11'de randevu") → söylenen kadar önce, yoksa 1 SAAT önce (tek).
 *   - Yalnız gün ("20 Ekim düğün") → söylenen gün kadar önce, yoksa 1 GÜN önce 08:00 + o gün 08:00.
 *   - Saat söylenmezse 08:00. Geçmişte kalan hatırlatma atlanır. Tekrar: haftalık / aylık / yıllık.
 */
class Reminder extends Model
{
    public const TIMEZONE = 'Europe/Istanbul';

    public const DEFAULT_HOUR = 8;

    public const REPEATS = ['daily', 'weekly', 'monthly', 'yearly'];

    /** Gönderildikten sonra bu kadar saat "aldım / tamam" cevabı bu hatırlatmaya bağlanır. */
    public const REPLY_WINDOW_HOURS = 12;

    protected $fillable = [
        'phone', 'text', 'party_id', 'event_date', 'event_time', 'lead_minutes', 'is_alarm', 'repeat',
        'anchor_day', 'next_fire_at', 'status', 'attempts', 'last_sent_at', 'acknowledged_at',
        'amount', 'expense_category_id', 'project_id',
    ];

    protected $casts = [
        'event_date' => 'date',
        'is_alarm' => 'boolean',
        'next_fire_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Düzenli gider: tutarı olan hatırlatma o gün mesaj yerine gider yazar (kullanıcı kararı 19.10). */
    public function isExpense(): bool
    {
        return (float) $this->amount > 0;
    }

    /**
     * O günün giderini yaz; aynı gün zaten yazıldıysa null (notlardaki "Düzenli gider #id" işaretiyle — ayrı kolon yok).
     */
    public function postExpense(CarbonInterface $day): ?Expense
    {
        $date = Carbon::parse($day->toDateString())->toDateString();
        $mark = 'Düzenli gider #' . $this->id;
        if (Expense::whereDate('expense_date', $date)->where('notes', 'like', $mark . ' %')->exists()) {
            return null;
        }

        return Expense::create([
            'expense_category_id' => $this->expense_category_id,
            'project_id' => $this->project_id,
            'expense_date' => $date,
            'amount' => $this->amount,
            'payment_status' => 'paid',
            'description' => $this->text,
            'notes' => $mark . ' (' . $this->scheduleText() . ')',
        ]);
    }

    /** "her gün" / "her pazartesi" / "her ayın 1'i" / "her yıl 5 Mart" / "tek sefer". */
    public function scheduleText(): string
    {
        $day = $this->event_date ? Carbon::parse($this->event_date->toDateString())->locale('tr') : null;

        return match ($this->repeat) {
            'daily' => 'her gün',
            'weekly' => 'her ' . mb_strtolower($day?->translatedFormat('l') ?? 'hafta'),
            'monthly' => 'her ayın ' . ($d = $this->anchor_day ?: ($day?->day ?? 1)) . "'" . self::dayEnding($d),
            'yearly' => 'her yıl ' . ($day?->translatedFormat('j F') ?? ''),
            default => 'tek sefer',
        };
    }

    /** Türkçe ek: 1'i, 2'si, 3'ü, 6'sı, 9'u, 10'u, 20'si, 30'u (son okunan sayıya göre). */
    private static function dayEnding(int $d): string
    {
        if ($d % 10 === 0) {
            return $d === 20 ? 'si' : 'u';
        }

        return [1 => 'i', 2 => 'si', 3 => 'ü', 4 => 'ü', 5 => 'i', 6 => 'sı', 7 => 'si', 8 => 'i', 9 => 'u'][$d % 10];
    }

    /** Olay anı (İstanbul): gün + saat; saat yoksa 08:00. */
    public function eventAt(?CarbonInterface $date = null): Carbon
    {
        $day = Carbon::parse(($date ?? $this->event_date)->toDateString(), self::TIMEZONE);

        return $this->event_time
            ? $day->setTimeFromTimeString($this->event_time)
            : $day->setTime(self::DEFAULT_HOUR, 0);
    }

    /**
     * Bir olay gününün hatırlatma anları (İstanbul, kronolojik).
     *
     * @return list<Carbon>
     */
    public function alarmsFor(CarbonInterface $date): array
    {
        $event = $this->eventAt($date);

        if ($this->is_alarm) {
            return [$event];
        }
        if ($this->event_time) {
            return [$event->copy()->subMinutes($this->lead_minutes ?? 60)];
        }

        $days = $this->lead_minutes ? max(1, (int) round($this->lead_minutes / 1440)) : 1;

        return [$event->copy()->subDays($days), $event];
    }

    /**
     * $after'dan sonraki ilk hatırlatma ve ait olduğu olay günü. Tekrarlıda ileriki olaylara bakar;
     * tek seferlikte kalmadıysa null. Uygulama saat dilimine çevrilmiş döner (kayıt için).
     *
     * @return array{0: Carbon, 1: Carbon}|null [hatırlatma anı, olay günü]
     */
    public function nextAlarmAfter(CarbonInterface $after): ?array
    {
        $date = Carbon::parse($this->event_date->toDateString(), self::TIMEZONE);

        for ($i = 0; $i < 400; $i++) {
            foreach ($this->alarmsFor($date) as $alarm) {
                if ($alarm->gt($after)) {
                    return [$alarm->setTimezone(config('app.timezone')), $date];
                }
            }
            if (! $this->repeat) {
                return null;
            }
            $date = $this->nextOccurrence($date);
        }

        return null;
    }

    /**
     * Aralığa düşen olay günleri (takvim: "bu hafta neler var"). Tekrarlıda her tekrar ayrı gün.
     * "X'te hatırlat" (is_alarm) da olay sayılır — o gün yapılacak iş.
     *
     * @return list<Carbon>
     */
    public function occurrencesBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        $date = Carbon::parse($this->event_date->toDateString(), self::TIMEZONE);
        $from = Carbon::parse($from->toDateString(), self::TIMEZONE);
        $to = Carbon::parse($to->toDateString(), self::TIMEZONE);

        $out = [];
        for ($i = 0; $i < 400 && $date->lte($to); $i++) {
            if ($date->gte($from)) {
                $out[] = $date->copy();
            }
            if (! $this->repeat) {
                break;
            }
            $date = $this->nextOccurrence($date);
        }

        return $out;
    }

    /** Tekrarlıda bir sonraki olay günü (aylık/yıllıkta asıl gün korunur: 31 → 30 → 31). */
    public function nextOccurrence(CarbonInterface $date): Carbon
    {
        $next = Carbon::parse($date->toDateString(), self::TIMEZONE);

        return match ($this->repeat) {
            'daily' => $next->addDay(),
            'weekly' => $next->addWeek(),
            'yearly' => self::onDay($next->addYearNoOverflow(), $this->anchor_day),
            default => self::onDay($next->startOfMonth()->addMonth(), $this->anchor_day),
        };
    }

    private static function onDay(Carbon $date, ?int $day): Carbon
    {
        return $day ? $date->setDay(min($day, $date->daysInMonth)) : $date;
    }

    /** Sıradaki hatırlatmayı (ve tekrarlıda olay gününü) şu andan sonrasına göre ayarla. */
    public function scheduleNext(?CarbonInterface $after = null): void
    {
        $next = $this->nextAlarmAfter($after ?? now());

        $this->next_fire_at = $next[0] ?? null;
        if ($next) {
            $this->event_date = $next[1]->toDateString();
        } elseif ($this->status === 'active') {
            $this->status = 'done';
        }
    }

    /**
     * "tamam" = gördüm: kalan hatırlatmalar İPTAL EDİLMEZ (olay günü yine hatırlatılır — kullanıcı
     * kuralı). Aynı anda iki hatırlatma gidince "tamam"ın hangisine olduğu belirsizdi; Ali'nin ertesi
     * günkü hatırlatması yanlışlıkla iptal oluyordu.
     */
    public function markSeen(): void
    {
        $this->acknowledged_at = now();
        if (! $this->next_fire_at && $this->status === 'active') {
            $this->status = 'done';
        }
        $this->save();
    }

    /** Görülmemiş, son gönderilen hatırlatmalar (cevap penceresi içinde). @return \Illuminate\Support\Collection<int, self> */
    public static function allAwaitingReply(string $phone)
    {
        return static::query()
            ->where('phone', $phone)
            ->where('last_sent_at', '>=', now()->subHours(self::REPLY_WINDOW_HOURS))
            ->where(fn (Builder $q) => $q->whereNull('acknowledged_at')->orWhereColumn('acknowledged_at', '<', 'last_sent_at'))
            ->orderBy('last_sent_at')
            ->get();
    }

    /**
     * İş bitti (bağlı tahsilat/ödeme onaylandı, ya da erteleme ve kalan hatırlatma yok): bu olayın kalan
     * hatırlatmaları iptal — tekrarlıda bir sonraki olaya geçer, tek seferlik kapanır.
     */
    public function acknowledge(): void
    {
        $this->acknowledged_at = now();
        if ($this->repeat) {
            // Olay günü hatırlatması gidince tarih zaten sıradaki aya geçer; aynı gün "ödendi" bir ay daha atlatıyordu
            // (Akkuş 19.10: Hikmet 17, Afgan Apo 19 Ekim ödendi → Kasım atlandı, sıradaki Aralık). Bu olayın ilk hatırlatması
            // son gönderimden sonraysa tarih zaten ileride — yalnız yeniden zamanla.
            $first = $this->alarmsFor($this->event_date)[0] ?? null;
            $alreadyNext = $this->last_sent_at && $first && $first->gt($this->last_sent_at);
            if (! $alreadyNext) {
                $this->event_date = $this->nextOccurrence($this->event_date)->toDateString();
            }
            $this->scheduleNext(now());
        } else {
            $this->status = 'done';
            $this->next_fire_at = null;
        }
        $this->save();
    }

    /** Bu telefonun bekleyen hatırlatmaları — "hatırlatmalarım" ve "iptal N" aynı sırayı kullanır. */
    public static function upcomingFor(string $phone): Builder
    {
        return static::query()->where('phone', $phone)->where('status', 'active')
            ->orderBy('next_fire_at')->orderBy('id');
    }

    /** Son gönderilen ve henüz cevaplanmamış hatırlatma (cevap penceresi içinde); cari verilirse o carinin. */
    public static function awaitingReply(string $phone, ?int $partyId = null): ?self
    {
        return static::query()
            ->where('phone', $phone)
            ->when($partyId, fn (Builder $q) => $q->where('party_id', $partyId))
            ->where('last_sent_at', '>=', now()->subHours(self::REPLY_WINDOW_HOURS))
            ->where(fn (Builder $q) => $q->whereNull('acknowledged_at')->orWhereColumn('acknowledged_at', '<', 'last_sent_at'))
            ->latest('last_sent_at')
            ->first();
    }
}
