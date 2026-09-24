<?php

namespace App\Filament\Resources\PropertyTaxProjects\RelationManagers;

use App\Filament\Resources\PropertyTaxBlocks\PropertyTaxBlockResource;
use App\Filament\Resources\PropertyTaxBlocks\Schemas\PropertyTaxBlockForm;
use App\Models\PropertyTaxBlock;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BlocksRelationManager extends RelationManager
{
    protected static string $relationship = 'blocks';

    protected static ?string $title = 'Bloklar';

    public function form(Schema $schema): Schema
    {
        return PropertyTaxBlockForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('Blok')->searchable(),
                TextColumn::make('units_count')
                    ->label('Daire')
                    ->counts('units')
                    ->badge()
                    ->color('info'),
                TextColumn::make('construction_type')->label('İnşaat')->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()->label('Blok Ekle'),
            ])
            ->recordActions([
                Action::make('manageUnits')
                    ->label('Daireler')
                    ->icon(Heroicon::OutlinedBuildingOffice2)
                    ->url(fn (PropertyTaxBlock $record) => PropertyTaxBlockResource::getUrl('edit', ['record' => $record])),

                EditAction::make()->label('Düzenle'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
