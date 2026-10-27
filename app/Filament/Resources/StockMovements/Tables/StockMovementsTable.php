<?php

namespace App\Filament\Resources\StockMovements\Tables;

use App\Support\Labels;
use App\Filament\Resources\StockMovements\StockMovementResource;
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
            ->description(StockMovementResource::readOnly()
                ? 'Stok giriş-çıkışlarının dökümü. Satır buradan değiştirilmez: satış/alış yanlışsa Satışlar ya da Alışlar’dan düzelt, sayı tutmuyorsa Ürünler’de "Sayım" yap.'
                : '🔒 satırlar Direkt Satış / İade’den gelir — düzenleme/silme satış ekranından yapılır. Yalnız elle Mal Girişi / Düzeltme satırları buradan yönetilir.')
            ->columns([
                TextColumn::make('sale_id')
                    ->label('')
                    ->visible(fn () => ! StockMovementResource::readOnly())
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
                    ->label('Kişi / Firma')
                    ->toggleable(),

                TextColumn::make('project.name')
                    ->label(Labels::project())
                    ->visible(fn () => (bool) \App\Support\Features::places())
                    ->toggleable(),

                TextColumn::make('notes')
                    ->label('Not')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Süzgeç seçilince hemen uygulansın (Filament 4 varsayılanı "Uygula" düğmesini bekliyor — kullanıcı 21.10: "Yön, Sebep çalışmıyor").
            ->deferFilters(false)
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
                // Satış/İade/Alış belgesinden gelen satırlar salt-okunur — belgeden yönetilir.
                EditAction::make()
                    ->visible(fn (StockMovement $record) => ! StockMovementResource::readOnly() && $record->sale_id === null && $record->purchase_id === null),
                DeleteAction::make()
                    ->visible(fn (StockMovement $record) => ! StockMovementResource::readOnly() && $record->sale_id === null && $record->purchase_id === null),
            ]);
    }
}
