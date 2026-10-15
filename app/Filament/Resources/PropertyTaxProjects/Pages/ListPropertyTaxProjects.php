<?php

namespace App\Filament\Resources\PropertyTaxProjects\Pages;

use App\Filament\Resources\PropertyTaxProjects\PropertyTaxProjectResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPropertyTaxProjects extends ListRecords
{
    protected static string $resource = PropertyTaxProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // v2 deneme: adı sorulur, proje açılır, sihirbaz ① adımdan başlar.
            \Filament\Actions\Action::make('newWizard')
                ->label('Yeni — Adım Adım (v2 deneme)')
                ->icon('heroicon-o-list-bullet')
                ->color('warning')
                ->modalHeading('Yeni Emlak Beyanı (adım adım)')
                ->modalSubmitActionLabel('Başla')
                ->schema([\Filament\Forms\Components\TextInput::make('name')->label('Proje / Bina Adı')->required()->placeholder('Hocabey Apartmanı')])
                ->action(function (array $data) {
                    $project = \App\Models\PropertyTaxProject::create([
                        'name' => $data['name'],
                        'declaration_year' => (string) now()->year,
                        'declaration_date' => now()->toDateString(),
                        'filing_reason' => 'first_acquisition',
                        'city' => config('property_tax.city'),
                        'district' => config('property_tax.district'),
                        'municipality' => config('property_tax.municipality'),
                    ]);
                    $this->redirect(PropertyTaxProjectResource::getUrl('build', ['record' => $project]));
                }),
            CreateAction::make()->label('Yeni Proje'),
        ];
    }
}
