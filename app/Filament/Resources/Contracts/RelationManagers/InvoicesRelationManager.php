<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Models\ContractDelivery;
use App\Support\Forms\MoneyInput;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    protected static ?string $title = 'Faturalar';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('invoice_number')
                ->label('Fatura No')
                ->maxLength(255),

            DatePicker::make('invoice_date')
                ->label('Fatura Tarihi')
                ->default(now()),

            MoneyInput::make('total_amount', 'Fatura Tutarı')
                ->required(false)
                ->live(),

            FileUpload::make('invoice_path')
                ->label('Fatura PDF')
                ->disk('public')
                ->directory('invoices')
                ->acceptedFileTypes(['application/pdf'])
                ->maxSize(10240)
                ->columnSpanFull(),

            Textarea::make('notes')
                ->label('Notlar')
                ->rows(2)
                ->columnSpanFull(),

            Repeater::make('deliveryAllocations')
                ->label('Teslimatlar')
                ->relationship()
                ->schema([
                    Select::make('delivery_id')
                        ->label('Teslimat')
                        ->options(function ($livewire): array {
                            $contract = $livewire->getOwnerRecord();

                            return ContractDelivery::query()
                                ->where('contract_id', $contract->id)
                                ->with('project')
                                ->orderBy('delivery_date')
                                ->get()
                                ->mapWithKeys(fn ($d) => [
                                    $d->id => sprintf(
                                        '%s – %s – ₺%s',
                                        optional($d->delivery_date)->format('d.m.Y'),
                                        $d->project?->name ?? '—',
                                        Money::format((float) $d->amount)
                                    ),
                                ])
                                ->toArray();
                        })
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (string|int|null $state, Set $set): void {
                            if ($state) {
                                $delivery = ContractDelivery::find($state);
                                // Money::format üretir "233.999,99" → Alpine mask doğru okur
                                $set('amount', $delivery ? Money::format((float) $delivery->amount) : null);
                            }
                        })
                        ->required()
                        ->columnSpan(2),

                    MoneyInput::make('amount')
                        ->required(false)
                        ->live(),
                ])
                ->columns(3)
                ->columnSpanFull()
                ->addActionLabel('Teslimat Ekle')
                ->reorderable(false)
                ->defaultItems(0),

            Placeholder::make('allocation_summary')
                ->label('')
                ->content(function (Get $get): \Illuminate\Support\HtmlString {
                    $invoiceTotal = Money::parse($get('total_amount'));
                    $allocations  = $get('deliveryAllocations') ?? [];
                    $allocated    = collect($allocations)->sum(
                        fn ($item) => is_array($item) ? Money::parse($item['amount'] ?? 0) : 0.0
                    );
                    $diff         = round($invoiceTotal - $allocated, 2);

                    if ($invoiceTotal <= 0 && $allocated <= 0) {
                        return new \Illuminate\Support\HtmlString('');
                    }

                    if (abs($diff) < 0.01) {
                        $html = '<span class="text-sm text-green-600 dark:text-green-400">✓ Tüm tutar dağıtıldı ('
                            . Money::format($allocated) . ' ₺)</span>';
                    } elseif ($diff > 0) {
                        $html = '<span class="text-sm text-orange-500">⚠ '
                            . Money::format($diff) . ' ₺ henüz dağıtılmadı'
                            . ' — Dağıtılan: ' . Money::format($allocated) . ' ₺'
                            . ' / Fatura: ' . Money::format($invoiceTotal) . ' ₺</span>';
                    } else {
                        $html = '<span class="text-sm text-red-500">⚠ Dağıtılan tutar faturadan '
                            . Money::format(abs($diff)) . ' ₺ fazla'
                            . ' — Dağıtılan: ' . Money::format($allocated) . ' ₺'
                            . ' / Fatura: ' . Money::format($invoiceTotal) . ' ₺</span>';
                    }

                    return new \Illuminate\Support\HtmlString($html);
                })
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        $contract   = $this->getOwnerRecord();
        $invoiced   = $contract->invoicedAmount();
        $uninvoiced = $contract->uninvoicedAmount();
        $total      = $contract->reportableTotal();

        $description = $total > 0
            ? sprintf(
                'Faturalanan: %s ₺ / Toplam: %s ₺ — Faturasız Kalan: %s ₺',
                Money::format($invoiced),
                Money::format($total),
                Money::format($uninvoiced),
            )
            : sprintf('Toplam Faturalanan: %s ₺', Money::format($invoiced));

        return $table
            ->recordTitleAttribute('invoice_number')
            ->description($description)
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Fatura No')
                    ->searchable()
                    ->placeholder('-'),

                TextColumn::make('invoice_date')
                    ->label('Fatura Tarihi')
                    ->date('d.m.Y')
                    ->sortable(),

                TextColumn::make('total_amount')
                    ->label('Tutar')
                    ->numeric(2)
                    ->suffix(' ₺')
                    ->sortable(),

                TextColumn::make('deliveryAllocations_count')
                    ->label('Teslimat')
                    ->counts('deliveryAllocations')
                    ->suffix(' teslimat')
                    ->placeholder('—'),

                TextColumn::make('notes')
                    ->label('Notlar')
                    ->limit(40)
                    ->placeholder('-'),
            ])
            ->headerActions([
                CreateAction::make()->label('Fatura Ekle'),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label('Önizle')
                    ->icon('heroicon-o-eye')
                    ->modalContent(fn ($record) => new \Illuminate\Support\HtmlString(
                        '<div style="height:75vh"><iframe src="' . route('invoice.file', ['filename' => basename($record->invoice_path)]) . '" style="width:100%;height:100%;border:none;border-radius:8px;"></iframe></div>'
                    ))
                    ->modalHeading(fn ($record) => 'Fatura: ' . ($record->invoice_number ?? 'Önizleme'))
                    ->modalWidth('5xl')
                    ->visible(fn ($record) => filled($record->invoice_path)),

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
