<?php

namespace App\Support;

use App\Models\WhatsappMessage;
use Illuminate\Support\Carbon;

/**
 * Bizim başlattığımız soru mesajları (hatırlatma, yarım kayıt sorusu) aynı kişiye ARKA ARKAYA gitmez (K5+K6 —
 * Akkuş 15.10: 08:00'de çek + kira birlikte gitti, "Ödendi" hangisi belli değildi; 12:35 ve 12:40'ta iki yarım kayıt
 * sorusu). Son sorumuz cevaplanınca (kişi bir şey yazınca) ya da en geç 1 saat sonra sıradaki gönderilir.
 * Saati belli alarmlar ("15:00'te hatırlat") bekletilmez.
 */
class WhatsappQueue
{
    public const WAIT_MINUTES = 60;

    private const PROMPT_KINDS = ['reminder', 'draft_followup'];

    public static function busy(string $phone): bool
    {
        $phone = Phone::normalize($phone);
        $lastPrompt = WhatsappMessage::where('phone', $phone)->where('direction', 'out')
            ->whereIn('kind', self::PROMPT_KINDS)
            ->where('created_at', '>=', now()->subMinutes(self::WAIT_MINUTES))
            ->max('created_at');
        if (! $lastPrompt) {
            return false;
        }

        return ! WhatsappMessage::where('phone', $phone)->where('direction', 'in')
            ->where('created_at', '>', Carbon::parse($lastPrompt))->exists();
    }
}
