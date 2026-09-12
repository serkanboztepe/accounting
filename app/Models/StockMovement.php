<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use HasFactory;

    public const DIRECTION_IN  = 'in';   // giren
    public const DIRECTION_OUT = 'out';  // çıkan

    public const DIRECTION_LABELS = [
        self::DIRECTION_IN  => 'Giren',
        self::DIRECTION_OUT => 'Çıkan',
    ];

    public const REASON_PURCHASE   = 'purchase';    // mal girişi (alım)
    public const REASON_SALE       = 'sale';        // satış
    public const REASON_RETURN     = 'return';      // iade
    public const REASON_ADJUSTMENT = 'adjustment';  // sayım / açılış düzeltmesi

    public const REASON_LABELS = [
        self::REASON_PURCHASE   => 'Mal Girişi',
        self::REASON_SALE       => 'Satış',
        self::REASON_RETURN     => 'İade',
        self::REASON_ADJUSTMENT => 'Düzeltme',
    ];

    protected $fillable = [
        'product_id',
        'direction',
        'reason',
        'quantity',
        'unit_price',
        'amount',
        'party_id',
        'project_id',
        'contract_id',
        'sale_id',
        'sale_return_id',
        'movement_date',
        'related_movement_id',
        'notes',
    ];

    protected $casts = [
        'movement_date' => 'date',
        'quantity'      => 'decimal:2',
        'unit_price'    => 'decimal:2',
        'amount'        => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // Tutar verilmemişse miktar × birim fiyattan türet (tüm giriş yolları için).
        static::saving(function (StockMovement $movement) {
            if ($movement->amount === null && $movement->unit_price !== null) {
                $movement->amount = round((float) $movement->quantity * (float) $movement->unit_price, 2);
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    /** İade → orijinal satış hareketi. */
    public function relatedMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_movement_id');
    }

    public function isIn(): bool
    {
        return $this->direction === self::DIRECTION_IN;
    }
}
