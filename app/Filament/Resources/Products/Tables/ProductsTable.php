<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use App\Support\Money;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Ad')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Tür')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Product::TYPE_LABELS[$state] ?? $state)
                    ->color(fn (?string $state) => $state === Product::TYPE_SERVICE ? 'info' : 'success'),

                TextColumn::make('category')
                    ->label('Kategori')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('unit.name')
                    ->label('Birim')
                    ->toggleable(),

                TextColumn::make('default_price')
                    ->label('Varsayılan fiyat')
                    ->formatStateUsing(fn ($state) => $state !== null ? Money::format($state) : '—')
                    ->alignEnd(),

                TextColumn::make('current_stock')
                    ->label('Mevcut Stok')
                    ->visible(fn () => (bool) config('modules.stock'))
                    ->state(fn (Product $record) => $record->isProduct()
                        ? number_format($record->currentStock(), 2, ',', '.')
                        : '—')
                    ->alignEnd()
                    ->badge()
                    ->color(fn (Product $record) => $record->isProduct() && $record->currentStock() < 0
                        ? 'danger'
                        : 'gray'),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tür')
                    ->options(Product::TYPE_LABELS),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
