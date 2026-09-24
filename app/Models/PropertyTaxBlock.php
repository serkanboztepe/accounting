<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Emlak Vergisi Bildirimi — Blok seviyesi (bina ortak özellikleri).
 * Unit'ler bu alanları devralır (null bırakırlarsa).
 */
class PropertyTaxBlock extends Model
{
    protected $fillable = [
        'property_tax_project_id',
        'name',
        'land_area',
        'land_share_denominator',
        'building_door_no',
        'construction_type',
        'construction_class',
        'usage_type',
        'construction_completion_date',
        'acquisition_date',
        'restriction_status',
        'exemption_status',
        'reduced_tax',
        'share_ratio',
        'has_heating',
        'has_elevator',
    ];

    protected $casts = [
        'land_area'                    => 'decimal:2',
        'land_share_denominator'       => 'integer',
        'construction_completion_date' => 'date',
        'acquisition_date'             => 'date',
        'has_heating'                  => 'boolean',
        'has_elevator'                 => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(PropertyTaxProject::class, 'property_tax_project_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(PropertyTaxUnit::class)->orderBy('sort_order')->orderBy('id');
    }
}
