<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Düzenli gider: her gün / hafta / ay kendiliğinden yazılan gider (ekmek, kira, abonelik). Her sabah expenses:recurring
 * zamanı gelen günleri yazar; sunucu bir sabah kaçırırsa sonraki çalışmada telafi eder, aynı günü iki kez yazmaz.
 */
class RecurringExpense extends Model
{
    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    public const FREQUENCIES = [self::DAILY => 'Her gün', self::WEEKLY => 'Her hafta', self::MONTHLY => 'Her ay'];

    public const WEEKDAYS = [1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar'];

    protected $fillable = ['description', 'amount', 'expense_category_id', 'project_id', 'frequency', 'weekday', 'day_of_month',
        'start_date', 'posted_through', 'paid', 'is_active', 'created_by_phone', 'source'];

    protected $casts = [
        'amount' => 'decimal:2',
        'weekday' => 'integer',
        'day_of_month' => 'integer',
        'start_date' => 'date',
        'posted_through' => 'date',
        'paid' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** "her gün 34,60 ₺" / "her pazartesi 500,00 ₺" / "her ayın 1'i 20.000,00 ₺" */
    public function label(): string
    {
        return self::scheduleText($this->frequency, $this->weekday, $this->day_of_month) . ' ' . Money::format((float) $this->amount) . ' ₺';
    }

    public static function scheduleText(string $frequency, ?int $weekday, ?int $dayOfMonth): string
    {
        return match ($frequency) {
            self::WEEKLY => 'her ' . mb_strtolower(self::WEEKDAYS[$weekday ?: 1]),
            self::MONTHLY => 'her ayın ' . ($dayOfMonth ?: 1) . "'" . self::dayEnding($dayOfMonth ?: 1),
            default => 'her gün',
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

    /** Bir gün bu düzene uyuyor mu. */
    public function fallsOn(Carbon $day): bool
    {
        return match ($this->frequency) {
            self::WEEKLY => $day->dayOfWeekIso === ($this->weekday ?: 1),
            self::MONTHLY => $day->day === min($this->day_of_month ?: 1, $day->daysInMonth),
            default => true,
        };
    }

    /** İlk (ya da sıradaki) kayıt günü. */
    public function nextDate(?Carbon $after = null): Carbon
    {
        $day = ($this->posted_through ? Carbon::parse($this->posted_through->toDateString())->addDay() : Carbon::parse($this->start_date->toDateString()));
        if ($after && $day->lt($after)) {
            $day = $after->copy();
        }
        for ($i = 0; $i < 400 && ! $this->fallsOn($day); $i++) {
            $day->addDay();
        }

        return $day;
    }

    /**
     * Bugüne kadar yazılmamış günlerin giderini yaz (kaçan günler telafi; en çok 62 gün geriye).
     *
     * @return int yazılan gider sayısı
     */
    public function post(?Carbon $today = null): int
    {
        // Yalnız takvim günü karşılaştırılır (sunucu UTC, gün İstanbul'a göre).
        $today = Carbon::parse(($today ?? Carbon::now(Reminder::TIMEZONE))->toDateString());
        if ($this->is_active === false) {
            return 0;
        }

        return DB::transaction(function () use ($today) {
            $self = self::lockForUpdate()->find($this->id);
            if (! $self->is_active) {
                return 0;
            }
            $from = $self->posted_through ? Carbon::parse($self->posted_through->toDateString())->addDay() : Carbon::parse($self->start_date->toDateString());
            $from = $from->max($today->copy()->subDays(62));
            $written = 0;
            for ($day = $from->copy(); $day->lte($today); $day->addDay()) {
                if (! $self->fallsOn($day) || Expense::where('recurring_expense_id', $self->id)->whereDate('expense_date', $day)->exists()) {
                    continue;
                }
                Expense::create([
                    'recurring_expense_id' => $self->id,
                    'expense_category_id' => $self->expense_category_id,
                    'project_id' => $self->project_id,
                    'expense_date' => $day->toDateString(),
                    'amount' => $self->amount,
                    'payment_status' => $self->paid ? 'paid' : 'unpaid',
                    'description' => $self->description,
                    'notes' => 'Düzenli gider (' . $self->label() . ')',
                ]);
                $written++;
            }
            if ($from->lte($today)) {
                $self->update(['posted_through' => $today->toDateString()]);
            }
            $this->setRawAttributes($self->getAttributes());

            return $written;
        });
    }
}
