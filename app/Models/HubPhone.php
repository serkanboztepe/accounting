<?php

namespace App\Models;

use App\Support\Phone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Hub: hangi telefon hangi firmaya yazıyor. */
class HubPhone extends Model
{
    protected $fillable = ['phone', 'hub_firm_id', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(fn (HubPhone $p) => $p->phone = Phone::normalize($p->phone));
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(HubFirm::class, 'hub_firm_id');
    }
}
