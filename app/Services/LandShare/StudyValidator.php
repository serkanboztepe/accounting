<?php

namespace App\Services\LandShare;

use App\Support\Fraction;

/**
 * Hesap yapılmadan önceki kontroller — accordion başlıklarındaki ✓/⚠ rozetleri
 * ve cetvel sayfasındaki "hangi yöntemler mümkün" mantığı buradan beslenir.
 */
final class StudyValidator
{
    /** Mevcut hisselerin toplamı 1/1 mi? */
    public function currentSharesComplete(StudyData $data): bool
    {
        $sum = Fraction::zero();
        foreach ($data->shareholders as $holder) {
            $sum = $sum->add(Fraction::of($holder['current_pay'], $holder['current_payda']));
        }

        return $sum->equals(Fraction::of(1));
    }

    /** Her bağımsız bölümün kendi dağılımı 1/1 mi? */
    public function everySectionComplete(StudyData $data): bool
    {
        foreach ($this->sumBySection($data) as $sum) {
            if (! $sum->equals(Fraction::of(1))) {
                return false;
            }
        }

        return true;
    }

    /** Tanımlı tüm BB'ler dağıtılmış mı? (arsa payı listesi varsa ona göre) */
    public function allSectionsAllocated(StudyData $data): bool
    {
        if ($data->sections === []) {
            // BB listesi ayrıca verilmemiş; dağılımdaki her BB zaten dağıtılmış sayılır.
            return $data->allocations !== [];
        }

        $allocated = $this->sumBySection($data);
        foreach (array_keys($data->sections) as $sectionKey) {
            if (! isset($allocated[$sectionKey])) {
                return false;
            }
        }

        return true;
    }

    /** ARSA PAYLI için: tüm BB'lerin arsa payı girilmiş ve toplam 1/1 mi? */
    public function arsaSharesComplete(StudyData $data): bool
    {
        if ($data->sections === []) {
            return false;
        }

        $sum = Fraction::zero();
        foreach ($data->sections as $section) {
            if ($section['arsa_pay'] === null || $section['arsa_payda'] === null) {
                return false;
            }
            $sum = $sum->add(Fraction::of($section['arsa_pay'], $section['arsa_payda']));
        }

        return $sum->equals(Fraction::of(1));
    }

    /**
     * Arsa payı girişinin durumu — ekranlarda "arsa payına göre / eşit"
     * ibaresi ve eksik uyarısı buradan beslenir.
     *
     * @return array{state: 'none'|'complete'|'incomplete', sum: Fraction, missing: int, denominator: ?int}
     *   none: hiç girilmemiş · complete: hepsi girilmiş ve toplam 1/1 · incomplete: diğer
     *   denominator: girilen paydaların EKOK'u — toplamı kullanıcının paydasıyla
     *   göstermek için (2/9 değil 4/18)
     */
    public function arsaSharesState(StudyData $data): array
    {
        $sum = Fraction::zero();
        $entered = 0;
        $missing = 0;
        $auto = 0;
        $denominator = null;

        foreach ($data->sections as $section) {
            if ($section['arsa_pay'] === null || $section['arsa_payda'] === null) {
                $missing++;

                continue;
            }
            $sum = $sum->add(Fraction::of($section['arsa_pay'], $section['arsa_payda']));
            $denominator = Fraction::lcm($denominator ?? 1, $section['arsa_payda']);
            $entered++;
            $auto += ! empty($section['auto']) ? 1 : 0;
        }

        $state = match (true) {
            $entered === 0                                      => 'none',
            $missing === 0 && $sum->equals(Fraction::of(1))     => 'complete',
            default                                             => 'incomplete',
        };

        return ['state' => $state, 'sum' => $sum, 'missing' => $missing, 'denominator' => $denominator, 'auto' => $auto];
    }

