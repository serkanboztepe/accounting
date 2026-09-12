<?php

namespace App\Filament\Resources\Sales\Schemas;

use App\Models\Party;
use App\Models\Product;
use App\Models\Project;
use App\Support\Forms\MoneyInput;
use App\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class SaleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('party_id')
                ->label('Müşteri (cari)')
                ->options(fn () => Party::orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->preload()
                ->required()
                ->live(),

            Select::make('project_id')
                ->label('Şantiye / Proje')
                // Müşteri seçiliyse ve onun projeleri varsa, listeyi onlarla sınırla.
                ->options(function (Get $get) {
                    $partyId = $get('party_id');
                    $query = Project::query()->orderBy('name');
                    if ($partyId && Project::where('party_id', $partyId)->exists()) {
                        $query->where('party_id', $partyId);
                    }

                    return $query->pluck('name', 'id');
                })
                ->searchable()
                ->preload()
                ->helperText('Opsiyonel — hangi şantiyeye gitti (müşterinin projeleri öne gelir).'),

            DatePicker::make('sale_date')
                ->label('Satış Tarihi')
                ->default(now())
                ->required(),

            Repeater::make('lines')
                ->label('Satış Kalemleri')
                ->dehydrated(false) // model kolonu değil; afterCreate/afterSave'de işlenir
                ->addActionLabel('Ürün Ekle')
                ->columns(4)
                ->columnSpanFull()
                ->minItems(1)
                ->schema([
                    Select::make('product_id')
                        ->label('Ürün')
                        ->options(fn () => Product::active()
                            ->where('type', Product::TYPE_PRODUCT)
                            ->orderBy('name')
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Get $get, Set $set, $state) {
                            $product = $state ? Product::find($state) : null;
                            if ($product && $product->default_price !== null) {
                                $set('unit_price', Money::format((float) $product->default_price));
                                $qty = (float) $get('quantity');
                                if ($qty) {
                                    $set('amount', Money::format($qty * (float) $product->default_price));
                                }
                            }
                        })
                        ->columnSpan(2),

                    TextInput::make('quantity')
                        ->label('Miktar')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0.01)
                        ->required()
                        ->live(debounce: 400)
                        ->afterStateUpdated(function (Get $get, Set $set, $state) {
                            $price = Money::parse($get('unit_price'));
                            $qty = (float) $state;
                            if ($qty && $price) {
                                $set('amount', Money::format($qty * $price));
                            }
                        }),

                    MoneyInput::make('unit_price', 'Birim Fiyat')
                        ->required(false)
                        ->live(debounce: 400)
                        ->afterStateUpdated(function (Get $get, Set $set, $state) {
                            $price = Money::parse($state);
                            $qty = (float) $get('quantity');
                            if ($price && $qty) {
                                $set('amount', Money::format($qty * $price));
                            }
                        }),

                    MoneyInput::make('amount', 'Tutar')
                        ->required(false)
                        ->columnSpan(3),
                ]),

            Textarea::make('notes')
                ->label('Not')
                ->rows(2)
                ->columnSpanFull(),
        ])->columns(3);
    }
}
