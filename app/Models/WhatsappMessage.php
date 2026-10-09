<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

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

    /** Bu telefon daha önce hiç yazmış mı? (ilk mesajda karşılama rehberi) */
    public static function isFirstContact(string $phone): bool
    {
        return ! static::where('phone', $phone)->where('direction', 'in')->exists();
    }
}
