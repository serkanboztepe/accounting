<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\HubSignature;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

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

    public function messageLogs(): HasMany
    {
        return $this->hasMany(HubMessageLog::class);
    }

    /** Firma kurulumundan ay özeti (AI + şablon maliyeti) — imzalı istek; ulaşılamazsa null. */
    public function fetchUsage(string $month): ?array
    {
        $params = ['month' => $month];

        try {
            $response = Http::timeout(8)
                ->withHeaders(HubSignature::headers($params, $this->secret))
                ->get(rtrim($this->url, '/') . '/hub/usage', $params);

            return $response->successful() ? $response->json() : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function webhookUrl(): string
    {
        return rtrim($this->url, '/') . '/whatsapp/webhook';
    }
}
