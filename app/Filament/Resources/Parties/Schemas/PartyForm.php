<?php

namespace App\Filament\Resources\Parties\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
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
                    // Personel: maaş ve ödeme günü (maaş günü hatırlatması, avans mahsubu). Ayrı modül yok, cari + işaret.
                    Toggle::make('is_employee')->label('Personel')->live()
                        ->helperText('Maaş/avans bu cari üzerinden takip edilir; raporlarda "Personel" olarak ayrı görünür.'),
                    TextInput::make('salary_amount')->label('Aylık maaş (₺)')
                        ->numeric()->minValue(0)
                        ->visible(fn (Get $get) => (bool) $get('is_employee')),
                    TextInput::make('salary_day')->label('Maaş günü (ayın kaçı)')
                        ->numeric()->minValue(1)->maxValue(31)
                        ->helperText('WhatsApp\'tan "X\'in maaş günü ayın 15\'i" yazılınca o gün hatırlatma da kurulur.')
                        ->visible(fn (Get $get) => (bool) $get('is_employee')),
                ]),
        ]);
    }
}
