<?php

namespace App\Filament\Resources\PropertyTaxProjects\RelationManagers;

use App\Models\PropertyTaxTaxpayer;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Proje mükellefleri (paylı mülkiyet). Her mükellef için ayrı beyanname üretilir.
 */
class TaxpayersRelationManager extends RelationManager
{
    protected static string $relationship = 'taxpayers';

    protected static ?string $title = 'Mükellefler';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Mükellef')
                ->columns(2)
                ->schema([
                    TextInput::make('surname')
                        ->label('Adı Soyadı / Ünvanı')
                        ->required()
                        ->placeholder('ABDULLAH UÇAR')
                        ->columnSpanFull(),
                    TextInput::make('tax_id')->label('T.C. / Vergi Kimlik No'),
                    TextInput::make('property_registry_no')->label('Emlak Vergisi Sicil No'),
                    TextInput::make('phone_area_code')->label('Telefon Alan Kodu')->placeholder('553'),
                    TextInput::make('phone')->label('Telefon'),
                    TextInput::make('email')->label('E-posta')->email(),
                    Select::make('filer_role')
                        ->label('Bildirimi Veren Sıfat')
                        ->options([
                            'taxpayer' => 'Mükellef',
                            'proxy'    => 'Kanuni Temsilci / Vekil',
                        ])
                        ->default('taxpayer')
                        ->required(),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('surname')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('surname')
                    ->label('Adı Soyadı / Ünvanı')
                    ->formatStateUsing(fn ($state, $record) => trim($state.' '.$record->first_name))
                    ->searchable(),
                TextColumn::make('tax_id')->label('T.C. / VKN'),
                TextColumn::make('units_count')
                    ->label('Daire')
                    ->counts('units')
                    ->badge()
                    ->color('info'),
                TextColumn::make('phone')->label('Telefon')->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()->label('Mükellef Ekle'),
            ])
            ->recordActions([
                Action::make('formatliPdf')
                    ->label('Formatlı PDF')
                    ->icon(Heroicon::OutlinedDocumentCheck)
                    ->color('danger')
                    ->url(fn (PropertyTaxTaxpayer $record) => route('property-tax.taxpayer.formatli-pdf', $record), shouldOpenInNewTab: true),
                Action::make('pdf')
                    ->label('PDF')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->color('info')
                    ->url(fn (PropertyTaxTaxpayer $record) => route('property-tax.taxpayer.pdf', $record), shouldOpenInNewTab: true),
                Action::make('excel')
                    ->label('Excel')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('success')
                    ->url(fn (PropertyTaxTaxpayer $record) => route('property-tax.taxpayer.excel', $record), shouldOpenInNewTab: true),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
