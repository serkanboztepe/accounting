<?php

namespace Tests\Feature\Eval;

/**
 * Yeniden oynatma puanlaması (saf fonksiyonlar; AI'sız test edilir — ReplayScoreTest). Üç ayrı sonuç:
 *   expected   beklenen kayıt doğru oluştu mu (hedef carinin yeni hareketleri + bakiye)
 *   unexpected beklenmeyen ya da çift kayıt (eşleşmeyen hareket, başka cariye hareket, beklenmeyen gider/satış belgesi)
 *   questions  gereksiz soru ya da soru döngüsü (vakada izin verilen soru kalıpları dışında kalan sorular; aynı soru 2+ kez)
 */
final class ReplayScore
{
    /** Türkçe büyük/küçük harf: İ→i, I→ı, sonra mb küçük (PostgreSQL LOWER yerel ayara göre Ş/İ'yi çevirmiyordu). */
    public static function fold(string $s): string
    {
        return mb_strtolower(strtr(trim($s), ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
    }

    /**
     * @param  array<int,string>  $names  id → ad
     * @return int|null aranan parçayı içeren en yeni cari
     */
    public static function findParty(string $like, array $names): ?int
    {
        $want = self::fold($like);
        $hit = null;
        foreach ($names as $id => $name) {
            if (str_contains(self::fold($name), $want)) {
                $hit = max($hit ?? 0, (int) $id);
            }
        }

        return $hit;
    }

    /**
     * Beklenen hareketler (has) yeni satırlardan BİRER kez düşülür; kalanlar allow'da değilse beklenmeyen sayılır.
     *
     * @param  list<array{0:string,1:float}>  $rows  [tür, tutar]
     * @return array{missing:list<string>, unexpected:list<string>, forbidden:list<string>}
     */
    public static function matchRows(array $rows, array $want): array
    {
        $left = $rows;
        $missing = [];
        foreach ($want['has'] ?? [] as [$type, $amount]) {
            $i = self::take($left, $type, (float) $amount);
            if ($i === null) {
                $missing[] = "{$type} " . $amount;
            }
        }
        foreach ($want['allow'] ?? [] as [$type, $amount]) {
            self::take($left, $type, (float) $amount);
        }
        $forbidden = [];
        foreach ($rows as [$type]) {
            if (in_array($type, $want['not'] ?? [], true)) {
                $forbidden[] = $type;
            }
        }

        return ['missing' => $missing, 'unexpected' => array_map(fn ($r) => $r[0] . ' ' . $r[1], array_values($left)),
            'forbidden' => array_values(array_unique($forbidden))];
    }

    private static function take(array &$left, string $type, float $amount): ?int
    {
        foreach ($left as $i => [$t, $a]) {
            if ($t === $type && abs((float) $a - $amount) < 0.01) {
                unset($left[$i]);

                return $i;
            }
        }

        return null;
    }

    /**
     * Sorular: onay satırları dışındaki her soru. İzinli kalıba uymayan → gereksiz; aynı soru (rakamlar hariç) 2+ kez → döngü.
     *
     * @param  list<array{in:string,out:string}>  $transcript
     * @param  list<string>  $allowed  regex (büyük/küçük harf duyarsız)
     * @return array{asked:list<array>, unnecessary:list<array>, loops:list<string>}
     */
    public static function questions(array $transcript, array $allowed): array
    {
        $asked = [];
        $seen = [];
        $loops = [];
        foreach ($transcript as $i => $t) {
            $plain = preg_replace('/[^\n]*(Kaydedeyim mi\?|Onaylamak için|Sileyim mi\?|Düzelteyim mi\?|Kaydetmem için)[^\n]*/u', '', $t['out']);
            if (! str_contains($plain, '❓') && ! str_contains($plain, '?')) {
                continue;
            }
            $q = self::questionText($plain);
            // İzin: soru cümlesi ya da aynı mesajın geri kalanı (ör. "satış zaten kapandı … başka bir şeyden mi aldın?").
            $ok = collect($allowed)->contains(fn ($re) => preg_match('/' . $re . '/iu', $q) || preg_match('/' . $re . '/iu', $plain));
            $asked[] = ['turn' => $i + 1, 'text' => $q, 'allowed' => $ok];
            $key = self::fold(preg_replace('/[\d.,]+/u', '#', $q));
            if (isset($seen[$key])) {
                $loops[] = $q;
            }
            $seen[$key] = true;
        }

        return ['asked' => $asked, 'unnecessary' => array_values(array_filter($asked, fn ($a) => ! $a['allowed'])),
            'loops' => array_values(array_unique($loops))];
    }

    /** Sorunun kendisi: ❓ satırı (seçenekleriyle) ya da soru işaretli son cümle. */
    public static function questionText(string $plain): string
    {
        if (preg_match('/❓\s*([^\n]+(?:\n\*\d\*[^\n]*)*)/u', $plain, $m)) {
            return trim(preg_replace('/\s+/u', ' ', $m[1]));
        }
        $parts = preg_split('/(?<=[.!?])\s+/u', trim(preg_replace('/\s+/u', ' ', $plain)));
        foreach (array_reverse($parts) as $p) {
            if (str_contains($p, '?')) {
                return $p;
            }
        }

        return mb_substr(trim($plain), 0, 200);
    }
}
