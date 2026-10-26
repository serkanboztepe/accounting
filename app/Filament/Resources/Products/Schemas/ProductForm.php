<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use App\Models\Unit;
use App\Support\Forms\MoneyInput;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use App\Support\StockUsage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                ->default(fn () => \App\Support\Features::stock() ? Product::TYPE_PRODUCT : Product::TYPE_SERVICE)
                ->inline()
                ->required()
                ->live()
                ->dehydrated(true)
                ->visible(fn () => (bool) \App\Support\Features::stock())
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
                ->label(fn () => \App\Support\Features::stockPurchases() ? 'Satış fiyatı' : 'Varsayılan fiyat')
                ->required(false)
                ->helperText('Teslimat/satışta seçilince otomatik gelir; düzenlenebilir.'),

            // Alışlar ekranı açık firmada: alışta gelen fiyat (her alışta kendiliğinden güncellenir).
            MoneyInput::make('purchase_price', 'Alış fiyatı')
                ->required(false)
                ->visible(fn () => (bool) \App\Support\Features::stockPurchases())
                ->helperText('Alışta fiyat yazılmazsa bu kullanılır; her alışta son fiyatla güncellenir.'),

            Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),

            // Stok uyarısı her stoklu üründe (lastikçi: "bu ebattan 4 kalınca haber ver"); otomatik düşüş
            // isteğe bağlı (fırın: "günde 3 çuval un kullanıyorum").
            Section::make('Stok uyarısı')
                ->description('Stok azalınca sabah 08:00\'de WhatsApp\'tan haber verilir.')
                ->visible(fn (Get $get) => \App\Support\Features::stock() && $get('type') === Product::TYPE_PRODUCT)
                ->schema([
                    TextInput::make('reorder_level')
                        ->label('Kaç tane kalınca haber verilsin?')
                        ->numeric()
                        ->minValue(0)
                        ->live(onBlur: true)
                        ->suffix(fn (Get $get) => Unit::find($get('unit_id'))?->name)
                        ->helperText(fn (Get $get) => $get('reorder_level') === null || $get('reorder_level') === ''
                            ? 'Boş bırakırsan haber verilmez.'
                            : ($get('auto_usage') && (float) $get('daily_usage') > 0
                                ? 'Yaklaşık ' . (int) floor((float) $get('reorder_level') / ((float) $get('daily_usage') / max(1, (int) ($get('usage_every_days') ?: 1)))) . ' günlük kaldığında haber verilir.'
                                : 'Stok bu sayıya inince haber verilir.')),

                    Placeholder::make('stock_info')
                        ->label('Şu anki stok')
                        ->visible(fn (?Product $record) => (bool) $record)
                        ->content(function (Get $get, ?Product $record) {
                            $stock = $record->currentStock();
                            $text = StockUsage::quantity($stock) . ' ' . ($record->unit?->name ?? '');
                            $daily = (float) $get('daily_usage') / max(1, (int) ($get('usage_every_days') ?: 1));

                            return $get('auto_usage') && $daily > 0
                                ? $text . ' — yaklaşık ' . (int) floor(max(0, $stock) / $daily) . ' gün yeter'
                                : $text;
                        }),

                    Toggle::make('auto_usage')
                        ->label('Otomatik stoktan düş')
                        ->live()
                        ->helperText(fn (?Product $record) => $record?->auto_usage
                            ? 'Açık — her sabah günlük miktar stoktan düşülüyor.'
                            : 'Her gün aynı miktarı kullanıyorsan aç (ör. fırında un). Açtığın günden sonraki sabah başlar.')
                        ->columnSpanFull(),

                    TextInput::make('daily_usage')
                        ->label('Günde kaç tane kullanılıyor?')
                        ->numeric()
                        ->minValue(0.01)
                        ->required(fn (Get $get) => (bool) $get('auto_usage'))
                        ->visible(fn (Get $get) => (bool) $get('auto_usage'))
                        ->live(onBlur: true)
                        ->suffix(fn (Get $get) => Unit::find($get('unit_id'))?->name),

                    // "3 günde 1 adet" (Oktay 18.10) — boş/1 = her gün.
                    TextInput::make('usage_every_days')
                        ->label('Kaç günde bir düşülsün?')
                        ->helperText('Her gün ise boş bırak. Ör. "3 günde 1 adet" → yukarıya 1, buraya 3.')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(365)
                        ->visible(fn (Get $get) => (bool) $get('auto_usage'))
                        ->live(onBlur: true)
                        ->suffix('günde bir'),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Textarea::make('notes')
                ->label('Not')
                ->rows(2)
                ->columnSpanFull(),
        ])->columns(2);
    }
}
