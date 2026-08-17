<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LandSection extends Model
{
    protected $fillable = [
        'block_id',
        'bb_no',
        'type',
        'floor',
        'arsa_pay',
        'arsa_payda',
        'sort',
    ];

    protected $casts = [
        'arsa_pay'   => 'integer',
        'arsa_payda' => 'integer',
        'sort'       => 'integer',
    ];

    public function block(): BelongsTo
    {
        return $this->belongsTo(LandBlock::class, 'block_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LandSectionAllocation::class, 'section_id');
    }
}
