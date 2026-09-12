<?php

namespace App\Filament\Resources\Sales\RelationManagers;

use App\Support\Money;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReturnsRelationManager extends RelationManager
{
    protected static string $relationship = 'saleReturns';

    protected static ?string $title = 'İadeler';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Bu satışa alınan iadeler. “Geri Al” iadenin stok girişini ve cari alacağını birlikte geri alır.')
            ->defaultSort('return_date', 'desc')
            ->columns([
                TextColumn::make('return_date')
                    ->label('Tarih')
                    ->date('d.m.Y')
                    ->sortable(),

                TextColumn::make('lines_count')
                    ->label('Kalem')
                    ->counts('lines')
                    ->alignEnd(),

                TextColumn::make('total_amount')
                    ->label('İade Tutarı')
                    ->formatStateUsing(fn ($state) => Money::format((float) $state) . ' ₺')
                    ->alignEnd(),

                TextColumn::make('notes')
                    ->label('Not')
                    ->limit(40)
                    ->toggleable(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label('Geri Al')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->modalHeading('İadeyi Geri Al')
                    ->modalDescription('Bu iadenin stok girişi ve cari alacağı geri alınacak (stok tekrar düşer, cari borç geri gelir). Emin misiniz?')
                    ->modalSubmitActionLabel('Evet, geri al'),
            ]);
    }
}
