<?php

namespace App\Filament\Resources\StockMovements\Schemas;

use App\Models\Party;
use App\Models\Product;
use App\Models\Project;
use App\Support\Forms\MoneyInput;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class StockMovementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::baseComponents())->columns(2);
    }

    /**
     * Ortak alanlar — Yön/Sebep YOK. Hangi düğmeye basıldığı (Mal Girişi /
     * Stok Düzeltme) yönü ve sebebi belirler (cari hareketlerdeki mantık).
     */
    public static function baseComponents(): array
    {
        return [
            Select::make('product_id')
                ->label('Ürün')
                ->options(fn () => Product::active()
                    ->where('type', Product::TYPE_PRODUCT)
                    ->orderBy('name')
                    ->pluck('name', 'id'))
                ->searchable()
                ->preload()
                ->required()
                ->helperText('Stok yalnız Ürün türü katalog kartlarında tutulur.')
                ->columnSpanFull(),

            DatePicker::make('movement_date')
                ->label('Tarih')
                ->default(now())
                ->required(),

            TextInput::make('quantity')
                ->label('Miktar')
                ->numeric()
                ->step(0.01)
                ->minValue(0.01)
                ->required(),

            MoneyInput::make('unit_price', 'Birim Fiyat (alış)')
                ->required(false)
                ->helperText('Opsiyonel — alış maliyeti.'),

            Select::make('party_id')
                ->label('Cari (kimden)')
                ->options(fn () => Party::orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->preload(),

            Select::make('project_id')
                ->label('Şantiye / Proje')
                ->options(fn () => Project::orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->preload()
                ->helperText('Opsiyonel — depo girişinde boş bırakılabilir.'),

            Textarea::make('notes')
                ->label('Not')
                ->rows(2)
                ->columnSpanFull(),
        ];
    }
}
