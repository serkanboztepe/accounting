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
            TextInput::make('name')->label('Ad')->required()->maxLength(255),
            Select::make('party_id')
                ->label('Sahip müşteri')
                ->options(fn () => Party::orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->preload()
                ->helperText('Opsiyonel — proje bir müşteriye aitse seç. Kendi projelerinde boş bırak.'),
            TextInput::make('code')->label('Kod')->maxLength(255),
            TextInput::make('location')->label('Konum')->maxLength(255),
            Select::make('status')
                ->label('Durum')
                ->options([
                    'draft' => 'Taslak',
                    'active' => 'Aktif',
                    'completed' => 'Tamamlandı',
                    'cancelled' => 'İptal',
                ])
                ->default('active')
                ->required(),
            DatePicker::make('start_date')->label('Başlangıç Tarihi'),
            DatePicker::make('end_date')->label('Bitiş Tarihi'),
            Textarea::make('notes')->label('Notlar')->columnSpanFull(),
        ])->columns(2);
    }
}
