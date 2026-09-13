<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use App\Models\Unit;
use App\Support\Forms\MoneyInput;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // Stok kapalıyken tür seçimi gösterilmez; kart doğrudan "Hizmet" açılır.
            Radio::make('type')
                ->label('Tür')
                ->options(Product::TYPE_LABELS)
                ->default(fn () => config('modules.stock') ? Product::TYPE_PRODUCT : Product::TYPE_SERVICE)
                ->inline()
                ->required()
                ->live()
                ->dehydrated(true)
                ->visible(fn () => (bool) config('modules.stock'))
                ->helperText('Ürün stoktan takip edilir; Hizmet stoksuzdur (sadece varsayılan fiyat).')
                ->columnSpanFull(),

            TextInput::make('name')
                ->label('Ad')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            TextInput::make('category')
                ->label('Kategori')
                ->maxLength(255),

            Select::make('unit_id')
                ->label('Varsayılan birim')
                ->options(fn () => Unit::orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->preload(),

            MoneyInput::make('default_price', 'Varsayılan fiyat')
                ->required(false)
                ->helperText('Teslimat/satışta seçilince otomatik gelir; düzenlenebilir.'),

            Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),

            Textarea::make('notes')
                ->label('Not')
                ->rows(2)
                ->columnSpanFull(),
        ])->columns(2);
    }
}
