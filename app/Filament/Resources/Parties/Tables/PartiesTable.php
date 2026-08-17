<?php

namespace App\Filament\Resources\Parties\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PartiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('İsim')->searchable()->sortable(),
                TextColumn::make('phone')->label('Telefon')->searchable(),
                TextColumn::make('notes')->label('Notlar')->limit(50),
                TextColumn::make('created_at')->label('Oluşturulma')->dateTime()->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
