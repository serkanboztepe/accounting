<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use Concerns\LogsActivity;

    /** İşlem geçmişi: belgenin (satış/alış/iade/sözleşme) stok satırı ayrı iş değil — yalnız sayım, üretim, elle giriş/çıkış, otomatik düşüş. */
    public function activitySkipped(): bool
    {
        return $this->sale_id || $this->purchase_id || $this->sale_return_id || $this->purchase_return_id || $this->contract_id;
    }

    public function activityAction(): string
    {
        return match ($this->reason) {
            self::REASON_ADJUSTMENT => 'Stok sayımı / düzeltme',
            self::REASON_USAGE      => 'Otomatik stok düşüşü',
            self::REASON_PRODUCTION => 'Üretim',
            default                 => $this->direction === self::DIRECTION_IN ? 'Stok girişi' : 'Stok çıkışı',
        };
    }

    public function activityAmount(): ?float
    {
        return $this->amount !== null ? (float) $this->amount : null;
    }

    public function activitySummary(): ?string
    {
        $product = \App\Models\ActivityLog::name(Product::class, $this->product_id);

        return ($this->direction === self::DIRECTION_IN ? '+' : '−') . \App\Support\StockUsage::quantity((float) $this->quantity) . ' ' . $product;
    }

    public function activityDetails(): array
    {
        return \App\Models\ActivityLog::compact([
            'Tarih'       => \App\Models\ActivityLog::date($this->movement_date),
            'Ürün'        => \App\Models\ActivityLog::name(Product::class, $this->product_id),
            'Yön'         => self::DIRECTION_LABELS[$this->direction] ?? $this->direction,
            'Sebep'       => self::REASON_LABELS[$this->reason] ?? $this->reason,
            'Miktar'      => \App\Support\StockUsage::quantity((float) $this->quantity),
            'Birim fiyat' => \App\Models\ActivityLog::money($this->unit_price),
            'Tutar'       => \App\Models\ActivityLog::money($this->amount),
            'Cari'        => \App\Models\ActivityLog::name(Party::class, $this->party_id),
            \App\Support\Labels::project() => \App\Models\ActivityLog::name(\App\Models\Project::class, $this->project_id),
            'Not'         => $this->notes,
        ]);
    }

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
    public const REASON_USAGE      = 'usage';       // otomatik günlük kullanım (Product::auto_usage)
    public const REASON_PRODUCTION = 'production';  // kendi üretimi (WhatsApp "ürettim") — maliyetsiz giriş

    public const REASON_LABELS = [
        self::REASON_PURCHASE   => 'Mal Girişi',
        self::REASON_SALE       => 'Satış',
        self::REASON_RETURN     => 'İade',
        self::REASON_ADJUSTMENT => 'Düzeltme',
        self::REASON_USAGE      => 'Kullanım',
        self::REASON_PRODUCTION => 'Üretim',
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
        'purchase_id',
        'purchase_return_id',
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

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
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
