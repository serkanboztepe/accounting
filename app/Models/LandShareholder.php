<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LandShareholder extends Model
{
    protected $fillable = [
        'study_id',
        'party_id',
        'name',
        'current_pay',
        'current_payda',
        'is_contractor',
        'group_key',
        'sort',
    ];

    protected $casts = [
        'current_pay'   => 'integer',
        'current_payda' => 'integer',
        'is_contractor' => 'boolean',
        'sort'          => 'integer',
    ];

    public function study(): BelongsTo
    {
        return $this->belongsTo(LandShareStudy::class, 'study_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LandSectionAllocation::class, 'shareholder_id');
    }
}
