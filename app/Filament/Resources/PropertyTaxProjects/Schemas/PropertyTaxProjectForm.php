<?php

namespace App\Filament\Resources\PropertyTaxProjects\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Proje (ortak) seviyesi form. Konum + beyan bilgileri BİR KEZ girilir;
 * mükellefler ayrı sekmede, bloklar/daireler bu bilgileri paylaşır.
 * İl/ilçe/belediye .env'den (config/property_tax.php) otomatik dolar.
 */
class PropertyTaxProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Proje')
                ->schema([
                    TextInput::make('name')
                        ->label('Proje / Bina Adı')
                        ->required()
                        ->placeholder('Hocabey Apartmanı')
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),

            Grid::make(2)->schema([
                Section::make('Beyan')
                    ->columns(1)
                    ->schema([
                        TextInput::make('declaration_year')
                            ->label('Beyan Yılı')
                            ->default((string) now()->year)
                            ->placeholder((string) now()->year),
                        DatePicker::make('declaration_date')
                            ->label('Beyan Tarihi')
                            ->default(now()),
                        Select::make('filing_reason')
                            ->label('Veriliş Nedeni')
                            ->options([
                                'first_acquisition' => 'İlk İktisap',
                                'change'            => 'Değişiklik',
                            ])
                            ->default('first_acquisition')
                            ->required(),
                    ]),

                Section::make('Konum')
                    ->description('İl/ilçe/belediye .env’den otomatik gelir. Mahalle/cadde/ada-parsel bina bazında.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('city')
                            ->label('İl')
                            ->default(config('property_tax.city'))
                            ->placeholder(config('property_tax.city') ?: 'ERZİNCAN'),
                        TextInput::make('district')
                            ->label('İlçe')
                            ->default(config('property_tax.district'))
                            ->placeholder(config('property_tax.district') ?: 'MERKEZ'),
                        TextInput::make('municipality')
                            ->label('Belediye')
                            ->default(config('property_tax.municipality'))
                            ->placeholder(config('property_tax.municipality') ?: 'MERKEZ-ERZİNCAN')
                            ->columnSpanFull()
                            ->helperText('Beyannamenin “… Belediye Başkanlığına” satırı.'),
                        TextInput::make('neighborhood')->label('Mahalle')->placeholder('HOCABEY'),
                        TextInput::make('cadastral_parcel')->label('Ada / Parsel')->placeholder('880/294'),
                        TextInput::make('street')->label('Cadde / Sokak')->placeholder('1056')->columnSpanFull(),
                    ]),
            ]),
        ]);
    }
}
