<?php

namespace App\Filament\Resources\Parties\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PartyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('İsim')->required()->maxLength(255),
            TextInput::make('phone')->label('Telefon')->maxLength(255),
            Textarea::make('notes')->label('Notlar')->columnSpanFull(),
        ])->columns(2);
    }
}
