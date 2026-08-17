<?php

namespace App\Filament\Resources\Projects\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->maxLength(255),
            TextInput::make('location')->maxLength(255),
            Select::make('status')
                ->options([
                    'draft' => 'Taslak',
                    'active' => 'Aktif',
                    'completed' => 'Tamamlandı',
                    'cancelled' => 'İptal',
                ])
                ->default('active')
                ->required(),
            DatePicker::make('start_date'),
            DatePicker::make('end_date'),
            Textarea::make('notes')->columnSpanFull(),
        ])->columns(2);
    }
}
