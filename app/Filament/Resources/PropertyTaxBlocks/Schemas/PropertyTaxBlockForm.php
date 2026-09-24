<?php

namespace App\Filament\Resources\PropertyTaxBlocks\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Blok (bina) formu. Buradaki "ortak özellikler" tüm dairelerce devralınır —
 * daire başına tekrar girilmez. İnşaat türü, kalorifer, asansör vb. BİR KEZ.
 */
class PropertyTaxBlockForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Blok')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Blok Adı')
                        ->required()
                        ->placeholder('A Blok'),
                    TextInput::make('building_door_no')
                        ->label('Bina / Kapı No')
                        ->placeholder('3'),
                    TextInput::make('land_area')
                        ->label('Arsa Alanı (m²)')
                        ->numeric(),
                    TextInput::make('land_share_numerator')
                        ->label('Varsayılan Arsa Payı — Pay')
                        ->numeric()
                        ->helperText('Tüm daireler aynı arsa payını paylaşıyorsa (ör. 1/120 için 1). Daire kendi payını girerse o geçerli olur.'),
                    TextInput::make('land_share_denominator')
                        ->label('Varsayılan Arsa Payı — Payda')
                        ->numeric()
                        ->helperText('Ör. 1/120 için 120.'),
                ]),

            Section::make('Bina Ortak Özellikleri')
                ->description('Bu alanlar tüm dairelerce devralınır — daire eklerken tekrar sorulmaz. Gerekirse daire bazında ezebilirsiniz.')
                ->columns(2)
                ->schema([
                    TextInput::make('construction_type')
                        ->label('İnşaat Türü')
                        ->default('B.ARME')
                        ->placeholder('B.ARME'),
                    TextInput::make('construction_class')
                        ->label('İnşaat Sınıfı')
                        ->default('3.SINIF')
                        ->placeholder('3.SINIF'),
                    TextInput::make('usage_type')
                        ->label('Kullanış Şekli')
                        ->default('MESKEN')
                        ->placeholder('MESKEN'),
                    TextInput::make('share_ratio')
                        ->label('Hisse Oranı')
                        ->default('TAM')
                        ->placeholder('TAM'),
                    DatePicker::make('construction_completion_date')
                        ->label('İnşaatın Bitim Tarihi'),
                    DatePicker::make('acquisition_date')
                        ->label('İktisap Tarihi'),
                    TextInput::make('restriction_status')
                        ->label('Kısıtlılık Hali')
                        ->default('YOK')
                        ->placeholder('YOK'),
                    TextInput::make('exemption_status')
                        ->label('Muafiyet')
                        ->default('YOK')
                        ->placeholder('YOK'),
                    Toggle::make('has_heating')
                        ->label('Kaloriferli'),
                    Toggle::make('has_elevator')
                        ->label('Asansörlü'),
                ]),
        ]);
    }
}
