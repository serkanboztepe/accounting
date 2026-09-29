<?php

namespace App\Filament\Resources\ContractPayments\Pages;

use App\Filament\Resources\ContractPayments\ContractPaymentResource;
use Illuminate\Database\Eloquent\Model;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditContractPayment extends EditRecord
{
    protected static string $resource = ContractPaymentResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $check = $this->record->checks()->latest()->first();
        $data['check_due_date'] = optional($check?->due_date)?->format('Y-m-d');

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $dueDate = $data['check_due_date'] ?? null;
        unset($data['check_due_date']);

        $record->update($data);
        $record->syncCheck($dueDate); // çekse Check oluştur/güncelle, durum senkron; çek değilse temizle

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
