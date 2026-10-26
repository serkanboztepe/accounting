<?php

namespace App\Filament\Resources\Projects;

use App\Support\Labels;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\RelationManagers\LandShareStudiesRelationManager;
use App\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Filament\Resources\Projects\Tables\ProjectsTable;
use App\Models\Project;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Genel';

    protected static ?string $navigationLabel = 'Projeler';

    protected static ?string $modelLabel = 'Proje';

    protected static ?string $pluralModelLabel = 'Projeler';

    protected static ?int $navigationSort = 2;

    // Ad firmaya göre: Proje / Şube (hub ayarı) — App\Support\Labels.
    public static function getNavigationLabel(): string
    {
        return Labels::projects();
    }

    public static function getModelLabel(): string
    {
        return Labels::project();
    }

    public static function getPluralModelLabel(): string
    {
        return Labels::projects();
    }

    protected static ?string $recordTitleAttribute = 'name';

    /** Projesiz kurulum (ör. alacak-verecek esnafı): menüde yok, adresle de açılmaz. */
    public static function canAccess(): bool
    {
        return \App\Support\Features::places();
    }

    public static function form(Schema $schema): Schema
    {
        return ProjectForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return array_values(array_filter([
            \App\Support\Features::landShare() ? LandShareStudiesRelationManager::class : null,
        ]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'edit' => EditProject::route('/{record}/edit'),
        ];
    }
}
