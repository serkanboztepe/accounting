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
        'notes',
    ];

    protected $casts = [
        'default_price' => 'decimal:2',
        'is_active'     => 'boolean',
    ];

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
}
