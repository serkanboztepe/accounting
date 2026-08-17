<?php

namespace App\Services\LandShare;

use App\Support\Fraction;

/** Tapu çıktısında bir kişiye ait satır. */
final class ShareRow
{
    /** @param array<int, string> $explanation */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly bool $isContractor,
        public readonly Fraction $current,
        public readonly Fraction $sold,
        public readonly Fraction $remaining,
        public readonly array $explanation = [],
    ) {
    }
}
