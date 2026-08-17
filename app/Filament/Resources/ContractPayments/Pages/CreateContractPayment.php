<?php

namespace App\Filament\Resources\ContractPayments\Pages;

use App\Filament\Resources\ContractPayments\ContractPaymentResource;
use App\Models\Check;
use App\Models\ContractPayment;
use Filament\Resources\Pages\CreateRecord;

class CreateContractPayment extends CreateRecord
{
    protected static string $resource = ContractPaymentResource::class;

    protected function handleRecordCreation(array $data): ContractPayment
    {
        $checkData = [
            'check_number' => $data['check_number'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'issue_date' => $data['payment_date'] ?? now()->toDateString(),
            'due_date' => $data['check_due_date'] ?? $data['payment_date'] ?? now()->toDateString(),
            'status' => $data['check_status'] ?? 'issued',
            'description' => $data['check_description'] ?? null,
            'notes' => $data['check_notes'] ?? null,
        ];

        unset(
            $data['check_number'],
            $data['bank_name'],
            $data['check_due_date'],
            $data['check_status'],
            $data['check_description'],
            $data['check_notes'],
        );

        $record = ContractPayment::create($data);

        if ($record->payment_type === 'check') {
            Check::create([
                'contract_payment_id' => $record->id,
                'party_id' => $record->contract->party_id,
                'amount' => $record->amount,
                ...$checkData,
            ]);
        }

        return $record;
    }
}
