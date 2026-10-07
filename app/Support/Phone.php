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
