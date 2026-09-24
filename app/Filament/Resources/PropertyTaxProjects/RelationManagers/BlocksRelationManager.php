<?php

namespace App\Filament\Resources\PropertyTaxProjects\RelationManagers;

use App\Filament\Resources\PropertyTaxBlocks\PropertyTaxBlockResource;
use App\Filament\Resources\PropertyTaxBlocks\Schemas\PropertyTaxBlockForm;
use App\Models\PropertyTaxBlock;
use App\Services\PropertyTax\DeclarationExporter;
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
                TextColumn::make('land_area')->label('Arsa Alanı')->numeric(2)->suffix(' m²'),
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

                Action::make('formatliPdf')
                    ->label('Formatlı PDF')
                    ->icon(Heroicon::OutlinedDocumentCheck)
                    ->color('danger')
                    ->url(fn (PropertyTaxBlock $record) => route('property-tax.declaration.formatli-pdf', $record), shouldOpenInNewTab: true),

                Action::make('pdf')
                    ->label('PDF')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->color('info')
                    ->url(fn (PropertyTaxBlock $record) => route('property-tax.declaration.pdf', $record), shouldOpenInNewTab: true),

                Action::make('download')
                    ->label('Excel')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('success')
                    ->action(fn (PropertyTaxBlock $record) => static::downloadDeclaration($record)),

                EditAction::make()->label('Düzenle'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function downloadDeclaration(PropertyTaxBlock $block)
    {
        $exporter = new DeclarationExporter();
        $path = $exporter->export($block);

        return response()->download($path, $exporter->downloadName($block))->deleteFileAfterSend();
    }
}
