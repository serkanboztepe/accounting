<?php

namespace App\Filament\Resources\StockMovements\Tables;

use App\Models\StockMovement;
use App\Support\Money;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StockMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('movement_date', 'desc')
            ->description('🔒 satırlar Direkt Satış / İade’den gelir — düzenleme/silme satış ekranından yapılır. Yalnız elle Mal Girişi / Düzeltme satırları buradan yönetilir.')
            ->columns([
                TextColumn::make('sale_id')
                    ->label('')
                    ->formatStateUsing(fn ($state) => $state ? '🔒' : '')
                    ->tooltip(fn ($state) => $state ? 'Satış/İade’den gelir — buradan değiştirilemez' : null),

                TextColumn::make('movement_date')
                    ->label('Tarih')
                    ->date('d.m.Y')
                    ->sortable(),

                TextColumn::make('product.name')
                    ->label('Ürün')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('direction')
                    ->label('Yön')
                    ->badge()
                    ->formatStateUsing(fn (?string $s) => StockMovement::DIRECTION_LABELS[$s] ?? $s)
                    ->color(fn (?string $s) => $s === StockMovement::DIRECTION_IN ? 'success' : 'danger'),

                TextColumn::make('reason')
                    ->label('Sebep')
                    ->badge()
                    ->formatStateUsing(fn (?string $s) => StockMovement::REASON_LABELS[$s] ?? $s)
                    ->color('gray'),

                TextColumn::make('quantity')
                    ->label('Miktar')
                    ->numeric(2)
                    ->alignEnd(),

                TextColumn::make('unit_price')
                    ->label('Birim Fiyat')
                    ->formatStateUsing(fn ($state) => $state !== null ? Money::format($state) . ' ₺' : '—')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('party.name')
                    ->label('Cari')
                    ->toggleable(),

                TextColumn::make('project.name')
                    ->label('Proje')
                    ->toggleable(),

                TextColumn::make('notes')
                    ->label('Not')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('direction')
                    ->label('Yön')
                    ->options(StockMovement::DIRECTION_LABELS),
                SelectFilter::make('reason')
                    ->label('Sebep')
                    ->options(StockMovement::REASON_LABELS),
                SelectFilter::make('product_id')
                    ->label('Ürün')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                // Satış/İade'den gelen (sale_id'li) satırlar salt-okunur — satıştan yönetilir.
                EditAction::make()
                    ->visible(fn (StockMovement $record) => $record->sale_id === null),
                DeleteAction::make()
                    ->visible(fn (StockMovement $record) => $record->sale_id === null),
            ]);
    }
}
