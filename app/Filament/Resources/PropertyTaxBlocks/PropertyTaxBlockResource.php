<?php

namespace App\Filament\Resources\PropertyTaxBlocks;

use App\Filament\Resources\PropertyTaxBlocks\Pages\EditPropertyTaxBlock;
use App\Filament\Resources\PropertyTaxBlocks\RelationManagers\UnitsRelationManager;
use App\Filament\Resources\PropertyTaxBlocks\Schemas\PropertyTaxBlockForm;
use App\Models\PropertyTaxBlock;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Blok kaynağı — navigasyonda gizli; projenin "Bloklar" listesinden
 * "Daireler" ile açılır. Blok özellikleri + daire yönetimi + beyanname indir.
 */
class PropertyTaxBlockResource extends Resource
{
    protected static ?string $model = PropertyTaxBlock::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Mimar';

    protected static ?string $modelLabel = 'Blok';

    protected static ?string $pluralModelLabel = 'Bloklar';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return config('modules.property_tax');
    }

    public static function form(Schema $schema): Schema
    {
        return PropertyTaxBlockForm::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            UnitsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'edit' => EditPropertyTaxBlock::route('/{record}/edit'),
        ];
    }
}
