<?php

namespace App\Support\Forms;

use App\Models\Product;
use App\Models\Unit;
use App\Support\Money;
use App\Support\StockUsage;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Satışlar / Alışlar tezgahındaki kalem satırları (ürün · miktar · birim fiyat · tutar). Ürün de hizmet de
 * seçilebilir (lastikçi: lastik + montaj tek satışta); stoklu üründe "stokta 5 → 2" ipucu görünür.
 * Kalem zorunlu değil — ürünsüz tutar (toplu ciro) ayrı alandan girilir.
 */
class TradeLines
{
    /** @param  string  $direction  'out' satış (stok düşer) · 'in' alış (stok artar) */
    public static function repeater(string $direction = 'out'): Repeater
    {
        return Repeater::make('lines')
            ->label('Ürünler')
            ->addActionLabel('Ürün ekle')
            ->defaultItems(0)
            ->columns(4)
            ->columnSpanFull()
            ->live()
            ->schema([
                Select::make('product_id')
                    ->label('Ürün / hizmet')
                    ->options(fn () => Product::active()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, $state) use ($direction) {
                        $product = $state ? Product::find($state) : null;
                        // Alışta satış fiyatı DEĞİL alış fiyatı (son alış / karttaki alış fiyatı).
                        $price = $product ? ($direction === 'in' ? $product->buyPrice() : $product->default_price) : null;
                        if ($price !== null) {
                            $set('unit_price', Money::format((float) $price));
                            self::recalc($get, $set);
                        }
                    })
                    ->helperText(fn (Get $get) => self::stockHint($get('product_id'), (float) $get('quantity'), $direction))
                    // Listede yoksa pencereden çıkmadan kart aç (kullanıcı 21.10: Akkuş'ta un kartı yoktu, alım stoğa giremiyordu).
                    ->createOptionForm([
                        TextInput::make('name')->label('Ürün adı')->required()->maxLength(255),
                        Radio::make('type')->label('Tür')->options(Product::TYPE_LABELS)->default(Product::TYPE_PRODUCT)->inline()->required(),
                        Select::make('unit_id')->label('Birim')
                            ->options(fn () => Unit::orderBy('name')->pluck('name', 'id'))
                            ->searchable(),
                        MoneyInput::make('price', $direction === 'in' ? 'Alış fiyatı' : 'Satış fiyatı')->required(false),
                    ])
                    ->createOptionModalHeading('Yeni ürün kartı')
                    ->createOptionUsing(fn (array $data): int => self::createProduct($data, $direction))
                    ->columnSpan(2),

                TextInput::make('quantity')
                    ->label('Miktar')
                    ->numeric()
                    ->minValue(0.01)
                    ->default(1)
                    ->required()
                    ->live(debounce: 400)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalc($get, $set)),

                MoneyInput::make('unit_price', 'Birim fiyat')
                    ->required(false)
                    ->live(debounce: 400)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalc($get, $set)),

                MoneyInput::make('amount', 'Tutar')
                    ->required(false)
                    ->live(debounce: 400)
                    ->columnSpan(2),
            ]);
    }

    /**
     * "+ Yeni ürün": aynı adlı kart varsa (büyük/küçük harf farkı dahil) yenisi açılmaz, o seçilir. Fiyat alışta alış fiyatına,
     * satışta satış fiyatına yazılır — seçilince satıra gelir.
     */
    public static function createProduct(array $data, string $direction): int
    {
        $name = trim((string) $data['name']);
        $existing = Product::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if ($existing) {
            return $existing->getKey();
        }
        $price = Money::parse($data['price'] ?? null);

        return Product::create([
            'name'           => $name,
            'type'           => $data['type'] ?? Product::TYPE_PRODUCT,
            'unit_id'        => $data['unit_id'] ?? null,
            'is_active'      => true,
            'purchase_price' => $direction === 'in' && $price > 0 ? $price : null,
            'default_price'  => $direction === 'out' && $price > 0 ? $price : null,
        ])->getKey();
    }

    /** Kalemler + ürünsüz tutar = toplam (formdaki canlı toplam için). */
    public static function total(?array $lines, mixed $manual): float
    {
        $sum = 0.0;
        foreach ($lines ?? [] as $line) {
            $amount = Money::parse($line['amount'] ?? null);
            if (! $amount && ($price = Money::parse($line['unit_price'] ?? null))) {
                $amount = $price * (float) ($line['quantity'] ?? 0);
            }
            $sum += (float) $amount;
        }

        return round($sum + (float) Money::parse($manual), 2);
    }

    /** Geçerli (ürünü ve miktarı olan) kalem var mı? */
    public static function hasLines(?array $lines): bool
    {
        foreach ($lines ?? [] as $line) {
            if (! empty($line['product_id']) && (float) ($line['quantity'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    private static function recalc(Get $get, Set $set): void
    {
        $price = Money::parse($get('unit_price'));
        $qty = (float) $get('quantity');
        if ($price && $qty) {
            $set('amount', Money::format($qty * $price));
        }
    }

    private static function stockHint(mixed $productId, float $qty, string $direction): ?string
    {
        $product = $productId ? Product::with('unit')->find($productId) : null;
        if (! $product || ! $product->isProduct() || ! config('modules.stock')) {
            return null;
        }

        $now = $product->currentStock();
        $after = $direction === 'in' ? $now + $qty : $now - $qty;
        $unit = $product->unit?->name ? ' ' . $product->unit->name : '';

        return 'Stokta ' . StockUsage::quantity($now) . $unit . ' → ' . StockUsage::quantity($after) . $unit
            . ($direction === 'out' && $after < 0 ? ' ⚠ stok yetmiyor' : '');
    }
}
