<?php

namespace App\Filament\Hub\HubFirms\Pages;

use App\Filament\Hub\HubFirms\HubFirmResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHubFirms extends ListRecords
{
    protected static string $resource = HubFirmResource::class;

    protected function getHeaderActions(): array
    {
        // create sayfası yok → modal açılır; kaydedince firmanın sayfasına (numara eklemeye) git.
        return [
            CreateAction::make()
                ->successRedirectUrl(fn ($record) => HubFirmResource::getUrl('edit', ['record' => $record])),
        ];
    }
}
