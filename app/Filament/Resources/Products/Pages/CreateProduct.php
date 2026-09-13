<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Stok kapalıyken tür seçimi gösterilmez → yeni kart daima "Hizmet".
        if (! config('modules.stock')) {
            $data['type'] = Product::TYPE_SERVICE;
        }

        return $data;
    }
}
