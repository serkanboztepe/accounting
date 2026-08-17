<?php

namespace App\Support;

class Money
{
    /**
     * Tüm formatlardan float'a çevirir:
     *   "233.999,99"  → 233999.99  (Türkçe masked, virgül ondalık)
     *   "600.000"     → 600000     (Türkçe binlik, mask henüz ondalık koymamış)
     *   "1.234.567"   → 1234567    (Türkçe binlik, çoklu nokta)
     *   "233999.99"   → 233999.99  (DB decimal:2 cast — sondan tam 2 hane)
     *   233999.99     → 233999.99  (PHP float / int)
     *   null / ""     → 0.0
     */
    public static function parse(mixed $value): float
    {
        if (blank($value)) {
            return 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $s = trim((string) $value);

        if ($s === '') {
            return 0.0;
        }

        // 1) Türkçe ondalık: virgülden sonra hane(ler) — "233.999,99", "600,5"
        if (preg_match('/,\d+$/', $s)) {
            return (float) str_replace(['.', ','], ['', '.'], $s);
        }

        // 2) DB cast (decimal:2): tek nokta, sondan tam 2 hane — "233999.99", "0.50"
        if (preg_match('/^-?\d+\.\d{2}$/', $s)) {
            return (float) $s;
        }

        // 3) Diğer hepsi Türkçe binlik (noktaları sil) — "600.000", "1.234.567", "600"
        return (float) str_replace('.', '', $s);
    }

    /**
     * Float'ı Türkçe para formatına çevirir: 233999.99 → "233.999,99"
     */
    public static function format(float $amount, int $decimals = 2): string
    {
        return number_format($amount, $decimals, ',', '.');
    }

    /**
     * DB'ye yazılacak normalize string üretir: "233999.99"
     */
    public static function store(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $amount = self::parse($value);

        return sprintf('%.2f', $amount);
    }
}
