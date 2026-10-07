<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Hub: mesajların iletileceği firma kurulumu. */
class HubFirm extends Model
{
    protected $fillable = ['name', 'url', 'secret', 'is_active'];

    protected $hidden = ['secret'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(function (HubFirm $firm) {
            $firm->secret = $firm->secret ?: Str::random(48);
            $firm->url = rtrim($firm->url, '/');
        });
    }

    public function phones(): HasMany
    {
        return $this->hasMany(HubPhone::class);
    }

    public function webhookUrl(): string
    {
        return rtrim($this->url, '/') . '/whatsapp/webhook';
    }
}
