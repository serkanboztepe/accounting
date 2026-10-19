<?php

namespace App\Filament\Resources\Purchases\RelationManagers;

use App\Support\Money;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Alış iadeleri (WhatsApp "geri verdim") — satış iadelerinin aynası; "Geri Al" stok çıkışını ve cari satırını birlikte geri alır. */
class ReturnsRelationManager extends RelationManager
{
    protected static string $relationship = 'purchaseReturns';

    protected static ?string $title = 'İadeler';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Bu alıştan tedarikçiye geri verilen mal. “Geri Al” iadenin stok çıkışını ve cari kaydını birlikte geri alır.')
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
                    ->modalDescription('Bu iadenin stok çıkışı ve cari kaydı geri alınacak (stok tekrar artar, borç geri gelir). Emin misiniz?')
                    ->modalSubmitActionLabel('Evet, geri al'),
            ]);
    }
}
