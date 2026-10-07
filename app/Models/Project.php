<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'party_id',
        'cadastral_parcel',
        'address',
        'status',
        'start_date',
        'end_date',
        'notes',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    /** Başlangıç tarihi sorulmaz — oluşturma günüdür. */
    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            $project->start_date ??= now()->toDateString();
        });
    }

    /** Projenin sahibi müşteri (opsiyonel) — "bu proje bu müşteriye ait". */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(Check::class);
    }

    public function contractDeliveries(): HasMany
    {
        return $this->hasMany(ContractDelivery::class);
    }

    public function landShareStudies(): HasMany
    {
        return $this->hasMany(LandShareStudy::class);
    }
}
