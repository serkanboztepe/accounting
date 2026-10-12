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
     * Tutarın tam kısmı yazıyla: 17017000 → "on yedi milyon on yedi bin". Büyük tutar teyidinde
     * ("17.017000 tl" gibi karışık yazım yanlış okunmasın) özette gösterilir.
     */
    public static function words(float $amount): string
    {
        $n = (int) floor(abs($amount));
        if ($n === 0) {
            return 'sıfır';
        }
        $ones = ['', 'bir', 'iki', 'üç', 'dört', 'beş', 'altı', 'yedi', 'sekiz', 'dokuz'];
        $tens = ['', 'on', 'yirmi', 'otuz', 'kırk', 'elli', 'altmış', 'yetmiş', 'seksen', 'doksan'];
        $three = function (int $x) use ($ones, $tens): string {
            $h = intdiv($x, 100);
            $parts = [$h === 0 ? '' : ($h === 1 ? 'yüz' : $ones[$h] . ' yüz'), $tens[intdiv($x % 100, 10)], $ones[$x % 10]];

            return implode(' ', array_filter($parts));
        };

        $words = [];
        foreach ([1_000_000_000 => 'milyar', 1_000_000 => 'milyon', 1_000 => 'bin', 1 => ''] as $unit => $name) {
            $group = intdiv($n, $unit);
            $n %= $unit;
            if ($group === 0) {
                continue;
            }
            // "bin" tek başına: "bir bin" denmez.
            $words[] = $unit === 1_000 && $group === 1 ? 'bin' : trim($three($group) . ' ' . $name);
        }

        return implode(' ', $words);
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
