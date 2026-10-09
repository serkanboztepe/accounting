<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use App\Tenancy\Tenancy;
use Illuminate\Support\Carbon;

/**
 * WhatsApp konuşma kaydı — asistanın takıldığı yerleri görmek için (hub: "Anlaşılamayan mesajlar").
 * Firmanın kendi veritabanında; 90 gün sonra model:prune ile silinir.
 */
class WhatsappMessage extends Model
{
    use Prunable;

    public const RETENTION_DAYS = 90;

    /** Gelen mesajın "anlaşılamadı" sayılan türleri. */
    public const UNCLEAR_KINDS = ['help', 'error', 'no_draft'];

    protected $fillable = ['phone', 'direction', 'body', 'has_media', 'kind'];

    protected $casts = ['has_media' => 'boolean'];

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    /**
     * Karşılama yalnız bu tarihten SONRA hub'a eklenen numaralara (kullanıcı kararı: mevcut
     * müşteriler almasın, yeni kayıtlar alsın). İstanbul saati — sunucu APP_TIMEZONE da öyle.
     */
    public const WELCOME_SINCE = '2026-10-10 00:00:00';

    /**
     * İlk mesajda karşılama rehberi gönderilsin mi? Numara hub'a karşılama özelliğinden sonra
     * eklenmiş olmalı (eski kullanıcılar yeniden "hoş geldin" almasın) ve daha önce hiç yazmamış olmalı.
     */
    public static function shouldWelcome(string $phone): bool
    {
        $registeredAt = HubPhone::query()
            ->where('phone', $phone)
            ->when(Tenancy::current(), fn (Builder $q, $firm) => $q->where('hub_firm_id', $firm->id))
            ->value('created_at');

        if ($registeredAt === null || Carbon::parse($registeredAt)->lt(Carbon::parse(self::WELCOME_SINCE))) {
            return false;
        }

        return ! static::where('phone', $phone)->where('direction', 'in')->exists();
    }
}
