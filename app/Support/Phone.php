<?php

namespace App\Support;

class Phone
{
    /** "whatsapp:+90 545 ..." / "0545..." / "90545..." → "90545..." (sadece rakam, TR için 90 önekli). */
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return '90' . substr($digits, 1);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '5')) {
            return '90' . $digits;
        }

        return $digits;
    }

    /** WhatsApp kullanıcı kimliği (numarasını gizleyen, Twilio'dan "TR.1601…" gelir; normalize harfleri siler) — telefon 15 haneyi geçmez. */
    public static function isWhatsappId(string $phone): bool
    {
        return strlen(self::normalize($phone)) > 15;
    }

    /** Twilio "To" adresi: telefon → "whatsapp:+90…", gizli kimlik → "whatsapp:TR.…" (Oktay, 14 Ekim teslim edildi). */
    public static function whatsappAddress(string $phone): string
    {
        $d = self::normalize($phone);

        return self::isWhatsappId($phone) ? 'whatsapp:TR.' . $d : 'whatsapp:+' . $d;
    }

    /** Gösterim: "905321234567" → "+90 532 123 45 67"; diğer ülkeler "+" + rakamlar. */
    public static function display(string $phone): string
    {
        $d = self::normalize($phone);

        if (strlen($d) === 12 && str_starts_with($d, '90')) {
            return sprintf('+90 %s %s %s %s', substr($d, 2, 3), substr($d, 5, 3), substr($d, 8, 2), substr($d, 10, 2));
        }

        return '+' . $d;
    }
}
