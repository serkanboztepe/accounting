<?php

namespace App\Filament\Resources\StockMovements;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\StockMovements\Schemas\StockMovementForm;
use App\Filament\Resources\StockMovements\Tables\StockMovementsTable;
use App\Models\StockMovement;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Ürün & Stok';

    protected static ?string $navigationLabel = 'Stok Hareketleri';

    protected static ?string $modelLabel = 'Stok Hareketi';

    protected static ?string $pluralModelLabel = 'Stok Hareketleri';

    protected static ?int $navigationSort = 5;

    /**
     * Alışlar ekranı açık firmada (Stoklu satış) TAMAMEN salt okunur: her satır bir işlemden doğar
     * (Satışlar, Alışlar, Sayım, otomatik kullanım); düzeltme o işlemden ya da Sayım'dan yapılır.
     * Menüde Ürünler'in altında. Diğer firmalarda eskisi gibi (Mal Girişi / Düzeltme butonları).
     */
    public static function readOnly(): bool
    {
        return (bool) config('modules.purchases');
    }

    public static function getNavigationGroup(): ?string
    {
        return self::readOnly() ? ProductResource::getNavigationGroup() : 'Ürün & Stok';
    }

    public static function getNavigationParentItem(): ?string
    {
        return self::readOnly() ? ProductResource::getNavigationLabel() : null;
    }

    public static function canAccess(): bool
    {
        return config('modules.stock');
    }

    public static function canCreate(): bool
    {
        return ! self::readOnly();
    }

    public static function canEdit(Model $record): bool
    {
        return ! self::readOnly();
    }

    public static function canDelete(Model $record): bool
    {
        return ! self::readOnly();
    }

    public static function canDeleteAny(): bool
    {
        return ! self::readOnly();
    }

    public static function form(Schema $schema): Schema
    {
        return StockMovementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockMovementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockMovements::route('/'),
        ];
    }
}
