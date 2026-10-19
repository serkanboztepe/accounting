<?php

namespace App\Filament\Resources\Purchases;

use App\Filament\Resources\Purchases\Pages\EditPurchase;
use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Filament\Resources\Purchases\Schemas\PurchaseForm;
use App\Filament\Resources\Purchases\Tables\PurchasesTable;
use App\Models\Purchase;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Alışlar (tezgah) — "Alışlar ekranı" modülü (purchases) açık firmada. Stoklu alış: kalemler stoğa girer,
 * cariden alındıysa cariye borç yazılır, peşinse alış + ödeme birlikte. Caride basit "Alış Yaptım" ayrıdır.
 */
class PurchaseResource extends Resource
{
    protected static ?string $model = Purchase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'Alış-Satış';

    protected static ?string $navigationLabel = 'Alışlar';

    protected static ?string $modelLabel = 'Alış';

    protected static ?string $pluralModelLabel = 'Alışlar';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return (bool) config('modules.purchases');
    }

    public static function form(Schema $schema): Schema
    {
        return PurchaseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurchasesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchases::route('/'),
            'edit'  => EditPurchase::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ReturnsRelationManager::class,
        ];
    }
}
