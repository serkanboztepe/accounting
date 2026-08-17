<?php

namespace App\Filament\Resources\ContractPayments\Pages;

use App\Filament\Resources\ContractPayments\ContractPaymentResource;
use App\Models\Check;
use Illuminate\Database\Eloquent\Model;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditContractPayment extends EditRecord
{
    protected static string $resource = ContractPaymentResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $check = $this->record->checks()->latest()->first();

        if ($check) {
            $data['check_number'] = $check->check_number;
            $data['bank_name'] = $check->bank_name;
            $data['check_due_date'] = optional($check->due_date)?->format('Y-m-d');
            $data['check_status'] = $check->status;
            $data['check_description'] = $check->description;
            $data['check_notes'] = $check->notes;
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $checkData = [
            'check_number' => $data['check_number'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'issue_date' => $data['payment_date'] ?? now()->toDateString(),
            'due_date' => $data['check_due_date'] ?? $data['payment_date'] ?? now()->toDateString(),
            'amount' => $data['amount'],
            'status' => $data['check_status'] ?? 'issued',
            'description' => $data['check_description'] ?? null,
            'notes' => $data['check_notes'] ?? null,
            'party_id' => $record->contract->party_id,
        ];

        unset(
            $data['check_number'],
            $data['bank_name'],
            $data['check_due_date'],
            $data['check_status'],
            $data['check_description'],
            $data['check_notes'],
        );

        $record->update($data);

        if (($data['payment_type'] ?? null) === 'check') {
            $check = $record->checks()->latest()->first();

            if ($check) {
                $check->update($checkData);
            } else {
                Check::create($checkData + [
                        'contract_payment_id' => $record->id,
                    ]);
            }
        } else {
            $record->checks()->delete();
        }

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
