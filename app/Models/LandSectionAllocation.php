<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandSectionAllocation extends Model
{
    use Concerns\LogsActivityAsPart;

    /** İşlem geçmişi: alt satır — üst kayıt "düzenlendi" olarak yazılır. */
    public function activityParent(): ?\Illuminate\Database\Eloquent\Model
    {
        return LandShareStudy::find(LandBlock::whereKey(LandSection::whereKey($this->section_id)->value('block_id'))->value('study_id'));
    }

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
