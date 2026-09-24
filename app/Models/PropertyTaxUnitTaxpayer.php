<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daire ↔ Mükellef atama pivotu + hisse (pay/payda).
 * Kat Karşılığı'ndaki LandSectionAllocation ile aynı.
 */
class PropertyTaxUnitTaxpayer extends Model
{
    protected $table = 'property_tax_unit_taxpayer';

    protected $fillable = [
        'property_tax_unit_id',
        'property_tax_taxpayer_id',
        'pay',
        'payda',
    ];

    protected $casts = [
        'pay'   => 'integer',
        'payda' => 'integer',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(PropertyTaxUnit::class, 'property_tax_unit_id');
    }

    public function taxpayer(): BelongsTo
    {
        return $this->belongsTo(PropertyTaxTaxpayer::class, 'property_tax_taxpayer_id');
    }

    /** Hisse oranı metni, ör. "1/2" veya tam ise "TAM". */
    public function shareText(): string
    {
        if ($this->pay > 0 && $this->pay === $this->payda) {
            return 'TAM';
        }

        return $this->pay.'/'.$this->payda;
    }

    /** Hisse kesri (0..1). */
    public function shareFraction(): float
    {
        return $this->payda > 0 ? $this->pay / $this->payda : 0.0;
    }
}
