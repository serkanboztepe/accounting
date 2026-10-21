<?php

namespace App\Services\Whatsapp;

use App\Support\Money;

/**
 * "İş yapıldı mı?" sorusu numaralı seçenekle (kullanıcı 20.10: "yazmak zor, 1 / 2 gibi olsun; mal alımında bağlantı, ustada
 * hakediş… varyantlarını sen bilirsin"). Seçim mevcut iş durumu mantığına (Understanding::apply) bağlanır — yeni kayıt türü yok.
 * Kendi cümlesiyle cevap da yine çalışır (AI okur); numara yalnız kısayol.
 *
 *  - Ödeme, malzemeci  : 1 Mal geldi · 2 Bağlantı / peşin (mal sonra) · 3 Eski borcu ödedim
 *  - Ödeme, usta       : 1 Yaptığı işin parası · 2 İşe başlamadan, peşin · 3 Eski borcu ödedim
 *  - Ödeme, belirsiz   : 1 İş/mal geldi · 2 Peşin (iş/mal sonra) · 3 Eski borcu ödedim
 *  - Alış              : 1 İş yapıldı / mal geldi · 2 Önceden ödüyorum (iş/mal sonra)
 *  - "Borcum var"      : 1 İş bitti, parasını vermedim · 2 Anlaştık, iş başlamadı · 3 Borç para aldım
 *  - Açık borcu olmayan cariye ödeme (eski "Ne için verdin?" menüsü): ödeme seçeneklerine + 4 Borç verdim (geri alacağım)
 */
final class WorkChoice
{
    /** Taslakta numaralı iş durumu sorusu var mı (cevabı numarayla gelebilir). */
    public static function shown(array $d): bool
    {
        return ! empty($d['work_choice']);
    }

    /** Sorulacak belirsizlik iş durumu mu (kişi sorusu / borç sorusu gibi özel sorular değil). */
    public static function applies(array $d): bool
    {
        $kind = $d['kind'] ?? null;
        if (! in_array($kind, [ExpenseExtractor::KIND_PAYMENT, ExpenseExtractor::KIND_PURCHASE, ExpenseExtractor::KIND_DEBT_NOTE], true)
            || ! Understanding::applies($d) || Understanding::partyUnclear($d) || ! empty($d['debt_asked'])) {
            return false;
        }
        if ($kind === ExpenseExtractor::KIND_DEBT_NOTE) {
            return ($d['work_status_known'] ?? null) === false
                || (! empty($d['work_status']) && ! in_array($d['work_status'], [Understanding::DONE, Understanding::AGREEMENT, Understanding::NO_WORK], true));
        }

        return ($d['work_status_known'] ?? null) === false;
    }

    /** @return list<string> seçenek etiketleri (1'den başlar) */
    public static function options(array $d): array
    {
        return match ($d['kind'] ?? null) {
            ExpenseExtractor::KIND_DEBT_NOTE => ['İş bitti, parasını vermedim', 'Anlaştık, iş başlamadı', 'Borç para aldım'],
            ExpenseExtractor::KIND_PURCHASE => ['İş yapıldı / mal geldi', 'Önceden ödüyorum (iş/mal sonra)'],
            default => [...match ($d['is_service'] ?? null) {
                true => ['Yaptığı işin parası', 'İşe başlamadan, peşin', 'Eski borcu ödedim'],
                false => ['Mal geldi', 'Bağlantı / peşin (mal sonra)', 'Eski borcu ödedim'],
                default => ['İş/mal geldi', 'Peşin (iş/mal sonra)', 'Eski borcu ödedim'],
            }, ...(! empty($d['work_loan_option']) ? ['Borç verdim (geri alacağım)'] : [])],
        };
    }

    public static function question(array $d): string
    {
        $name = DraftGaps::party($d)?->name ?? ($d['party_name'] ?? 'Bu kişi');
        $to = self::dative($name); // "Kuşak Beton'a", "Ali Usta'ya", "Kamuran Narçin'e"
        $amount = (float) ($d['amount'] ?? 0);
        $head = match ($d['kind'] ?? null) {
            ExpenseExtractor::KIND_DEBT_NOTE => $to . ($amount > 0 ? ' ' . Money::format($amount) . ' ₺' : '') . ' borcun — neden?',
            ExpenseExtractor::KIND_PURCHASE => $name . ($amount > 0 ? ' ' . Money::format($amount) . ' ₺' : '') . ' — iş/mal durumu?',
            default => $to . ($amount > 0 ? ' ' . Money::format($amount) . ' ₺' : ' ödeme') . ' — ne için?',
        };
        $opts = [];
        foreach (self::options($d) as $i => $label) {
            $opts[] = '*' . ($i + 1) . '* ' . $label;
        }

        return $head . "\n" . implode(' · ', $opts);
    }

