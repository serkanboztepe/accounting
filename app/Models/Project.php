<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use Concerns\LogsActivity;

    public function activityAction(): string
    {
        return \App\Support\Labels::project();
    }

    public function activitySummary(): ?string
    {
        return $this->name;
    }

    public function activityDetails(): array
    {
        return \App\Models\ActivityLog::compact([
            'Ad'         => $this->name,
            'Cari'       => \App\Models\ActivityLog::name(Party::class, $this->party_id),
            'Ada/parsel' => $this->cadastral_parcel,
            'Adres'      => $this->address,
            'Durum'      => ['draft' => 'Taslak', 'active' => 'Aktif', 'completed' => 'Tamamlandı', 'cancelled' => 'İptal'][$this->status] ?? $this->status,
            'Başlangıç'  => \App\Models\ActivityLog::date($this->start_date),
            'Bitiş'      => \App\Models\ActivityLog::date($this->end_date),
            'Not'        => $this->notes,
        ]);
    }

    /** Proje silinince sözleşmeleri, teslimatları, hisse dağılımı veritabanında kendiliğinden silinir — önce yaz. */
    public function activityBeforeDelete(): void
    {
        $note = \App\Support\Labels::project() . ' silindiği için';
        foreach (Contract::withoutGlobalScopes()->where('project_id', $this->id)->get() as $contract) {
            $contract->activityBeforeDelete($note);
            \App\Models\ActivityLog::record($contract, 'deleted', note: $note);
        }
        foreach (ContractDelivery::where('project_id', $this->id)->get() as $delivery) {
            \App\Models\ActivityLog::record($delivery, 'deleted', note: $note);
        }
        foreach (LandShareStudy::where('project_id', $this->id)->get() as $study) {
            \App\Models\ActivityLog::record($study, 'deleted', note: $note);
        }
    }

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
