<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    public const TYPE_PRODUCT = 'product';   // Ürün — stoklu
    public const TYPE_SERVICE = 'service';   // Hizmet — stoksuz

    public const TYPE_LABELS = [
        self::TYPE_PRODUCT => 'Ürün',
        self::TYPE_SERVICE => 'Hizmet',
    ];

    protected $fillable = [
        'name',
        'type',
        'category',
        'unit_id',
        'default_price',
        'is_active',
        'auto_usage',
        'daily_usage',
        'reorder_level',
        'usage_booked_through',
        'low_stock_alerted_at',
        'notes',
    ];

    protected $casts = [
        'default_price' => 'decimal:2',
        'is_active'     => 'boolean',
        'auto_usage'    => 'boolean',
        'daily_usage'   => 'decimal:2',
        'reorder_level' => 'decimal:2',
        'usage_booked_through' => 'date',
        'low_stock_alerted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Anahtar açıldığı gün düşülmüş sayılır: ilk düşüş ertesi sabah (açtığın gün iki kez düşmesin).
        static::saving(function (Product $product) {
            if ($product->auto_usage && ($product->isDirty('auto_usage') || $product->usage_booked_through === null)) {
                $product->usage_booked_through = now(Reminder::TIMEZONE)->toDateString();
            }
            if (! $product->auto_usage) {
                $product->usage_booked_through = null;
            }
        });
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function isProduct(): bool
    {
        return $this->type === self::TYPE_PRODUCT;
    }

    public function isService(): bool
    {
        return $this->type === self::TYPE_SERVICE;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Mevcut stok = Σ(giren) − Σ(çıkan). Yalnız stock_movements'tan türetilir
     * (contract_deliveries'ten DEĞİL — proje maliyeti ile stok ayrı dünyalar).
     * Hizmet için anlamsız; her zaman 0 döner (hareket girilmez).
     */
    public function currentStock(): float
    {
        $in = (float) $this->stockMovements()
            ->where('direction', StockMovement::DIRECTION_IN)->sum('quantity');
        $out = (float) $this->stockMovements()
            ->where('direction', StockMovement::DIRECTION_OUT)->sum('quantity');

        return $in - $out;
    }

    /**
     * Sayım: "elinde kaç tane var?" — sistemdeki stokla fark Düzeltme hareketi olarak yazılır
     * (fazla → giren, eksik → çıkan). Fark yoksa hareket yazılmaz.
     */
    public function countTo(float $counted, ?string $date = null, ?string $note = null): ?StockMovement
    {
        $diff = round($counted - $this->currentStock(), 2);
        if (abs($diff) < 0.005) {
            return null;
        }

        return $this->stockMovements()->create([
            'direction'     => $diff > 0 ? StockMovement::DIRECTION_IN : StockMovement::DIRECTION_OUT,
            'reason'        => StockMovement::REASON_ADJUSTMENT,
            'quantity'      => abs($diff),
            'movement_date' => $date ?? now()->toDateString(),
            'notes'         => $note ?? 'Sayım: ' . rtrim(rtrim(number_format($counted, 2, ',', '.'), '0'), ','),
        ]);
    }

    /** Eşik girilmiş ve stok eşiğe (veya altına) inmiş mi. */
    public function isLowStock(?float $stock = null): bool
    {
        return $this->isProduct()
            && $this->reorder_level !== null
            && ($stock ?? $this->currentStock()) <= (float) $this->reorder_level;
    }

    /** Günlük kullanıma göre stok kaç gün yeter (otomatik düşüş kapalıysa null). */
    public function daysLeft(?float $stock = null): ?int
    {
        if (! $this->auto_usage || (float) $this->daily_usage <= 0) {
            return null;
        }

        return max(0, (int) floor(($stock ?? $this->currentStock()) / (float) $this->daily_usage));
    }
}
