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

    /** Usta unvanları: "Sinan usta" — kişi işiyle anılıyor; soyadlı cariyle yalnız adı tutuyorsa aynı kişi olmayabilir. */
    private const TRADE_TITLES = ['usta', 'ustasi', 'ustaya', 'ustadan', 'ustanin', 'kalfa', 'kalfaya'];

    /**
     * "Sinan usta" ↔ cari "Sinan Sele": yazılan yalnız ad + usta unvanı, cari soyadlı ve yalnız ilk ad tutuyor → aynı kişi
     * olmayabilir (Taner 16.10: sıvacı Sinan, bağlantı yapılan Sinan Sele'ye sessizce yazıldı). Bir kez sorulur, sonra takma ad.
     */
    public static function titledFirstNameOnly(Party $party, ?string $said): bool
    {
        $raw = preg_split('/[^a-z0-9]+/', strtr(mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], (string) $said)),
            ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (! array_intersect($raw, self::TRADE_TITLES)) {
            return false;
        }
        $words = self::words((string) $said);
        $known = self::words($party->name);

        return count($words) === 1 && count($known) >= 2 && self::matchesAny($words[0], [$known[0]]);
    }

    /**
     * Yazılan adla carinin adında ortak (benzer) en az bir kelime var mı. Yoksa öneri alakasız: "Ali" ↔ "Ayhan Ergül"
     * (25.10 kullanım incelemesi — "listedeki Ayhan Ergül mü?" diye sorulmuştu).
     */
    public static function sharesAnyWord(Party $party, ?string $said): bool
    {
        $words = self::words((string) $said);
        $known = self::words($party->name);
        if ($words === [] || $known === []) {
            return true; // karşılaştırılamıyor → alakasız sayma
        }
        foreach ($words as $w) {
            if (self::matchesAny($w, $known)) {
                return true;
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
