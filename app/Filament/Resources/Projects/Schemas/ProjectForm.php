<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Models\Party;
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
            Select::make('party_id')
                ->label('Sahip müşteri')
                ->options(fn () => Party::orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->preload()
                ->helperText('Opsiyonel — proje bir müşteriye aitse seç. Kendi projelerinde boş bırak.'),
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
