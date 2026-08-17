<?php

namespace App\Services\LandShare;

/**
 * Hesap motoruna verilen salt-veri yapısı (DB'den bağımsız).
 *
 * Filament/Eloquent tarafı bu yapıyı doldurur; motor sadece bunu görür.
 * Böylece hesap mantığı veritabanı olmadan test edilebilir.
 */
final class StudyData
{
    /**
     * @param  array<int, array{key:string, name:string, current_pay:int, current_payda:int, is_contractor?:bool, group_key?:?string}>  $shareholders
     * @param  array<int, array{section:string, holder:string, pay:int, payda:int}>  $allocations
     * @param  array<string, array{arsa_pay:?int, arsa_payda:?int}>  $sections  BB anahtarı => arsa payı
     * @param  array<string, int>  $groups  grup anahtarı => daire hakkı (yalnız GRUP)
     */
    public function __construct(
        public array $shareholders = [],
        public array $allocations = [],
        public array $sections = [],
        public array $groups = [],
    ) {
    }

    /** Toplam bağımsız bölüm sayısı — dağılımlardaki tekil BB'lerden türetilir. */
    public function sectionCount(): int
    {
        if ($this->sections !== []) {
            return count($this->sections);
        }

        $keys = [];
        foreach ($this->allocations as $alloc) {
            $keys[$alloc['section']] = true;
        }

        return count($keys);
    }
}
