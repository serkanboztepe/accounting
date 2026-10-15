<?php

namespace App\Services\Whatsapp;

use App\Models\Party;

/**
 * AI kişiyi mevcut bir cariyle eşleştirdi ama kullanıcının yazdığı ad belirgin farklı mı?
 * ("Hüseyin BİM'in bacısı Ayten" → "Nusaybin bayisi Ayten" — Erkan, 13 Ekim: sessizce eşleşti.)
 * Kısa ad / unvan ("Ali", "Ali usta" → "Ali Arıcı") sorulmaz; carinin adında olmayan bir kelime varsa sorulur.
 * AI'sız, kod kontrolü.
 */
final class PartyNameCheck
{
    /** Ayırt etmeyen sözler: unvan, hitap, şirket eki. */
    private const IGNORE = ['usta', 'ustasi', 'bey', 'beyi', 'hanim', 'hanimi', 'abi', 'abisi', 'abla', 'ablasi', 'amca',
        'dayi', 'efendi', 've', 'ile', 'firma', 'firmasi', 'ltd', 'sti', 'as', 'san', 'tic', 'insaat'];

    public static function differs(Party $party, ?string $said): bool
    {
        $words = self::words((string) $said);
        if ($words === []) {
            return false;
        }
        $known = self::words($party->name);
        if ($known === []) {
            return false;
        }
        foreach ($words as $w) {
            if (! self::matchesAny($w, $known)) {
                return true; // carinin adında olmayan bir söz ("bacısı", "Hüseyin", "oğlu")
            }
        }

        return false;
    }

    /** Carinin adındaki her kelime yazılan adda var mı ("Hazal Doğan" ⊆ "Evrim Hazal Doğan") — resmî tam ad, kısa ad. */
    public static function containedIn(Party $party, string $said): bool
    {
        $known = self::words($party->name);
        $words = self::words($said);
        if ($known === [] || $words === []) {
            return false;
        }
        foreach ($known as $k) {
            if (! self::matchesAny($k, $words)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> küçük harf, Türkçe harfler sadeleşmiş, ek ("Ayten'in") ve ayırt etmeyen sözler atılmış */
    public static function words(string $text): array
    {
        $text = str_replace(['I', 'İ'], ['ı', 'i'], $text);
        $text = mb_strtolower($text);
        $text = preg_replace("/['’`´][\\p{L}]*/u", '', $text); // Ayten'in → ayten
        $text = strtr($text, ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u']);
        $words = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($words, fn ($w) => mb_strlen($w) >= 2 && ! in_array($w, self::IGNORE, true)));
    }

    /** @param  list<string>  $known */
    private static function matchesAny(string $w, array $known): bool
    {
        foreach ($known as $k) {
            if ($w === $k) {
                return true;
            }
            // Ek: bayi ↔ bayisi, ali ↔ aliye
            $short = strlen($w) < strlen($k) ? $w : $k;
            $long = $short === $w ? $k : $w;
            if (strlen($short) >= 3 && str_starts_with($long, $short) && strlen($long) - strlen($short) <= 3) {
                return true;
            }
            if (strlen($w) >= 4 && strlen($k) >= 4 && levenshtein($w, $k) <= 1) {
                return true; // yazım hatası
            }
        }

        return false;
    }
}
