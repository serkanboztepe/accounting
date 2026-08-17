<?php

namespace App\Filament\Resources\Expenses\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('expense_date')
                    ->label('Tarih')
                    ->date()
                    ->sortable(),
                TextColumn::make('project.name')
                    ->label('Proje')
                    ->placeholder('Genel Gider')
                    ->searchable(),
                TextColumn::make('category.name')
                    ->label('Kategori')
                    ->searchable(),
                TextColumn::make('party.name')
                    ->label('Cari')
                    ->searchable(),
                TextColumn::make('amount')
                    ->label('Tutar')
                    ->money('TRY')
                    ->sortable(),
                TextColumn::make('payment_status')
                    ->label('Durum')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'paid'    => 'Ödendi',
                        'unpaid'  => 'Ödenmedi',
                        'partial' => 'Kısmi Ödendi',
                        default   => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'paid'    => 'success',
                        'unpaid'  => 'danger',
                        'partial' => 'warning',
                        default   => 'gray',
                    }),
                TextColumn::make('description')
                    ->label('Açıklama')
                    ->limit(40)
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('project_id')
                    ->label('Proje')
                    ->relationship('project', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('Tüm Projeler'),

                SelectFilter::make('payment_status')
                    ->label('Ödeme Durumu')
                    ->options([
                        'unpaid' => 'Ödenmedi',
                        'partial' => 'Kısmi Ödendi',
                        'paid' => 'Ödendi',
                    ]),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make()->slideOver(),
                DeleteAction::make(),
            ]);
    }
}
