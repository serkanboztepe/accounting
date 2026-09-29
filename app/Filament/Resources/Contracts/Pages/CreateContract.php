<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Filament\Resources\Contracts\ContractResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContract extends CreateRecord
{
    protected static string $resource = ContractResource::class;

    /**
     * Durum ve tarih alanları formdan çıkarıldı — oluştururken otomatik doldur:
     * yeni sözleşme 'active', başlangıç tarihi = oluşturma günü. Bitiş tarihi
     * boş kalır (sözleşme kapatılınca dolacak).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] ??= 'active';
        $data['contract_date'] ??= now()->toDateString();
        $data['start_date'] ??= now()->toDateString();

        return $data;
    }
}
