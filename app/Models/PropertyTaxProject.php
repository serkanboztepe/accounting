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
    use Concerns\LogsActivity;
    use Concerns\RequiresFeature;

    public static function featureEnabled(): bool
    {
        return \App\Support\Features::propertyTax();
    }

    public static function featureLabel(): string
    {
        return 'Emlak Beyanı';
    }

    public function activityAction(): string
    {
        return 'Emlak beyanı';
    }

    public function activitySummary(): ?string
    {
        return $this->name;
    }

    public function activityDetails(): array
    {
        return \App\Models\ActivityLog::compact([
            'Ad'             => $this->name,
            'Yapı sahibi'    => $this->building_owner,
            \App\Support\Labels::project() => \App\Models\ActivityLog::name(\App\Models\Project::class, $this->project_id),
            'Blok sayısı'    => $this->id ? PropertyTaxBlock::where('property_tax_project_id', $this->id)->count() : null,
            'Mükellef sayısı' => $this->id ? PropertyTaxTaxpayer::where('property_tax_project_id', $this->id)->count() : null,
        ]);
    }

    protected $fillable = [
        'name',
        'building_owner',
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
        'land_area',
        'declaration_year',
        'filing_reason',
        'filer_role',
        'declaration_date',
    ];

    protected $casts = [
        'declaration_date' => 'date',
        'land_area'        => 'decimal:2',
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