    /**
     * Boş arsa paylarını hesap için tamamlar (veritabanına YAZILMAZ — kullanıcı
     * kararı 11.11: "girilmemişse sistem kendisi saysın"):
     *  - hiç girilmemişse her BB eşit (N BB → her biri 1/N),
     *  - bir kısmı girilmişse 1/1'e kalan, boş BB'lere eşit bölünür.
     * Girilenler 1/1'i zaten dolduruyor/aşıyorsa dokunulmaz (uyarı + eşit hesap sürer).
     * Payda mümkünse kullanıcınınki (1/18 → 1/18, 9 BB + payda 9 → 1/9).
     *
     * @param  array<string|int, array{arsa_pay:?int, arsa_payda:?int}>  $sections
     * @return array{0: array, 1: list<string|int>, 2: ?string}  [sections (doldurulanlarda 'auto' => true), doldurulan anahtarlar, BB başına pay "1/18"]
     */
    public static function fillMissingArsaShares(array $sections, ?int $denominator = null): array
    {
        $sum = Fraction::zero();
        $den = $denominator ?: 1;
        $hasEntries = false;
        $empty = [];

        foreach ($sections as $key => $section) {
            if (($section['arsa_pay'] ?? null) === null || empty($section['arsa_payda'])) {
                $empty[] = $key;

                continue;
            }
            $sum = $sum->add(Fraction::of((int) $section['arsa_pay'], (int) $section['arsa_payda']));
            $den = Fraction::lcm($den, (int) $section['arsa_payda']);
            $hasEntries = true;
        }

        $remaining = Fraction::of(1)->sub($sum);
        if ($empty === [] || $remaining->isZero() || $remaining->isNegative()) {
            return [$sections, [], null];
        }

        $each = $remaining->div(Fraction::of(count($empty)));
        // Hiç giriş yoksa ve payda BB sayısına bölünmüyorsa (1000 / 3) sade yaz: 1/3.
        $den = ! $hasEntries && $den % $each->den !== 0 ? $each->den : Fraction::lcm($den, $each->den);

        foreach ($empty as $key) {
            $sections[$key]['arsa_pay'] = $each->numeratorOver($den);
            $sections[$key]['arsa_payda'] = $den;
            $sections[$key]['auto'] = true;
        }

        return [$sections, $empty, $each->numeratorOver($den) . '/' . $den];
    }

    /** Cetvelde seçilebilecek yöntemler — "akıllı" varsayılan için. */
    public function availableMethods(StudyData $data): array
    {
        $base = $this->currentSharesComplete($data)
            && $this->everySectionComplete($data)
            && $this->allSectionsAllocated($data);

        $methods = [];

        if ($base) {
            $methods[] = ShareCalculator::BLOK_DAIRE;

            if ($this->arsaSharesComplete($data)) {
                $methods[] = ShareCalculator::ARSA_PAYLI;
            }
        }

        if ($data->groups !== [] && $this->currentSharesComplete($data)) {
            $methods[] = ShareCalculator::GRUP;
        }

        return $methods;
    }

    /**
     * Akıllı varsayılan yöntem: arsa payları tam girilmişse ARSA PAYLI,
     * değilse BLOK/DAİRE. Kullanıcı baştan seçim yapmak zorunda kalmaz.
     */
    public function defaultMethod(StudyData $data): ?string
    {
        $available = $this->availableMethods($data);

        if (in_array(ShareCalculator::ARSA_PAYLI, $available, true)) {
            return ShareCalculator::ARSA_PAYLI;
        }

        if (in_array(ShareCalculator::BLOK_DAIRE, $available, true)) {
            return ShareCalculator::BLOK_DAIRE;
        }

        return $available[0] ?? null;
    }

    /** @return array<string, Fraction> BB anahtarı => dağılım toplamı */
    private function sumBySection(StudyData $data): array
    {
        $sum = [];
        foreach ($data->allocations as $alloc) {
            $key = $alloc['section'];
            $sum[$key] = ($sum[$key] ?? Fraction::zero())->add(Fraction::of($alloc['pay'], $alloc['payda']));
        }

        return $sum;
    }
}
