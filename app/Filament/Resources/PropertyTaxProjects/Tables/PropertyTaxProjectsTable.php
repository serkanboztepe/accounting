<?php

namespace App\Filament\Resources\PropertyTaxProjects\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PropertyTaxProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Proje / Bina')
                    ->searchable()
                    ->sortable(),
                // Mükellefler artık ayrı tabloda (property_tax_taxpayers) — eski proje seviyesi
                // taxpayer_surname alanı boştu. Mükellef SAYISINI göster.
                TextColumn::make('taxpayers_count')
                    ->label('Mükellef')
                    ->counts('taxpayers')
                    ->badge()
                    ->color('success'),
                TextColumn::make('building_owner')
                    ->label('Yapı Sahibi')
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('cadastral_parcel')
                    ->label('Ada/Parsel'),
                TextColumn::make('city')
                    ->label('İl')
                    ->toggleable(),
                TextColumn::make('blocks_count')
                    ->label('Blok')
                    ->counts('blocks')
                    ->badge()
                    ->color('info'),
                TextColumn::make('declaration_year')
                    ->label('Yıl'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