    /** Özel ada yönelme eki, ses uyumuyla: Beton → Beton'a, Usta → Usta'ya, Narçin → Narçin'e, Ölmez → Ölmez'e. */
    public static function dative(string $name): string
    {
        $lower = mb_strtolower(strtr(trim($name), ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
        if ($lower === '' || ! preg_match('/([aeıioöuü])[^aeıioöuü]*$/u', $lower, $m)) {
            return $name . "'e";
        }
        $back = in_array($m[1], ['a', 'ı', 'o', 'u'], true);
        $endsVowel = (bool) preg_match('/[aeıioöuü]$/u', $lower);

        return $name . "'" . ($endsVowel ? 'y' : '') . ($back ? 'a' : 'e');
    }

    /** Özel ada bulunma eki, ses uyumu + sertleşme: Muhyiddin → Muhyiddin'de, Beton → Beton'da, Usta → Usta'da, Tokgöz → Tokgöz'de, Seramikpark → Seramikpark'ta. */
    public static function locative(string $name): string
    {
        $lower = mb_strtolower(strtr(trim($name), ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
        if ($lower === '' || ! preg_match('/([aeıioöuü])[^aeıioöuü]*$/u', $lower, $m)) {
            return $name . "'de";
        }
        $back = in_array($m[1], ['a', 'ı', 'o', 'u'], true);
        $hard = (bool) preg_match('/[fstkçşhp]$/u', $lower);

        return $name . "'" . ($hard ? 't' : 'd') . ($back ? 'a' : 'e');
    }

    /**
     * Kısa yazılı cevap seçeneğin kelimesine benziyorsa numarası (firma 7, 21.10: "Başlantı" → Bağlantı anlaşılmadı, soru tekrarlandı).
     * Yazım hatası payı 1 harf (7+ harfte 2); iki seçeneğe birden uyarsa ya da 3 kelimeden uzunsa null → AI okur.
     */
    public static function match(array $d, string $body): ?int
    {
        $fold = fn (string $v) => strtr(mb_strtolower(strtr($v, ['İ' => 'i', 'I' => 'ı']), 'UTF-8'), ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u']);
        $said = preg_split('/[^a-z]+/', $fold($body), -1, PREG_SPLIT_NO_EMPTY);
        if ($said === [] || count($said) > 3) {
            return null;
        }
        $generic = ['sonra', 'parasi', 'parasini', 'vermedim', 'geldi', 'yapildi', 'odedim', 'odeme'];
        $hits = [];
        foreach (self::options($d) as $i => $label) {
            foreach (preg_split('/[^a-z]+/', $fold($label), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                if (mb_strlen($word) < 4 || in_array($word, $generic, true)) {
                    continue;
                }
                foreach ($said as $w) {
                    if (mb_strlen($w) >= 4 && levenshtein($w, $word) <= (strlen($word) >= 7 ? 2 : 1)) {
                        $hits[$i + 1] = true;
                    }
                }
            }
        }

        return count($hits) === 1 ? array_key_first($hits) : null;
    }

    /** Numara → iş durumu (Understanding::apply kayda çevirir); geçersizse null. */
    public static function choose(array $d, int $n): ?array
    {
        if ($n < 1 || $n > count(self::options($d))) {
            return null;
        }
        $kind = $d['kind'] ?? null;
        $status = match (true) {
            $kind === ExpenseExtractor::KIND_DEBT_NOTE => [1 => Understanding::DONE, 2 => Understanding::AGREEMENT, 3 => Understanding::NO_WORK][$n],
            $kind === ExpenseExtractor::KIND_PURCHASE => [1 => Understanding::DONE, 2 => Understanding::NOT_YET][$n],
            default => [1 => Understanding::DONE, 2 => Understanding::NOT_YET, 3 => Understanding::OLD_DEBT, 4 => Understanding::NO_WORK][$n],
        };
        $extra = $kind === ExpenseExtractor::KIND_PURCHASE && $status === Understanding::NOT_YET ? ['paid' => true] : [];

        return array_merge($d, $extra, ['work_status' => $status, 'work_status_known' => true, 'work_choice' => null, 'question' => null,
            'is_new_entry' => false, 'work_chosen' => true]);
    }
}
