<?php

namespace App\Filament\Resources\Checks\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChecksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('party.name')
                    ->label('Cari')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('project.name')
                    ->label('Proje')
                    ->toggleable(),
                TextColumn::make('check_number')
                    ->label('Çek No')
                    ->searchable(),
                TextColumn::make('bank_name')
                    ->label('Banka')
                    ->toggleable(),
                TextColumn::make('amount')
                    ->label('Tutar')
                    ->money('TRY')
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Vade')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Durum')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'portfolio'  => 'Portföyde',
                        'issued'     => 'Verildi',
                        'collected'  => 'Tahsil Edildi',
                        'paid'       => 'Ödendi',
                        'cancelled'  => 'İptal',
                        'bounced'    => 'Karşılıksız',
                        default      => $state,
                    }),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
