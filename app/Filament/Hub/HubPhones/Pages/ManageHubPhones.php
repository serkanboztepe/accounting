<?php

namespace App\Filament\Hub\HubPhones\Pages;

use App\Filament\Hub\HubPhones\HubPhoneResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageHubPhones extends ManageRecords
{
    protected static string $resource = HubPhoneResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
