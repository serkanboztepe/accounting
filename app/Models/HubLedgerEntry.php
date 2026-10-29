<?php

namespace App\Models;

use App\Tenancy\UsesCentralConnection;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Hub para satırı (merkez): sabit gider · kredi yüklemesi · firma abonelik ödemesi. Rapor (HubReport) bunlardan hesaplanır.
 * Kredi yüklemesi gider DEĞİLDİR (harcama zaten AI / mesaj maliyetinde) — yalnız bakiye ve "bu ay yüklenen" bilgisi.
 */
class HubLedgerEntry extends Model
{
    use UsesCentralConnection;

    public const FIXED = 'fixed_cost';

    public const TOPUP = 'credit_topup';

    public const PAYMENT = 'payment';

    public const CURRENCIES = ['TRY' => '₺', 'USD' => '$', 'EUR' => '€'];

    protected $table = 'hub_ledger';

    protected $fillable = ['kind', 'provider', 'hub_firm_id', 'title', 'amount', 'currency', 'period', 'starts_on', 'ends_on', 'paid_on', 'note'];

    protected $casts = ['amount' => 'float', 'starts_on' => 'date', 'ends_on' => 'date', 'paid_on' => 'date'];

    protected static function booted(): void
    {
        // Kapanmış ayların raporu önbellekte — para satırı değişince yeniden hesaplansın.
        static::saved(fn () => self::bumpReport());
        static::deleted(fn () => self::bumpReport());
    }

    public static function bumpReport(): void
    {
        Cache::forever('hub-report-version', (int) Cache::get('hub-report-version', 0) + 1);
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(HubFirm::class, 'hub_firm_id');
    }

    /** Bu ay içinde geçerli sabit giderler (başlangıç ≤ ay sonu, bitiş boş ya da ≥ ay başı). */
    public static function fixedIn(CarbonInterface $month): Builder
    {
        $start = Carbon::parse($month)->startOfMonth();

        return static::where('kind', self::FIXED)->whereDate('starts_on', '<=', $start->copy()->endOfMonth()->toDateString())
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start->toDateString()));
    }

    /** Sabit giderin aylık karşılığı (kendi para biriminde): yıllık 12'ye bölünür. */
    public function monthlyAmount(): float
    {
        return $this->period === 'yearly' ? $this->amount / 12 : $this->amount;
    }

    /** Ödemenin kapsadığı ay sayısı (aylık 1, yıllık 12) — gelir bu aylara eşit dağıtılır. */
    public function coveredMonths(): int
    {
        if (! $this->ends_on) {
            return 1;
        }
        $from = Carbon::parse($this->starts_on)->startOfMonth();
        $to = Carbon::parse($this->ends_on)->startOfMonth();

        return max(1, (int) round($from->diffInMonths($to)) + 1);
    }

    public function covers(CarbonInterface $month): bool
    {
        $m = Carbon::parse($month)->startOfMonth();

        return Carbon::parse($this->starts_on)->startOfMonth()->lte($m)
            && Carbon::parse($this->ends_on ?? $this->starts_on)->startOfMonth()->gte($m);
    }

    public function symbol(): string
    {
        return self::CURRENCIES[$this->currency] ?? $this->currency;
    }
}
