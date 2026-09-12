<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Resources\Sales\SaleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSale extends CreateRecord
{
    protected static string $resource = SaleResource::class;

    protected function afterCreate(): void
    {
        // Kalemleri stok çıkışına + cari borca dönüştür.
        $this->record->rebuildFromLines($this->data['lines'] ?? []);
    }
}
