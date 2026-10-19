<?php

namespace App\Filament\Resources\Parties\Pages;

use App\Filament\Resources\Parties\PartyResource;
use App\Services\Whatsapp\EmployeeDraft;
use Filament\Resources\Pages\CreateRecord;

class CreateParty extends CreateRecord
{
    protected static string $resource = PartyResource::class;

    /** Personel + maaş günü girildiyse maaş günü hatırlatması kurulur (tek doğru yer kart). */
    protected function afterCreate(): void
    {
        EmployeeDraft::syncFromCard($this->record);
    }
}
