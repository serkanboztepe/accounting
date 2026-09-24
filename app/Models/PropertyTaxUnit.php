<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Emlak Vergisi Bildirimi — Daire (bağımsız bölüm) seviyesi.
 * Yalnız kendine özel alanlar; boş bırakılan alanları bloktan/projeden devralır.
 * Arsa payı pay/payda daire bazında; mükellef atamaları pivot (hisse) ile.
 */
class PropertyTaxUnit extends Model
{
    protected $fillable = [
        'property_tax_block_id',
        'unit_no',
        'floor_no',
        'floor_position',
        'area',
        'land_share_numerator',
        'land_share_denominator',
        'usage_type',
        'construction_class',
        'share_ratio',
        'neighborhood',
        'street',
        'sort_order',
    ];

    protected $casts = [
        'floor_no'               => 'integer',
        'floor_position'         => 'integer',
        'area'                   => 'decimal:2',
        'land_share_numerator'   => 'integer',
        'land_share_denominator' => 'integer',
        'sort_order'             => 'integer',
    ];

    public function block(): BelongsTo
    {
        return $this->belongsTo(PropertyTaxBlock::class, 'property_tax_block_id');
    }

    /** Kat etiketi: 0 → "Zemin", diğerleri "N. Kat". */
    public function floorLabel(): string
    {
        return (int) ($this->floor_no ?? 0) === 0 ? 'Zemin' : $this->floor_no.'. Kat';
    }

    /** Kat seçim listesi: Zemin (0), 1. Kat, 2. Kat … */
    public static function floorOptions(int $max = 40): array
    {
        $options = [0 => 'Zemin'];
        for ($i = 1; $i <= $max; $i++) {
            $options[$i] = $i.'. Kat';
        }

        return $options;
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PropertyTaxUnitTaxpayer::class);
    }

    /** Bu daireyi paylaşan mükellefler (hisse pivotuyla). */
    public function taxpayers(): BelongsToMany
    {
        return $this->belongsToMany(PropertyTaxTaxpayer::class, 'property_tax_unit_taxpayer')
            ->withPivot(['pay', 'payda'])
            ->withTimestamps();
    }

    // ── Miras: null ise bloktan/projeden devral ────────────────────────────

    public function effectiveUsageType(): ?string
    {
        return $this->usage_type ?: $this->block?->usage_type;
    }

    public function effectiveConstructionClass(): ?string
    {
        return $this->construction_class ?: $this->block?->construction_class;
    }

    public function effectiveShareRatio(): ?string
    {
        return $this->share_ratio ?: $this->block?->share_ratio;
    }

    public function effectiveNeighborhood(): ?string
    {
        return $this->neighborhood ?: $this->block?->project?->neighborhood;
    }

    public function effectiveStreet(): ?string
    {
        return $this->street ?: $this->block?->project?->street;
    }

    /** Arsa payı payı — daire boşsa bloğun varsayılanını devral. */
    public function landShareNumerator(): ?int
    {
        return $this->land_share_numerator ?: $this->block?->land_share_numerator;
    }

    /** Arsa payı paydası — daire boşsa bloğun varsayılanını devral. */
    public function landShareDenominator(): ?int
    {
        return $this->land_share_denominator ?: $this->block?->land_share_denominator;
    }

    /** Arsa payı oranı metni, ör. "1/8". */
    public function landShareRatioText(): ?string
    {
        $num = $this->landShareNumerator();
        $den = $this->landShareDenominator();
        if (! $num || ! $den) {
            return null;
        }

        return $num.'/'.$den;
    }

    /** Arsa payına düşen metrekare = arsa alanı × (pay / payda). */
    public function landShareArea(): ?float
    {
        $num = $this->landShareNumerator();
        $den = $this->landShareDenominator();
        $area = $this->block?->project?->land_area;
        if (! $num || ! $den || $area === null) {
            return null;
        }

        return round(((float) $area) * $num / $den, 4);
    }
}
