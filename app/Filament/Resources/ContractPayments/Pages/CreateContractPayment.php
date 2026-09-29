<?php

namespace App\Filament\Resources\ContractPayments\Pages;

use App\Filament\Resources\ContractPayments\ContractPaymentResource;
use App\Models\ContractPayment;
use Filament\Resources\Pages\CreateRecord;

class CreateContractPayment extends CreateRecord
{
    protected static string $resource = ContractPaymentResource::class;

    protected function handleRecordCreation(array $data): ContractPayment
    {
        $dueDate = $data['check_due_date'] ?? null;
        unset($data['check_due_date']);

        $record = ContractPayment::create($data);
        $record->syncCheck($dueDate); // çekse bağlı Check açar; durum senkron

        return $record;
    }
}
