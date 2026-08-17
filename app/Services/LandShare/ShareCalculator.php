<?php

namespace App\Services\LandShare;

use App\Support\Fraction;
use InvalidArgumentException;

/**
 * Hisse dağıtımı hesap motoru — DB'den bağımsız, saf PHP.
 *
 * Girdi (StudyData) modellerden kurulan salt-veri yapısıdır; böylece motor
 * Filament/Eloquent olmadan test edilebilir. Referans Excel'in üç yöntemini
 * kapsar. Fark tek noktada: "bir bağımsız bölüm ne kadar hisse eder?"
 *
 *  - BLOK_DAIRE : her BB eşit ağırlık → kişi payı = Σ(sahiplik) / BB sayısı
 *  - ARSA_PAYLI : her BB kendi arsa payı kadar → kişi payı = Σ(sahiplik × arsa payı)
 *  - GRUP       : gruba toplu daire hakkı, grup içinde mevcut paya bölünür
 *
 * Her yöntemde çıktı aynıdır: kişi başına mevcut / satılan / kalan hisse.
 */
final class ShareCalculator
{
    public const BLOK_DAIRE = 'blok_daire';
    public const ARSA_PAYLI = 'arsa_payli';
    public const GRUP = 'grup';

    public function calculate(StudyData $data, string $method): ShareResult
    {
        $newShares = match ($method) {
            self::BLOK_DAIRE => $this->blokDaire($data),
            self::ARSA_PAYLI => $this->arsaPayli($data),
            self::GRUP => $this->grup($data),
            default => throw new InvalidArgumentException("Bilinmeyen yöntem: {$method}"),
        };

        $rows = [];
        foreach ($data->shareholders as $holder) {
            $current = Fraction::of($holder['current_pay'], $holder['current_payda']);
            $new = $newShares[$holder['key']] ?? Fraction::zero();
            $sold = $current->sub($new)->clampNonNegative();

            $rows[] = new ShareRow(
                key: $holder['key'],
                name: $holder['name'],
                isContractor: (bool) ($holder['is_contractor'] ?? false),
                current: $current,
                sold: $sold,
                remaining: $new,
                explanation: $this->explain($method, $data, $holder, $current, $new, $sold),
            );
        }

        return new ShareResult($method, $rows);
    }

    /**
     * BLOK/DAİRE — her BB eşit ağırlık.
     * Kişi payı = (o kişinin tüm BB'lerdeki sahiplik kesirleri toplamı) / toplam BB.
     */
    private function blokDaire(StudyData $data): array
    {
        $sectionCount = $data->sectionCount();
        $divisor = Fraction::of($sectionCount);

        $sumByHolder = $this->ownershipSumByHolder($data);

        $result = [];
        foreach ($sumByHolder as $key => $sum) {
            $result[$key] = $sectionCount > 0 ? $sum->div($divisor) : Fraction::zero();
        }

        return $result;
    }

    /**
     * ARSA PAYLI — her BB kendi arsa payı kadar ağırlık taşır.
     * Kişi payı = Σ ( BB'deki sahiplik kesri × BB'nin arsa payı ).
     * (Arsa paylarının toplamı 1/1 olduğundan ayrıca normalize gerekmez.)
     */
    private function arsaPayli(StudyData $data): array
    {
        $result = [];

        foreach ($data->allocations as $alloc) {
            $section = $data->sections[$alloc['section']] ?? null;

            if ($section === null || $section['arsa_pay'] === null || $section['arsa_payda'] === null) {
                throw new InvalidArgumentException(
                    "ARSA PAYLI yöntemi için '{$alloc['section']}' BB'sinin arsa payı girilmemiş.",
                );
            }

            $ownership = Fraction::of($alloc['pay'], $alloc['payda']);
            $arsaPay = Fraction::of($section['arsa_pay'], $section['arsa_payda']);
            $share = $ownership->mul($arsaPay);

            $key = $alloc['holder'];
            $result[$key] = ($result[$key] ?? Fraction::zero())->add($share);
        }

        return $result;
    }

