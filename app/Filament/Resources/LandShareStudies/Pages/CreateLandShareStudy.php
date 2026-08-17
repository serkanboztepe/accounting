<?php

namespace App\Filament\Resources\LandShareStudies\Pages;

use App\Filament\Resources\LandShareStudies\LandShareStudyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLandShareStudy extends CreateRecord
{
    protected static string $resource = LandShareStudyResource::class;

    protected function getRedirectUrl(): string
    {
        // Oluşturduktan sonra canlı sihirbaza git.
        return $this->getResource()::getUrl('build', ['record' => $this->getRecord()]);
    }
}
