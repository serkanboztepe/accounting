<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Emlak Beyanı — Mükellef (paylı mülkiyette birden çok olabilir).
 * Kat Karşılığı'ndaki LandShareholder ile aynı rol.
 */
class PropertyTaxTaxpayer extends Model
{
    protected $fillable = [
        'property_tax_project_id',
        'surname',
        'first_name',
        'tax_id',
        'property_registry_no',
        'phone_area_code',
        'phone',
        'fax_area_code',
        'fax',
        'email',
        'filer_role',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(PropertyTaxProject::class, 'property_tax_project_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PropertyTaxUnitTaxpayer::class);
    }

    /** Bu mükellefe atanmış daireler (hisse pivotuyla). */
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(PropertyTaxUnit::class, 'property_tax_unit_taxpayer')
            ->withPivot(['pay', 'payda'])
            ->withTimestamps();
    }

    public function fullName(): string
    {
        return trim($this->surname.' '.$this->first_name);
    }
}
