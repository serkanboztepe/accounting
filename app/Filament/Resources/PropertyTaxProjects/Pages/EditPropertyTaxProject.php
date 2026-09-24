<?php

namespace App\Filament\Resources\PropertyTaxProjects\Pages;

use App\Filament\Resources\PropertyTaxProjects\PropertyTaxProjectResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditPropertyTaxProject extends EditRecord
{
    protected static string $resource = PropertyTaxProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('allFormatliPdf')
                ->label('Tüm Mükellefler — Formatlı PDF')
                ->icon(Heroicon::OutlinedDocumentCheck)
                ->color('danger')
                ->url(fn () => route('property-tax.project.formatli-pdf', $this->record), shouldOpenInNewTab: true),

            Action::make('allExcel')
                ->label('Tümü — Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('success')
                ->url(fn () => route('property-tax.project.excel', $this->record), shouldOpenInNewTab: true),

            DeleteAction::make(),
        ];
    }
}
