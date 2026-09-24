<?php

namespace App\Filament\Resources\PropertyTaxBlocks\RelationManagers;

use App\Models\PropertyTaxTaxpayer;
use App\Models\PropertyTaxUnit;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UnitsRelationManager extends RelationManager
{
    protected static string $relationship = 'units';

    protected static ?string $title = 'Daireler';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Daire')
                ->columns(2)
                ->schema([
                    TextInput::make('unit_no')
                        ->label('Daire No')
                        ->required(),
                    TextInput::make('area')
                        ->label('Dıştan Dışa Yüzölçümü (m²)')
                        ->numeric(),
                    Select::make('floor_no')
                        ->label('Kat')
                        ->options(PropertyTaxUnit::floorOptions())
                        ->default(0)
                        ->helperText('Zemin en alt kat.'),
                    TextInput::make('floor_position')
                        ->label('Kattaki Sıra')
                        ->numeric()
                        ->helperText('1 = sol, 2 = sağ…'),
                    TextInput::make('land_share_numerator')
                        ->label('Arsa Payı — Pay')
                        ->numeric()
                        ->placeholder('boş = bloktan devralır')
                        ->helperText('Ör. 5/120 için buraya 5.'),
                    TextInput::make('land_share_denominator')
                        ->label('Arsa Payı — Payda')
                        ->numeric()
                        ->placeholder('boş = bloktan devralır')
                        ->helperText('Ör. 5/120 için buraya 120.'),
                ]),

            Section::make('Mükellef Atamaları (Hisse)')
                ->description('Bu daireyi paylaşan mükellef(ler) ve hisseleri. Tam sahiplik için pay=payda (ör. 1/1). Beyanname her mükellef için ayrı üretilir.')
                ->schema([
                    Repeater::make('allocations')
                        ->hiddenLabel()
                        ->relationship()
                        ->addActionLabel('Mükellef Ata')
                        ->columns(3)
                        ->schema([
                            Select::make('property_tax_taxpayer_id')
                                ->label('Mükellef')
                                ->options(fn () => PropertyTaxTaxpayer::query()
                                    ->where('property_tax_project_id', $this->getOwnerRecord()->property_tax_project_id)
                                    ->orderBy('sort_order')->orderBy('id')
                                    ->get()->mapWithKeys(fn ($t) => [$t->id => $t->fullName()]))
                                ->required()
                                ->columnSpan(1),
                            TextInput::make('pay')->label('Hisse Pay')->numeric()->default(1)->required(),
                            TextInput::make('payda')->label('Hisse Payda')->numeric()->default(1)->required(),
                        ]),
                ]),

            Section::make('Bloktan Farklıysa (İsteğe Bağlı)')
                ->description('Boş bırakılırsa blok değerleri kullanılır. Sadece bu daire farklıysa doldur (ör. zemin dükkan).')
                ->collapsed()
                ->columns(2)
                ->schema([
                    TextInput::make('usage_type')->label('Kullanış Şekli')->placeholder('bloktan devralır'),
                    TextInput::make('construction_class')->label('İnşaat Sınıfı')->placeholder('bloktan devralır'),
                    TextInput::make('share_ratio')->label('Hisse Oranı')->placeholder('bloktan devralır'),
                    TextInput::make('neighborhood')->label('Mahalle')->placeholder('projeden devralır'),
                    TextInput::make('street')->label('Cadde/Sokak')->placeholder('projeden devralır'),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('unit_no')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('unit_no')->label('Daire')->sortable(),
                TextColumn::make('floor_no')->label('Kat')->sortable()
                    ->getStateUsing(fn (PropertyTaxUnit $record) => $record->floorLabel()),
                TextColumn::make('floor_position')->label('Sıra'),
                TextColumn::make('area')->label('Yüzölçümü')->numeric(2)->suffix(' m²'),
                TextColumn::make('land_share')
                    ->label('Arsa Payı')
                    ->getStateUsing(fn (PropertyTaxUnit $record) => $record->landShareRatioText() ?? '—'),
                TextColumn::make('taxpayers')
                    ->label('Mükellef(ler)')
                    ->getStateUsing(fn (PropertyTaxUnit $record) => $record->allocations
                        ->map(fn ($a) => $a->taxpayer?->fullName().' ('.$a->shareText().')')
                        ->filter()->implode(', ') ?: '— atanmadı —'),
                TextColumn::make('usage_type')
                    ->label('Kullanış')
                    ->getStateUsing(fn (PropertyTaxUnit $record) => $record->effectiveUsageType())
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()->label('Daire Ekle'),
            ])
            ->recordActions([
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
