<?php

namespace App\Filament\Resources\StockMovements\Pages;

use App\Filament\Resources\StockMovements\Schemas\StockMovementForm;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Models\StockMovement;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListStockMovements extends ListRecords
{
    protected static string $resource = StockMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Mal Girişi — hep giren + alım. Yön/sebep sorulmaz.
            CreateAction::make('malGirisi')
                ->label('Mal Girişi')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('success')
                ->modalHeading('Mal Girişi')
                ->mutateDataUsing(function (array $data): array {
                    $data['direction'] = StockMovement::DIRECTION_IN;
                    $data['reason'] = StockMovement::REASON_PURCHASE;

                    return $data;
                }),

            // Stok Düzeltme — nadir (sayım/fire). Modalda insanca Ekle/Çıkar seçimi.
            CreateAction::make('duzeltme')
                ->label('Stok Düzeltme')
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->color('gray')
                ->modalHeading('Stok Düzeltme (Sayım / Fire)')
                ->schema([
                    Select::make('adj_dir')
                        ->label('İşlem')
                        ->options([
                            StockMovement::DIRECTION_IN  => 'Ekle (+) — sayım fazlası',
                            StockMovement::DIRECTION_OUT => 'Çıkar (−) — fire / eksik',
                        ])
                        ->default(StockMovement::DIRECTION_OUT)
                        ->required()
                        ->columnSpanFull(),
                    ...StockMovementForm::baseComponents(),
                ])
                ->mutateDataUsing(function (array $data): array {
                    $data['direction'] = ($data['adj_dir'] ?? StockMovement::DIRECTION_OUT) === StockMovement::DIRECTION_IN
                        ? StockMovement::DIRECTION_IN
                        : StockMovement::DIRECTION_OUT;
                    $data['reason'] = StockMovement::REASON_ADJUSTMENT;
                    unset($data['adj_dir']);

                    return $data;
                }),
        ];
    }
}
