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
