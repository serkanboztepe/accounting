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
}
