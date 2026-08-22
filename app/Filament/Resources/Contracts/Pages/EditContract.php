<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Contracts\Widgets\ContractSummary;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditContract extends EditRecord
{
    protected static string $resource = ContractResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Yazdır')
                ->icon(Heroicon::OutlinedPrinter)
                ->url(fn () => route('contract.print', $this->record))
                ->extraAttributes(fn () => \App\Support\Printing::iframeAttributes(route('contract.print', $this->record))),

            DeleteAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ContractSummary::class,
        ];
    }
}
