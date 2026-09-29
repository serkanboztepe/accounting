<?php

namespace App\Filament\Resources\ContractPayments\Tables;

use App\Models\ContractPayment;
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
                    ->label('Yöntem')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ContractPayment::PAYMENT_TYPES[$state] ?? $state),
                TextColumn::make('amount')
                    ->label('Tutar')
                    ->money('TRY')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Durum')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ContractPayment::STATUSES[$state] ?? '—')
                    ->color(fn (?string $state): string => $state === 'paid' ? 'success' : 'gray'),
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
