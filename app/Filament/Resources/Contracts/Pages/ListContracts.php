<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Filament\Resources\Contracts\ContractResource;
use App\Models\Contract;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListContracts extends ListRecords
{
    protected static string $resource = ContractResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Alım butonu — sadece satış yapan firmada gizlenebilir.
            Action::make('createPurchase')
                ->label('Alım Sözleşmesi')
                ->icon(Heroicon::OutlinedShoppingBag)
                ->visible(fn () => (bool) config('modules.purchase_contracts'))
                ->url(ContractResource::getUrl('create', ['direction' => Contract::DIRECTION_PURCHASE])),

            // Satış butonu — sadece alım yapan firmada gizlenebilir (liste yine satışları gösterir).
            Action::make('createSale')
                ->label('Satış Sözleşmesi')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('success')
                ->visible(fn () => (bool) config('modules.sales_contracts'))
                ->url(ContractResource::getUrl('create', ['direction' => Contract::DIRECTION_SALE])),
        ];
    }
}
