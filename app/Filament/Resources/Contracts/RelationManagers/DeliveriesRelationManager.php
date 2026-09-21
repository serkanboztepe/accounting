<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Models\Contract;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use App\Support\Forms\MoneyInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DeliveriesRelationManager extends RelationManager
{
    protected static string $relationship = 'deliveries';

    /** "Oluştur & yeni oluştur" akışında proje seçimini korumak için. */
    public ?int $lastDeliveryProjectId = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return $ownerRecord instanceof Contract && $ownerRecord->isSubcontract()
            ? 'Hakedişler'
            : 'Teslimatlar';
    }

    public function form(Schema $schema): Schema
    {
        $contract = $this->getOwnerRecord();
        $isSub = $contract->isSubcontract();

        $itemOptions = $contract->items->pluck('description', 'id');

        return $schema
            ->components([
                Select::make('contract_item_id')
                    ->label('Kalem')
                    ->options($itemOptions)
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, $state) use ($contract) {
                        if (! $state) return;
                        $item = $contract->items->firstWhere('id', $state);
                        if (! $item) return;
                        $set('unit_id', $item->unit_id);
                        $set('unit_price', Money::format((float) $item->unit_price));
                        $qty = (float) $get('quantity');
                        if ($qty) {
                            $set('amount', Money::format($qty * (float) $item->unit_price));
                        }
                    })
                    ->columnSpanFull(),

                $contract->project_id
                    ? Hidden::make('project_id')
                        ->default($contract->project_id)
                    : Select::make('project_id')
                        ->label('Şantiye / Proje')
                        ->relationship('project', 'name')
                        ->searchable()
                        ->preload()
                        ->default(fn () => $this->lastDeliveryProjectId
                            ?? ($this->tableFilters['project_id']['value'] ?? null))
                        ->helperText('Bu sözleşme birden fazla şantiyeye açık (çapraz proje). Bu ' . ($isSub ? 'hakedişin' : 'teslimatın') . ' hangi şantiyeye ait olduğunu seç.')
                        ->required(),

                DatePicker::make('delivery_date')
                    ->label($isSub ? 'Hakediş Tarihi' : 'Teslimat Tarihi')
                    ->default(now())
                    ->required(),

                TextInput::make('quantity')
                    ->label('Miktar')
                    ->numeric()
                    ->step(0.01)
                    ->required()
                    ->live(debounce: 500)
                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                        $unitPrice = Money::parse($get('unit_price'));
                        $quantity  = (float) $state;
                        if ($quantity && $unitPrice) {
                            $set('amount', Money::format($quantity * $unitPrice));
                        }
                    }),

                Select::make('unit_id')
                    ->label('Birim')
                    ->relationship('unit', 'name')
                    ->searchable()
                    ->preload(),

                MoneyInput::make('unit_price', 'Birim Fiyat')
                    ->live(debounce: 500)
                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                        $unitPrice = Money::parse($state);
                        $quantity  = (float) $get('quantity');
                        if ($unitPrice && $quantity) {
                            $set('amount', Money::format($quantity * $unitPrice));
                        }
                    }),

                MoneyInput::make('amount', 'Tutar (₺)')
                    ->live(debounce: 500)
                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                        $amount   = Money::parse($state);
                        $quantity = (float) $get('quantity');
                        if ($amount && $quantity) {
                            $set('unit_price', Money::format($amount / $quantity));
                        }
                    }),

                Textarea::make('notes')
                    ->label('Notlar')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        $contract = $this->getOwnerRecord();
        $deliveredAmt = $contract->deliveredAmount();
        $isSub = $contract->isSubcontract();

        return $table
            ->recordTitleAttribute('delivery_date')
            ->defaultSort('delivery_date', 'desc')
            ->description(sprintf(
                '%s: %s ₺  (filtreden bağımsız — filtreli toplam için tablo altındaki "Görünen Toplam"a bak)',
                $isSub ? 'Genel Hakediş Tutarı' : 'Genel Teslimat Tutarı',
                Money::format($deliveredAmt),
            ))
            ->columns([
                TextColumn::make('contractItem.description')
                    ->label('Kalem')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('project.name')
                    ->label('Proje')
                    ->searchable()
                    ->sortable()
                    ->visible(! $contract->project_id),

                TextColumn::make('delivery_date')
                    ->label('Tarih')
                    ->date('d.m.Y')
                    ->sortable(),

                TextColumn::make('quantity')
                    ->label('Miktar')
                    ->numeric(2)
                    ->suffix(fn ($record) => ' ' . ($record->unit?->code ?? ''))
                    ->summarize(
                        Sum::make()
                            ->label('Toplam Miktar')
                            ->numeric(2),
                    ),

                TextColumn::make('unit_price')
                    ->label('Birim Fiyat')
                    ->numeric(2)
                    ->suffix(' ₺'),

                TextColumn::make('amount')
                    ->label('Tutar')
                    ->numeric(2)
                    ->suffix(' ₺')
                    ->sortable()
                    ->summarize(
                        Sum::make()
                            ->label('Görünen Toplam')
                            ->formatStateUsing(fn ($state) => Money::format((float) $state) . ' ₺'),
                    ),

                TextColumn::make('notes')
                    ->label('Notlar')
                    ->limit(40),
            ])
            ->groups([
                Group::make('contractItem.description')
                    ->label('Kalem')
                    // Kalemsiz teslimatlar (serbest not) tek grupta toplansın.
                    ->getTitleFromRecordUsing(fn (Model $record): string => $record->contractItem?->description
                        ?: ($record->notes ? \Illuminate\Support\Str::limit($record->notes, 30) : 'Kalemsiz'))
                    ->collapsible(),
            ])
            ->filters(array_values(array_filter([
                $contract->items->isNotEmpty()
                    ? SelectFilter::make('contract_item_id')
                        ->label('Kalem')
                        ->options($contract->items->pluck('description', 'id'))
                        ->placeholder('Tüm Kalemler')
                    : null,

                ! $contract->project_id
                    ? SelectFilter::make('project_id')
                        ->label('Şantiye / Proje')
                        ->relationship('project', 'name')
                        ->searchable()
                        ->preload()
                        ->placeholder('Tüm Şantiyeler')
                    : null,

                Filter::make('delivery_date')
                    ->schema([
                        DatePicker::make('from')->label('Başlangıç Tarihi'),
                        DatePicker::make('until')->label('Bitiş Tarihi'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('delivery_date', '>=', $date))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('delivery_date', '<=', $date))),
            ])))
            ->headerActions([
                CreateAction::make()
                    ->label($isSub ? 'Hakediş Ekle' : 'Teslimat Ekle')
                    ->modalHeading($isSub ? 'Hakediş Oluştur' : 'Teslimat Oluştur')
                    ->after(fn (Model $record) => $this->lastDeliveryProjectId = $record->project_id),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalHeading($isSub ? 'Hakedişi Düzenle' : 'Teslimatı Düzenle'),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}