<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Emlak Vergisi Bildirimi — Daire (bağımsız bölüm) seviyesi.
 * Yalnız kendine özel alanlar; boş bırakılan alanları bloktan/projeden devralır.
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
        'usage_type',
        'construction_class',
        'share_ratio',
        'neighborhood',
        'street',
        'sort_order',
    ];

    protected $casts = [
        'floor_no'             => 'integer',
        'floor_position'       => 'integer',
        'area'                 => 'decimal:2',
        'land_share_numerator' => 'integer',
        'sort_order'           => 'integer',
    ];

    public function block(): BelongsTo
    {
        return $this->belongsTo(PropertyTaxBlock::class, 'property_tax_block_id');
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

    /** Arsa payı oranı metni, ör. "1/8". */
    public function landShareRatioText(): ?string
    {
        $den = $this->block?->land_share_denominator;
        if (! $den) {
            return null;
        }

        return $this->land_share_numerator.'/'.$den;
    }

    /** Arsa payına düşen metrekare = arsa alanı × (pay / payda). */
    public function landShareArea(): ?float
    {
        $den = $this->block?->land_share_denominator;
        $area = $this->block?->land_area;
        if (! $den || $area === null) {
            return null;
        }

        return round(((float) $area) * $this->land_share_numerator / $den, 4);
    }
}
