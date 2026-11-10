<?php

namespace App\Services\Agent\Ops;

/**
 * Müşterinin HAM mesajındaki para rakamları (onayın ikinci anahtarı, plan v5 §1): "evet" derken kartta olmayan bir rakam yazdıysa
 * ("evet ama 742 -120 olacak") kayıt yapılmaz — ajana "kullanıcı yeni rakam verdi" denir. Tarih ("8.11") ve saat rakam sayılmaz.
 */
final class Numbers
{
    /** @return list<float> */
    public static function in(string $text): array
    {
        $lower = mb_strtolower(strtr($text, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
        preg_match_all('/(?<![\p{L}\d.,:\/])(\d[\d.,]*)(\s*(bin|milyon|mn|m)(?![\p{L}]))?/u', $lower, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($all as $m) {
            $raw = rtrim($m[1][0], '.,');
            $after = substr($lower, $m[1][1] + strlen($m[1][0]), 1);
            if ($after === ':' || $after === '/') {
                continue; // saat, tarih
            }
            if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $raw)) {
                $value = (float) str_replace(',', '.', str_replace('.', '', $raw)); // 120.000 / 1.250.000,50
            } elseif (preg_match('/^\d+(,\d+)?$/', $raw)) {
                $value = (float) str_replace(',', '.', $raw); // 120000 / 12,5
            } elseif (isset($m[3]) && preg_match('/^\d+\.\d+$/', $raw)) {
                $value = (float) $raw; // "1.5 milyon"
            } else {
                continue; // 8.11, 08.11.2026 — tarih
            }
            $unit = $m[3][0] ?? null;
            $value *= match ($unit) {
                'bin' => 1000, 'milyon', 'mn', 'm' => 1000000, default => 1,
            };
            $out[] = round($value, 2);
        }

        return $out;
    }

    /** Mesajdaki, bilinen rakamların hiçbiriyle tutmayan ilk para rakamı (100'den küçükler sayılmaz: "2 tane", "1. seçenek"). */
    public static function unknown(string $text, array $known): ?float
    {
        foreach (self::in($text) as $n) {
            if ($n < 100) {
                continue;
            }
            foreach ($known as $k) {
                // "742" kartta 742.000 ise aynı rakamın kısa söylenişi sayılır.
                if (abs($n - (float) $k) < 0.01 || abs($n * 1000 - (float) $k) < 0.01) {
                    continue 2;
                }
            }

            return $n;
        }

        return null;
    }

    public static function contains(string $text, float $amount): bool
    {
        foreach (self::in($text) as $n) {
            if (abs($n - $amount) < 0.01) {
                return true;
            }
        }

        return false;
    }
}
