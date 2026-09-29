<?php

namespace App\Filament\Resources\Sales\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

class SaleReturnForm
{
    /**
     * "İade Al" modal bileşenleri — hem Direkt Satış listesinde hem Cari Ekstresi'nde
     * kullanılır. fillForm ($record->returnFormLines()) + Sale::processReturn() çağıran taraf yapar.
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    public static function components(): array
    {
        return [
            DatePicker::make('return_date')
                ->label('İade Tarihi')
                ->required(),

            Repeater::make('lines')
                ->label('İade edilecek ürünler')
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->columns(5)
                ->columnSpanFull()
                ->schema([
                    Hidden::make('product_id'),
                    Hidden::make('unit_price'),
                    Hidden::make('remaining'),

                    TextInput::make('product_label')
                        ->label('Ürün')
                        ->disabled()
                        ->columnSpan(2),

                    TextInput::make('sold')
                        ->label('Satılan')
                        ->disabled(),

                    TextInput::make('returned_before')
                        ->label('Önce iade')
                        ->disabled(),

                    TextInput::make('return_qty')
                        ->label('İade')
                        ->numeric()
                        ->step(0.01)
                        ->default(0)
                        ->minValue(0)
                        ->maxValue(fn (Get $get) => (float) $get('remaining'))
                        ->helperText(fn (Get $get) => 'Kalan: ' . number_format((float) $get('remaining'), 2, ',', '.')),
                ]),

            Textarea::make('return_notes')
                ->label('İade notu')
                ->rows(2)
                ->columnSpanFull(),
        ];
    }

    /**
     * Cari Ekstresi'ndeki üst "İade Al" — müşterinin TÜM satışlarının iade edilebilir kalemleri
     * tek listede (her satır kendi satışına + fiyatına bağlı; kaydederken satışa göre gruplanır).
     * fillForm: $party->returnableLines().
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    public static function partyComponents(): array
    {
        return [
            DatePicker::make('return_date')
                ->label('İade Tarihi')
                ->required(),

            Repeater::make('lines')
                ->label('İade edilecek ürünler')
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->columns(6)
                ->columnSpanFull()
                ->schema([
                    Hidden::make('product_id'),
                    Hidden::make('sale_id'),
                    Hidden::make('unit_price'),
                    Hidden::make('remaining'),

                    TextInput::make('product_label')->label('Ürün')->disabled()->columnSpan(2),
                    TextInput::make('sale_ref')->label('Satış')->disabled(),
                    TextInput::make('sale_date')->label('Tarih')->disabled(),
                    TextInput::make('unit_price_label')->label('Fiyat')->disabled(),

                    TextInput::make('return_qty')
                        ->label('İade')
                        ->numeric()
                        ->step(0.01)
                        ->default(0)
                        ->minValue(0)
                        ->maxValue(fn (Get $get) => (float) $get('remaining'))
                        ->helperText(fn (Get $get) => 'Kalan: ' . number_format((float) $get('remaining'), 2, ',', '.')),
                ]),

            Textarea::make('return_notes')
                ->label('İade notu')
                ->rows(2)
                ->columnSpanFull(),
        ];
    }
}
