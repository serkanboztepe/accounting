<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LandBlock extends Model
{
    protected $fillable = [
        'study_id',
        'name',
        'planned_unit_count',
        'sort',
    ];

    protected $casts = [
        'planned_unit_count' => 'integer',
        'sort'               => 'integer',
    ];

    public function study(): BelongsTo
    {
        return $this->belongsTo(LandShareStudy::class, 'study_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(LandSection::class, 'block_id');
    }
}
