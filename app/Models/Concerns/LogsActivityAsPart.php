<?php

namespace App\Models\Concerns;

/**
 * Kendi başına iş olmayan alt satır (sözleşme kalemi, hisse dağılımı satırı, emlak beyanı dairesi…): değişince üst kayıt
 * için bu istekte bir kez "düzenlendi" yazılır (activityParent zorunlu).
 */
trait LogsActivityAsPart
{
    use LogsActivity;

    public function activityAction(): string
    {
        return class_basename($this);
    }

    public function activityDetails(): array
    {
        return [];
    }
}
