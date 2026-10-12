<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Support\Money;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPurchase extends EditRecord
{
    protected static string $resource = PurchaseResource::class;

    public function getTitle(): string
    {
        return 'Alış #' . $this->record->id . ' · ' . $this->record->supplierLabel();
    }

    protected function getHeaderActions(): array
    {
        // Silince stok girişleri ve cari satırları (alış + peşin ödeme) birlikte gider.
        return [DeleteAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['lines'] = $this->record->lines()->orderBy('id')->get()->map(fn ($m) => [
            'product_id' => $m->product_id,
            'quantity'   => number_format((float) $m->quantity, 2, '.', ''),
            'unit_price' => $m->unit_price !== null ? Money::format((float) $m->unit_price) : null,
            'amount'     => $m->amount !== null ? Money::format((float) $m->amount) : null,
        ])->toArray();

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->rebuildFromLines($this->data['lines'] ?? []);
    }
}
