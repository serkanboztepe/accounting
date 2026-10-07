<?php

namespace App\Filament\Hub\HubFirms\Pages;

use App\Filament\Hub\HubFirms\HubFirmResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageHubFirms extends ManageRecords
{
    protected static string $resource = HubFirmResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
