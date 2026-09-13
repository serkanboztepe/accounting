<?php

namespace App\Filament\Resources\Contracts\Tables;

use App\Models\Contract;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ContractsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('project.name')
                    ->label('Proje')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('party.name')
                    ->label('Cari')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Başlık')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('direction')
                    ->label('Yön')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Contract::DIRECTIONS[$state] ?? Contract::DIRECTIONS[Contract::DIRECTION_PURCHASE])
                    ->color(fn (?string $state) => $state === Contract::DIRECTION_SALE ? 'success' : 'gray'),
                TextColumn::make('contract_type')
                    ->label('Tür')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Contract::TYPES[$state] ?? '—')
                    ->color(fn (?string $state) => match ($state) {
                        Contract::TYPE_SUPPLY => 'info',
                        Contract::TYPE_SUBCONTRACT => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('total_amount')
                    ->label('Toplam Bedel')
                    ->getStateUsing(fn (Contract $record) => $record->reportableTotal())
                    ->money('TRY')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Durum')
                    ->badge(),

                TextColumn::make('invoice_status')
                    ->label('Fatura')
                    ->badge()
                    ->getStateUsing(fn ($record) => $record->invoiceStatus())
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'none'     => 'Fatura Yok',
                        'partial'  => 'Kısmi',
                        'complete' => 'Tamamlandı',
                        default    => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'none'     => 'gray',
                        'partial'  => 'warning',
                        'complete' => 'success',
                        default    => 'gray',
                    }),
                TextColumn::make('contract_date')
                    ->label('Tarih')
                    ->date('d.m.Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('direction')
                    ->label('Yön')
                    ->options(Contract::DIRECTIONS),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
