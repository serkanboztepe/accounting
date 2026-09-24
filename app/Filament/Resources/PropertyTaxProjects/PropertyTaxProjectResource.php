<?php

namespace App\Filament\Resources\PropertyTaxProjects;

use App\Filament\Resources\PropertyTaxProjects\Pages\CreatePropertyTaxProject;
use App\Filament\Resources\PropertyTaxProjects\Pages\EditPropertyTaxProject;
use App\Filament\Resources\PropertyTaxProjects\Pages\ListPropertyTaxProjects;
use App\Filament\Resources\PropertyTaxProjects\RelationManagers\BlocksRelationManager;
use App\Filament\Resources\PropertyTaxProjects\Schemas\PropertyTaxProjectForm;
use App\Filament\Resources\PropertyTaxProjects\Tables\PropertyTaxProjectsTable;
use App\Models\PropertyTaxProject;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class PropertyTaxProjectResource extends Resource
{
    protected static ?string $model = PropertyTaxProject::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static string|UnitEnum|null $navigationGroup = 'Mimar';

    protected static ?string $navigationLabel = 'Emlak Beyanı';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Emlak Beyanı Projesi';

    protected static ?string $pluralModelLabel = 'Emlak Beyanı Projeleri';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return config('modules.property_tax');
    }

    public static function form(Schema $schema): Schema
    {
        return PropertyTaxProjectForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PropertyTaxProjectsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            BlocksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPropertyTaxProjects::route('/'),
            'create' => CreatePropertyTaxProject::route('/create'),
            'edit' => EditPropertyTaxProject::route('/{record}/edit'),
        ];
    }
}
