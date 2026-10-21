<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandShareGroup extends Model
{
    use Concerns\LogsActivityAsPart;

    /** İşlem geçmişi: alt satır — üst kayıt "düzenlendi" olarak yazılır. */
    public function activityParent(): ?\Illuminate\Database\Eloquent\Model
    {
        return LandShareStudy::find($this->study_id);
    }

    protected $fillable = [
        'study_id',
        'group_key',
        'unit_credits',
    ];

    protected $casts = [
        'unit_credits' => 'integer',
    ];

    public function study(): BelongsTo
    {
        return $this->belongsTo(LandShareStudy::class, 'study_id');
    }
}
