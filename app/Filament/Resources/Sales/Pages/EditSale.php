<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Resources\Sales\SaleResource;
use App\Support\Money;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSale extends EditRecord
{
    protected static string $resource = SaleResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * İadesi olan satış düzenlenemez — kalem/adet değişirse iadelerle çelişip stok/bakiye bozulur
     * (rebuildFromLines iadeleri ellemez). Önce iade "geçmiş iadeler"den geri alınmalı.
     */
    protected function beforeSave(): void
    {
        if ($this->record->saleReturns()->exists()) {
            Notification::make()
                ->danger()
                ->title('Bu satışın iadesi var')
                ->body('Önce iadeyi geri alın (aşağıdaki geçmiş iadeler), sonra düzenleyin.')
                ->send();

            $this->halt();
        }
    }

    /** Mevcut kalemleri (stok çıkışları) repeater'a doldur. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['lines'] = $this->record->lines()
            ->orderBy('id')
            ->get()
            ->map(fn ($m) => [
                'product_id' => $m->product_id,
                'quantity'   => number_format((float) $m->quantity, 2, '.', ''),
                'unit_price' => $m->unit_price !== null ? Money::format((float) $m->unit_price) : null,
                'amount'     => $m->amount !== null ? Money::format((float) $m->amount) : null,
            ])
            ->toArray();

        return $data;
    }

    protected function afterSave(): void
    {
        // Kalemleri yeniden kur (stok + cari otomatik güncellenir).
        $this->record->rebuildFromLines($this->data['lines'] ?? []);
    }
}
