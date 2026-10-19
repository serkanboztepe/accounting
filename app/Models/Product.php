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
        self::TYPE_SERVICE => 'Hizmet / stoksuz',
    ];

    protected $fillable = [
        'name',
        'type',
        'category',
        'unit_id',
        'default_price',
        'purchase_price',
        'is_active',
        'auto_usage',
        'daily_usage',
        'usage_every_days',
        'reorder_level',
        'usage_booked_through',
        'low_stock_alerted_at',
        'notes',
    ];

    protected $casts = [
        'default_price' => 'decimal:2',
        'purchase_price' => 'decimal:2',
        'is_active'     => 'boolean',
        'auto_usage'    => 'boolean',
        'daily_usage'   => 'decimal:2',
        'usage_every_days' => 'integer',
        'reorder_level' => 'decimal:2',
        'usage_booked_through' => 'date',
        'low_stock_alerted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Anahtar açıldığı gün düşülmüş sayılır: ilk düşüş ertesi sabah (açtığın gün iki kez düşmesin).
        static::saving(function (Product $product) {
            // Aralık değişince de yeniden başlar ("3 günde 1": ilk düşüş 3 gün sonra).
            if ($product->auto_usage && ($product->isDirty('auto_usage') || $product->isDirty('usage_every_days') || $product->usage_booked_through === null)) {
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

        return max(0, (int) floor(($stock ?? $this->currentStock()) / ((float) $this->daily_usage / $this->usageEveryDays())));
    }

    /** Günlük miktar kaç günde bir düşülür (1 = her gün). */
    public function usageEveryDays(): int
    {
        return max(1, (int) ($this->usage_every_days ?? 1));
    }

    /** "her sabah 12 adet" / "her 3 günde 1 adet" — WhatsApp ve panel aynı dili konuşsun. */
    public function usageLabel(): ?string
    {
        if (! $this->auto_usage || (float) $this->daily_usage <= 0) {
            return null;
        }
        $qty = \App\Support\StockUsage::quantity((float) $this->daily_usage) . ($this->unit?->name ? ' ' . $this->unit->name : '');

        return $this->usageEveryDays() > 1 ? 'her ' . $this->usageEveryDays() . ' günde ' . $qty : 'her sabah ' . $qty;
    }

    /**
     * Fiyatı söylenmeyen alışta kullanılacak birim fiyat: o tedarikçiden son alış → karttaki alış fiyatı →
     * herhangi birinden son alış. Satış fiyatı (default_price) ASLA kullanılmaz. Hiçbiri yoksa null (sorulur).
     */
    public function buyPrice(?int $partyId = null): ?float
    {
        if ($partyId && ($price = $this->lastPurchasePrice($partyId)) !== null) {
            return $price;
        }
        if ($this->purchase_price !== null) {
            return (float) $this->purchase_price;
        }

        return $this->lastPurchasePrice();
    }

    public function lastPurchasePrice(?int $partyId = null): ?float
    {
        $price = $this->stockMovements()
            ->where('direction', StockMovement::DIRECTION_IN)
            ->where('reason', StockMovement::REASON_PURCHASE)
            ->whereNotNull('unit_price')
            ->when($partyId, fn ($q) => $q->where('party_id', $partyId))
            ->latest('movement_date')->latest('id')
            ->value('unit_price');

        return $price !== null ? (float) $price : null;
    }

    /** Bu ürünün en son alındığı cari ("Boztepe'den" denmese de "unlar geldi" → Boztepe). */
    public function lastSupplierId(): ?int
    {
        return $this->stockMovements()
            ->where('direction', StockMovement::DIRECTION_IN)
            ->where('reason', StockMovement::REASON_PURCHASE)
            ->whereNotNull('party_id')
            ->latest('movement_date')->latest('id')
            ->value('party_id');
    }
}
