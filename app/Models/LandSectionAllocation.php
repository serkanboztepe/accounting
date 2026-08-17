<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandSectionAllocation extends Model
{
    protected $fillable = [
        'section_id',
        'shareholder_id',
        'pay',
        'payda',
    ];

    protected $casts = [
        'pay'   => 'integer',
        'payda' => 'integer',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(LandSection::class, 'section_id');
    }

    public function shareholder(): BelongsTo
    {
        return $this->belongsTo(LandShareholder::class, 'shareholder_id');
    }
}
