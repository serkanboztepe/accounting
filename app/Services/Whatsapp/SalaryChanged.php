<?php

namespace App\Services\Whatsapp;

use RuntimeException;

/** Onay beklerken maaş hesabı değişti (başka kayıt girdi): kart yeniden gösterilir, yazılmaz (kart = kayıt). */
final class SalaryChanged extends RuntimeException
{
    public function __construct(public readonly array $draft)
    {
        parent::__construct('Maaş hesabı onaydan önce değişti.');
    }
}
