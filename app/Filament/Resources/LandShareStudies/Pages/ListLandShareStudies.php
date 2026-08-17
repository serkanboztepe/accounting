<?php

namespace App\Filament\Resources\LandShareStudies\Pages;

use App\Filament\Resources\LandShareStudies\LandShareStudyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLandShareStudies extends ListRecords
{
    protected static string $resource = LandShareStudyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Yeni Çalışma'),
        ];
    }
}