    /**
     * GRUP — eski yöntem. Her grup sabit "daire hakkı" alır, grup içinde
     * üyelerin mevcut hisse oranına göre bölünür.
     * Kişi payı = grupHakkı × (kişiMevcut / grupMevcutToplam) / toplam BB.
     */
    private function grup(StudyData $data): array
    {
        $sectionCount = $data->sectionCount();
        $divisor = Fraction::of($sectionCount);

        // Grup içi mevcut hisse toplamları.
        $groupCurrentSum = [];
        foreach ($data->shareholders as $holder) {
            $gk = $holder['group_key'] ?? null;
            if ($gk === null) {
                continue;
            }
            $current = Fraction::of($holder['current_pay'], $holder['current_payda']);
            $groupCurrentSum[$gk] = ($groupCurrentSum[$gk] ?? Fraction::zero())->add($current);
        }

        $result = [];
        foreach ($data->shareholders as $holder) {
            $gk = $holder['group_key'] ?? null;
            if ($gk === null || ! isset($data->groups[$gk])) {
                $result[$holder['key']] = Fraction::zero();
                continue;
            }

            $credits = Fraction::of($data->groups[$gk]);      // grubun daire hakkı
            $current = Fraction::of($holder['current_pay'], $holder['current_payda']);
            $groupSum = $groupCurrentSum[$gk] ?? Fraction::zero();

            if ($groupSum->isZero() || $sectionCount === 0) {
                $result[$holder['key']] = Fraction::zero();
                continue;
            }

            // credits × (current / groupSum) / sectionCount
            $result[$holder['key']] = $credits
                ->mul($current->div($groupSum))
                ->div($divisor);
        }

        return $result;
    }

    /** Kişi bazında tüm BB'lerdeki sahiplik kesirlerinin toplamı. */
    private function ownershipSumByHolder(StudyData $data): array
    {
        $sum = [];
        foreach ($data->allocations as $alloc) {
            $key = $alloc['holder'];
            $ownership = Fraction::of($alloc['pay'], $alloc['payda']);
            $sum[$key] = ($sum[$key] ?? Fraction::zero())->add($ownership);
        }

        return $sum;
    }

    /** "Hesabı Açıkla" — sonucun nasıl oluştuğunu adım adım anlatan satırlar. */
    private function explain(
        string $method,
        StudyData $data,
        array $holder,
        Fraction $current,
        Fraction $new,
        Fraction $sold,
    ): array {
        $steps = [];
        $steps[] = "Mevcut hisse: {$current}";

        if ($method === self::BLOK_DAIRE) {
            $parts = [];
            $sum = Fraction::zero();
            foreach ($data->allocations as $alloc) {
                if ($alloc['holder'] !== $holder['key']) {
                    continue;
                }
                $own = Fraction::of($alloc['pay'], $alloc['payda']);
                $parts[] = "{$alloc['section']} ({$own})";
                $sum = $sum->add($own);
            }
            if ($parts) {
                $steps[] = 'Aldığı bağımsız bölümler: ' . implode(' + ', $parts) . " = {$sum} BB";
            } else {
                $steps[] = 'Hiç bağımsız bölüm almadı.';
            }
            $steps[] = "Yeni hisse = {$sum} ÷ {$data->sectionCount()} BB = {$new}";
        } elseif ($method === self::ARSA_PAYLI) {
            $parts = [];
            foreach ($data->allocations as $alloc) {
                if ($alloc['holder'] !== $holder['key']) {
                    continue;
                }
                $section = $data->sections[$alloc['section']] ?? null;
                $own = Fraction::of($alloc['pay'], $alloc['payda']);
                $arsa = Fraction::of($section['arsa_pay'], $section['arsa_payda']);
                $parts[] = "{$alloc['section']}: {$own} × arsa {$arsa}";
            }
            $steps[] = $parts
                ? 'Aldığı BB × arsa payı: ' . implode(' + ', $parts)
                : 'Hiç bağımsız bölüm almadı.';
            $steps[] = "Yeni hisse = {$new}";
        } elseif ($method === self::GRUP) {
            $gk = $holder['group_key'] ?? '—';
            $credits = $data->groups[$gk] ?? 0;
            $steps[] = "Grup: {$gk} · grup daire hakkı: {$credits}";
            $steps[] = "Mevcut paya göre bölünen yeni hisse = {$new}";
        }

        $steps[] = $sold->isZero()
            ? "Satılan hisse: 0 (yeni hisse mevcut hisseye eşit ya da fazla — devralıyor)"
            : "Satılan = mevcut − yeni = {$current} − {$new} = {$sold}";
        $steps[] = "Satış sonu kalan: {$new}";

        return $steps;
    }
}
