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
                ->visible(fn () => (bool) \App\Support\Features::purchaseContracts())
                ->url(ContractResource::getUrl('create', ['direction' => Contract::DIRECTION_PURCHASE])),

            // Satış butonu — sadece alım yapan firmada gizlenebilir (liste yine satışları gösterir).
            Action::make('createSale')
                ->label('Satış Sözleşmesi')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('success')
                ->visible(fn () => (bool) \App\Support\Features::salesContracts())
                ->url(ContractResource::getUrl('create', ['direction' => Contract::DIRECTION_SALE])),
        ];
    }
}
