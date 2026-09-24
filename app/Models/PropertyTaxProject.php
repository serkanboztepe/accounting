<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Emlak Vergisi Bildirimi — Proje seviyesi (ortak/mükellef bilgisi).
 * Tüm bloklar bu bilgileri paylaşır.
 */
class PropertyTaxProject extends Model
{
    protected $fillable = [
        'name',
        'project_id',
        'taxpayer_surname',
        'taxpayer_first_name',
        'tax_id',
        'property_registry_no',
        'phone_area_code',
        'phone',
        'fax_area_code',
        'fax',
        'email',
        'owner_address_street',
        'owner_address_lane',
        'owner_address_door_no',
        'owner_address_apartment_no',
        'owner_address_district',
        'owner_address_city',
        'postal_code',
        'city',
        'district',
        'municipality',
        'neighborhood',
        'street',
        'cadastral_parcel',
        'declaration_year',
        'filing_reason',
        'filer_role',
        'declaration_date',
    ];

    protected $casts = [
        'declaration_date' => 'date',
    ];

    public function blocks(): HasMany
    {
        return $this->hasMany(PropertyTaxBlock::class);
    }

    public function taxpayers(): HasMany
    {
        return $this->hasMany(PropertyTaxTaxpayer::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Opsiyonel ERP projesi bağı (sözleşme/teslimat projeleri). */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
