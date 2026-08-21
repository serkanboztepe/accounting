<?php

namespace App\Filament\Resources\Quotes\RelationManagers;

use App\Support\Forms\MoneyInput;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Teklif Kalemleri';

    public function form(Schema $schema): Schema
    {
        $totalLocked = (float) $this->getOwnerRecord()->total_amount > 0;

        return $schema
            ->components([
                TextInput::make('description')
                    ->label('Kalem Adı')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                Select::make('unit_id')
                    ->label('Birim')
                    ->relationship('unit', 'name')
                    ->searchable()
                    ->preload(),

                TextInput::make('quantity')
                    ->label('Miktar')
                    ->numeric()
                    ->step(0.01)
                    ->live(debounce: 400)
                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                        $qty   = (float) $state;
                        $price = Money::parse($get('unit_price'));
                        if ($qty && $price) {
                            $set('amount', Money::format($qty * $price));
                        }
                    }),

                MoneyInput::make('unit_price', 'Birim Fiyat')
                    ->live(debounce: 400)
                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                        $price = Money::parse($state);
                        $qty   = (float) $get('quantity');
                        if ($price && $qty) {
                            $set('amount', Money::format($qty * $price));
                        }
                    }),

                MoneyInput::make('amount', 'Tutar')
                    ->required(false)
                    ->disabled($totalLocked)
                    ->helperText($totalLocked
                        ? 'Teklifte toplam tutar girildiği için bu alan kilitli.'
                        : null)
                    ->dehydrateStateUsing(fn ($state) => Money::store($state) ?? '0.00')
                    ->live(debounce: 400)
                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                        $amount = Money::parse($state);
                        $qty    = (float) $get('quantity');
                        if ($amount && $qty) {
                            $set('unit_price', Money::format($amount / $qty));
                        }
                    }),

                Textarea::make('notes')
                    ->label('Not')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        $quote = $this->getOwnerRecord();

        $unitSuffix = fn ($record) => ' ' . ($record->unit?->code ?? $record->unit?->name ?? '');

        return $table
            ->recordTitleAttribute('description')
            ->description(sprintf('Teklif Toplamı: %s ₺', Money::format($quote->reportableTotal())))
            ->columns([
                TextColumn::make('description')
                    ->label('Kalem Adı')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('quantity')
                    ->label('Miktar')
                    ->numeric(2)
                    ->suffix($unitSuffix),

                TextColumn::make('unit_price')
                    ->label('Birim Fiyat')
                    ->numeric(2)
                    ->suffix(' ₺'),

                TextColumn::make('amount')
                    ->label('Tutar')
                    ->numeric(2)
                    ->suffix(' ₺')
                    ->sortable(),

                TextColumn::make('notes')
                    ->label('Not')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Kalem Ekle')
                    ->modalHeading('Yeni Kalem'),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalHeading('Kalemi Düzenle'),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
