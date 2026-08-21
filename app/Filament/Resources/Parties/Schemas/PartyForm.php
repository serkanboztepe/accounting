<?php

namespace App\Filament\Resources\Parties\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PartyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cari Bilgileri')
                ->collapsible()
                // Create → açık (doldurulacak) · Edit → kapalı (asıl amaç ekstre/geçmiş)
                ->collapsed(fn (string $operation): bool => $operation === 'edit')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label('İsim')->required()->maxLength(255),
                    TextInput::make('phone')->label('Telefon')->maxLength(255),
                    Textarea::make('notes')->label('Notlar')->columnSpanFull(),
                ]),
        ]);
    }
}
