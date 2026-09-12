<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Models\ContractDelivery;
use App\Models\ContractItem;
use App\Models\Product;
use App\Models\Project;
use App\Models\Unit;
use App\Support\Forms\MoneyInput;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Sözleşme Kalemleri';

    public function form(Schema $schema): Schema
    {
        $contractTotalLocked = (float) $this->getOwnerRecord()->total_amount > 0;

        return $schema
            ->components([
                Select::make('product_id')
                    ->label('Katalog (Hizmet / Ürün)')
                    ->options(fn () => Product::active()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                        if (! $state) {
                            return;
                        }
                        $product = Product::find($state);
                        if (! $product) {
                            return;
                        }
                        if (blank($get('description'))) {
                            $set('description', $product->name);
                        }
                        if ($product->unit_id) {
                            $set('unit_id', $product->unit_id);
                        }
                        if ($product->default_price !== null) {
                            $set('unit_price', Money::format((float) $product->default_price));
                            $qty = (float) $get('quantity');
                            if ($qty) {
                                $set('amount', Money::format($qty * (float) $product->default_price));
                            }
                        }
                    })
                    ->createOptionForm([
                        Radio::make('type')
                            ->label('Tür')
                            ->options(Product::TYPE_LABELS)
                            ->default(Product::TYPE_PRODUCT)
                            ->inline()
                            ->required(),
                        TextInput::make('name')->label('Ad')->required()->maxLength(255),
                        Select::make('unit_id')
                            ->label('Birim')
                            ->options(fn () => Unit::orderBy('name')->pluck('name', 'id'))
                            ->searchable(),
                        MoneyInput::make('default_price', 'Varsayılan fiyat')->required(false),
                    ])
                    ->createOptionUsing(fn (array $data) => Product::create($data)->getKey())
                    ->helperText('Katalogdan seç — ad/birim/fiyat otomatik gelir. Yoksa + ile yeni kart aç ya da aşağıya serbest yaz.')
                    ->columnSpanFull(),

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
                    ->disabled($contractTotalLocked)
                    ->helperText($contractTotalLocked
                        ? 'Sözleşmede toplam tutar girildiği için bu alan kilitli.'
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
        $contract = $this->getOwnerRecord();
        $total    = $contract->reportableTotal();
        $isSub    = $contract->isSubcontract();

        $unitSuffix = fn ($record) => ' ' . ($record->unit?->code ?? $record->unit?->name ?? '');

        return $table
            ->recordTitleAttribute('description')
            ->description(sprintf('Sözleşme Toplamı: %s ₺', Money::format($total)))
            ->columns([
                TextColumn::make('description')
                    ->label('Kalem Adı')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('quantity')
                    ->label('Sözleşme')
                    ->numeric(2)
                    ->suffix($unitSuffix),

                TextColumn::make('delivered_quantity')
                    ->label($isSub ? 'Yapılan' : 'Teslim Edilen')
                    ->getStateUsing(fn (ContractItem $record) => $record->deliveredQuantity())
                    ->numeric(2)
                    ->suffix($unitSuffix)
                    ->color('success'),

                TextColumn::make('remaining_quantity')
                    ->label('Kalan')
                    ->getStateUsing(fn (ContractItem $record) => $record->remainingQuantity())
                    ->numeric(2)
                    ->suffix($unitSuffix),

                TextColumn::make('progress')
                    ->label('İlerleme')
                    ->getStateUsing(function (ContractItem $record): ?string {
                        $qty = (float) $record->quantity;
                        if ($qty <= 0) {
                            return null;
                        }
                        $percent = ($record->deliveredQuantity() / $qty) * 100;

                        return number_format($percent, 0, ',', '.') . '%';
                    })
                    ->badge()
                    ->color(function (ContractItem $record): string {
                        $qty = (float) $record->quantity;
                        if ($qty <= 0) {
                            return 'gray';
                        }
                        $percent = ($record->deliveredQuantity() / $qty) * 100;

                        return match (true) {
                            $percent >= 100 => 'success',
                            $percent >= 50  => 'warning',
                            $percent > 0    => 'info',
                            default         => 'gray',
                        };
                    })
                    ->placeholder('—'),

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
                Action::make('addDelivery')
                    ->label($isSub ? 'Hakediş Ekle' : 'Teslimat Ekle')
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->modalHeading($isSub ? 'Hakediş Oluştur' : 'Teslimat Oluştur')
                    ->modalSubmitActionLabel('Kaydet')
                    ->visible(fn (ContractItem $record) => $record->quantity === null
                        || $record->remainingQuantity() > 0)
                    ->fillForm(function (ContractItem $record) use ($contract) {
                        $remaining = $record->quantity !== null
                            ? $record->remainingQuantity()
                            : null;
                        $unitPrice = (float) $record->unit_price;

                        return [
                            'delivery_date' => now()->toDateString(),
                            'project_id'    => $contract->project_id,
                            'unit_id'       => $record->unit_id,
                            'unit_price'    => Money::format($unitPrice),
                            'quantity'      => $remaining !== null
                                ? number_format($remaining, 2, '.', '')
                                : null,
                            'amount'        => $remaining !== null
                                ? Money::format($remaining * $unitPrice)
                                : null,
                        ];
                    })
                    ->schema([
                        DatePicker::make('delivery_date')
                            ->label($isSub ? 'Hakediş Tarihi' : 'Teslimat Tarihi')
                            ->required(),

                        Select::make('project_id')
                            ->label('Şantiye / Proje')
                            ->options(fn () => Project::orderBy('name')->pluck('name', 'id')->toArray())
                            ->searchable()
                            ->required()
                            ->helperText('Sözleşmenin projesi dışında bir şantiyeye teslimat yapılıyorsa değiştirebilirsin (çapraz proje).')
                            ->visible(! $isSub),

                        TextInput::make('quantity')
                            ->label('Miktar')
                            ->numeric()
                            ->step(0.01)
                            ->required()
                            ->minValue(0.01)
                            ->maxValue(function (?ContractItem $record) {
                                if (! $record || $record->quantity === null) {
                                    return null;
                                }

                                return $record->remainingQuantity();
                            })
                            ->helperText(function (?ContractItem $record) {
                                if (! $record || $record->quantity === null) {
                                    return null;
                                }
                                $remaining = $record->remainingQuantity();
                                $unit = $record->unit?->code ?? $record->unit?->name ?? '';

                                return sprintf(
                                    'Kalan: %s %s',
                                    number_format($remaining, 2, ',', '.'),
                                    $unit,
                                );
                            })
                            ->live(debounce: 400)
                            ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                $price = Money::parse($get('unit_price'));
                                $qty   = (float) $state;
                                if ($qty && $price) {
                                    $set('amount', Money::format($qty * $price));
                                }
                            }),

                        Select::make('unit_id')
                            ->label('Birim')
                            ->relationship('unit', 'name')
                            ->searchable()
                            ->preload(),

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
                            ->required()
                            ->live(debounce: 400)
                            ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                $amount = Money::parse($state);
                                $qty    = (float) $get('quantity');
                                if ($amount && $qty) {
                                    $set('unit_price', Money::format($amount / $qty));
                                }
                            }),

                        Textarea::make('notes')
                            ->label('Notlar')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->action(function (array $data, ContractItem $record) use ($contract, $isSub) {
                        ContractDelivery::create([
                            'contract_id'      => $contract->id,
                            'contract_item_id' => $record->id,
                            'project_id'       => $isSub ? $contract->project_id : ($data['project_id'] ?? $contract->project_id),
                            'delivery_date'    => $data['delivery_date'],
                            'unit_id'          => $data['unit_id'] ?? null,
                            'quantity'         => $data['quantity'],
                            'unit_price'       => Money::store($data['unit_price'] ?? 0),
                            'amount'           => Money::store($data['amount']),
                            'notes'            => $data['notes'] ?? null,
                        ]);
                    }),

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
