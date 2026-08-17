<?php

namespace App\Filament\Resources\LandShareStudies;

use App\Filament\Resources\LandShareStudies\Pages\CreateLandShareStudy;
use App\Filament\Resources\LandShareStudies\Pages\ListLandShareStudies;
use App\Filament\Resources\LandShareStudies\Pages\StudyBuilder;
use App\Filament\Resources\LandShareStudies\Pages\StudyLedger;
use App\Filament\Resources\LandShareStudies\Tables\LandShareStudiesTable;
use App\Models\LandShareStudy;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class LandShareStudyResource extends Resource
{
    protected static ?string $model = LandShareStudy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Kat Karşılığı';

    protected static ?string $navigationLabel = 'Hisse Dağıtım Çalışmaları';

    protected static ?string $modelLabel = 'Hisse Dağıtım Çalışması';

    protected static ?string $pluralModelLabel = 'Hisse Dağıtım Çalışmaları';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Minimal başlatıcı form — sadece çalışmayı oluşturmaya yeter.
     * Asıl veri girişi (bloklar, hissedarlar, atama, cetvel) sihirbazda (build).
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('project_id')
                ->label('Proje')
                ->relationship('project', 'name')
                ->searchable()->preload()->required()
                ->columnSpanFull(),

            TextInput::make('name')
                ->label('Çalışma Adı')
                ->required()
                ->placeholder('298 Ada / 8 Parsel — v1')
                ->columnSpanFull(),

            TextInput::make('ada')->label('Ada'),
            TextInput::make('parsel')->label('Parsel'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return LandShareStudiesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLandShareStudies::route('/'),
            'create' => CreateLandShareStudy::route('/create'),
            'build' => StudyBuilder::route('/{record}/olustur'),
            'ledger' => StudyLedger::route('/{record}/cetvel'),
        ];
    }
}
