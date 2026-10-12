<?php

namespace App\Filament\Resources\Products\Actions;

use App\Models\Product;
use App\Support\StockUsage;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/** Ürünler listesinde ve ürün kartında "Sayım": elindeki adet yazılır, fark Düzeltme olur. */
class CountStockAction
{
    public static function make(): Action
    {
        return Action::make('countStock')
            ->label('Sayım')
            ->icon(Heroicon::OutlinedCalculator)
            ->color('gray')
            ->visible(fn (Product $record) => config('modules.stock') && $record->isProduct())
            ->modalHeading(fn (Product $record) => 'Sayım · ' . $record->name)
            ->modalDescription(fn (Product $record) => 'Sistemde: ' . StockUsage::quantity($record->currentStock()) . ' ' . ($record->unit?->name ?? '')
                . '. Elindekini yaz, fark düzeltme olarak kaydedilir.')
            ->modalSubmitActionLabel('Kaydet')
            ->schema([
                TextInput::make('counted')
                    ->label('Elinde kaç tane var?')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->autofocus(),
                DatePicker::make('date')->label('Tarih')->default(now())->required(),
            ])
            ->action(function (array $data, Product $record) {
                $before = $record->currentStock();
                $movement = $record->countTo((float) $data['counted'], $data['date']);

                Notification::make()
                    ->success()
                    ->title($movement
                        ? 'Stok ' . StockUsage::quantity($before) . ' → ' . StockUsage::quantity((float) $data['counted']) . ' yapıldı'
                        : 'Stok zaten doğru, değişiklik yok')
                    ->send();
            });
    }
}
