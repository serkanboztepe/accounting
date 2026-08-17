<?php

namespace App\Filament\Resources\ContractPayments\Pages;

use App\Filament\Resources\ContractPayments\ContractPaymentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListContractPayments extends ListRecords
{
    protected static string $resource = ContractPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
