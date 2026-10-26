<?php

namespace App\Filament\Resources\Checks\Pages;

use App\Filament\Resources\Checks\CheckResource;
use App\Support\CheckLedger;
use Filament\Resources\Pages\CreateRecord;

class CreateCheck extends CreateRecord
{
    protected static string $resource = CheckResource::class;

    /** "Cari hesabına da yaz" işaretliyse çek cariye ödeme / tahsilat olarak işlenir (CheckLedger). */
    protected function afterCreate(): void
    {
        if ($this->data['write_ledger'] ?? true) {
            CheckLedger::attach($this->record);
        }
    }
}
