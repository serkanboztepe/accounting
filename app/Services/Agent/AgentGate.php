<?php

namespace App\Services\Agent;

use App\Support\Features;

/**
 * Ajan asistanın kapısı (plan v5 §6, §8): bu firmada bu tur ajanla mı yürür?
 *
 *   off    → eski yol, birebir bugünkü gibi (varsayılan — hiçbir firmada kendiliğinden açılmaz)
 *   shadow → eski yol cevaplar; cevap gittikten sonra ajan aynı mesajı işler, yazmaz/göndermez
 *   live   → turu ajan yürütür
 *
 * Kod Features::agentLive()'a doğrudan bakmaz, buraya sorar: acil kapatma (WHATSAPP_AGENT=off) ve ileride
 * günlük maliyet sınırı / devre kesici tek yerden uygulanır.
 */
final class AgentGate
{
    public const OFF = 'off';

    public const SHADOW = 'shadow';

    public const LIVE = 'live';

    public static function mode(): string
    {
        if (self::killed() || ! Features::smartAssistant()) {
            return self::OFF;
        }
        if (Features::agentLive()) {
            return self::LIVE;
        }

        return Features::agentShadow() ? self::SHADOW : self::OFF;
    }

    public static function live(): bool
    {
        return self::mode() === self::LIVE;
    }

    public static function shadow(): bool
    {
        return self::mode() === self::SHADOW;
    }

    /** Acil kapatma: sunucuda WHATSAPP_AGENT=off → hub kutuları ne olursa olsun ajan yok. */
    public static function killed(): bool
    {
        return mb_strtolower(trim((string) config('services.whatsapp.agent', 'on'))) === 'off';
    }
}
