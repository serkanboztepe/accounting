<?php

namespace App\Filament\Resources\Checks\Pages;

use App\Filament\Resources\Checks\CheckResource;
use App\Support\CheckLedger;
use Filament\Resources\Pages\CreateRecord;

class CreateCheck extends CreateRecord
{
    protected static string $resource = CheckResource::class;

    /** Carili çek cariye ödeme / tahsilat olarak işlenir (CheckLedger; carisiz = yalnız hatırlatma). */
    protected function afterCreate(): void
    {
        CheckLedger::apply($this->record);
    }
}
