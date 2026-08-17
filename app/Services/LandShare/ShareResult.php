<?php

namespace App\Services\LandShare;

use App\Support\Fraction;

/** Bir yönteme göre hesaplanmış tam tapu çıktısı. */
final class ShareResult
{
    /** @param array<int, ShareRow> $rows */
    public function __construct(
        public readonly string $method,
        public readonly array $rows,
    ) {
    }

    public function totalSold(): Fraction
    {
        return array_reduce(
            $this->rows,
            fn (Fraction $acc, ShareRow $r) => $acc->add($r->sold),
            Fraction::zero(),
        );
    }

    public function totalRemaining(): Fraction
    {
        return array_reduce(
            $this->rows,
            fn (Fraction $acc, ShareRow $r) => $acc->add($r->remaining),
            Fraction::zero(),
        );
    }

    /** Kalan hisseler toplamı 1/1 mi? (matematiksel tutarlılık kontrolü) */
    public function isBalanced(): bool
    {
        return $this->totalRemaining()->equals(Fraction::of(1));
    }

    /** Tüm satırların pay/payda değerlerini yazdırmak için ortak payda. */
    public function commonDenominator(): int
    {
        $fractions = [];
        foreach ($this->rows as $row) {
            $fractions[] = $row->current;
            $fractions[] = $row->remaining;
        }

        return $fractions === [] ? 1 : Fraction::commonDenominator(...$fractions);
    }
}
