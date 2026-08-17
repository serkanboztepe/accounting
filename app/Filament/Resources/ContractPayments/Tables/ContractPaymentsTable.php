<?php

namespace App\Filament\Resources\ContractPayments\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContractPaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('contract.title')
                    ->label('Sözleşme')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('payment_type')
                    ->label('Ödeme Türü')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'cash'             => 'Nakit',
                        'eft'              => 'EFT',
                        'bank_transfer'    => 'Havale',
                        'check'            => 'Çek',
                        'promissory_note'  => 'Senet',
                        'other'            => 'Diğer',
                        default            => $state,
                    }),
                TextColumn::make('amount')
                    ->label('Tutar')
                    ->money('TRY')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Durum')
                    ->badge(),
                TextColumn::make('payment_date')
                    ->label('Ödeme Tarihi')
                    ->date('d.m.Y')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
