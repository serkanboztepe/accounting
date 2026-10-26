<?php

namespace App\Models\Concerns;

use App\Exceptions\FeatureDisabled;

/**
 * Kapalı özelliğe yazma engeli (plan 26.10): menü ve WhatsApp zaten engeller; bu, unutulan bir yol olursa son güvenlik
 * ağıdır — ör. gider kapalı firmaya hiçbir yoldan gider yazılmaz. Yalnız YENİ kayıtta bakılır (eski kayıt düzenlenebilir).
 * Model featureEnabled() + featureLabel() tanımlar.
 */
trait RequiresFeature
{
    abstract public static function featureEnabled(): bool;

    abstract public static function featureLabel(): string;

    public static function bootRequiresFeature(): void
    {
        static::creating(function (): void {
            if (! static::featureEnabled()) {
                throw new FeatureDisabled(static::featureLabel());
            }
        });
    }
}
