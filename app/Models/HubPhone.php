<?php

namespace App\Models;

use App\Support\Phone;
use App\Tenancy\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Hub: hangi telefon hangi firmaya yazıyor. */
class HubPhone extends Model
{
    use UsesCentralConnection;

    protected $fillable = ['phone', 'hub_firm_id', 'name', 'is_active', 'receives_reminders', 'welcomed_at',
        'nudge_enabled', 'nudge_sent_at', 'nudge_unanswered', 'receives_revenue_prompt'];

    protected $casts = ['is_active' => 'boolean', 'receives_reminders' => 'boolean', 'welcomed_at' => 'datetime',
        'nudge_enabled' => 'boolean', 'nudge_sent_at' => 'datetime', 'nudge_unanswered' => 'integer',
        'receives_revenue_prompt' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(fn (HubPhone $p) => $p->phone = Phone::normalize($p->phone));
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(HubFirm::class, 'hub_firm_id');
    }
}
