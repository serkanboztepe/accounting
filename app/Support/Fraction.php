<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Basit, değişmez (immutable) rasyonel kesir value object'i.
 *
 * Hisse dağıtımının tamamı tam sayı kesir aritmetiğidir (1/16, 5/48 ...).
 * `App\Support\Money` para için neyse, Fraction da pay/payda için odur:
 * formatlama ve sadeleştirme tek yerden yönetilir, her yerde float ile
 * uğraşılmaz. Payda her zaman pozitif tutulur, işaret payda taşınır.
 */
final class Fraction
{
    public readonly int $num;
    public readonly int $den;

    public function __construct(int $num, int $den = 1)
    {
        if ($den === 0) {
            throw new InvalidArgumentException('Payda sıfır olamaz.');
        }

        // İşareti paya taşı, paydayı pozitif tut.
        if ($den < 0) {
            $num = -$num;
            $den = -$den;
        }

        $g = self::gcd(abs($num), $den) ?: 1;

        $this->num = intdiv($num, $g);
        $this->den = intdiv($den, $g);
    }

    public static function of(int $num, int $den = 1): self
    {
        return new self($num, $den);
    }

    public static function zero(): self
    {
        return new self(0, 1);
    }

    public function add(self $other): self
    {
        return new self(
            $this->num * $other->den + $other->num * $this->den,
            $this->den * $other->den,
        );
    }

    public function sub(self $other): self
    {
        return new self(
            $this->num * $other->den - $other->num * $this->den,
            $this->den * $other->den,
        );
    }

    public function mul(self $other): self
    {
        return new self($this->num * $other->num, $this->den * $other->den);
    }

    public function div(self $other): self
    {
        if ($other->num === 0) {
            throw new InvalidArgumentException('Sıfıra bölme.');
        }

        return new self($this->num * $other->den, $this->den * $other->num);
    }

    public function isZero(): bool
    {
        return $this->num === 0;
    }

    public function isNegative(): bool
    {
        return $this->num < 0;
    }

    public function equals(self $other): bool
    {
        // Her ikisi de kurucuda sadeleştirildiği için doğrudan karşılaştırılır.
        return $this->num === $other->num && $this->den === $other->den;
    }

    public function compare(self $other): int
    {
        return ($this->num * $other->den) <=> ($other->num * $this->den);
    }

    /** Negatifse sıfıra kırpar (satılan hisse hiç negatif olamaz). */
    public function clampNonNegative(): self
    {
        return $this->isNegative() ? self::zero() : $this;
    }

    public function toFloat(): float
    {
        return $this->num / $this->den;
    }

    /** "5/48" ya da tam sayıysa "1". */
    public function __toString(): string
    {
        return $this->den === 1 ? (string) $this->num : "{$this->num}/{$this->den}";
    }

    public static function gcd(int $a, int $b): int
    {
        $a = abs($a);
        $b = abs($b);

        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }

    public static function lcm(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return intdiv(abs($a), self::gcd($a, $b)) * abs($b);
    }

    /**
     * Verilen kesirlerin ortak paydası (paydaların EKOK'u).
     * Tapu çıktısında herkesi aynı paydaya genişletmek için kullanılır.
     */
    public static function commonDenominator(Fraction ...$fractions): int
    {
        $lcm = 1;

        foreach ($fractions as $f) {
            $lcm = self::lcm($lcm, $f->den);
        }

        return $lcm;
    }

    /** Verilen ortak paydaya göre pay değeri (den bu paydaya bölünebilmeli). */
    public function numeratorOver(int $commonDenominator): int
    {
        return $this->num * intdiv($commonDenominator, $this->den);
    }
}
