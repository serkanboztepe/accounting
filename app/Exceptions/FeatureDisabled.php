<?php

namespace App\Exceptions;

use RuntimeException;

/** Firmada kapalı bir özelliğe kayıt yazılmaya çalışıldı (Concerns\RequiresFeature — son güvenlik ağı). */
class FeatureDisabled extends RuntimeException
{
    public function __construct(public readonly string $feature)
    {
        parent::__construct("Bu firmada {$feature} kapalı — kayıt yazılmadı.");
    }
}
