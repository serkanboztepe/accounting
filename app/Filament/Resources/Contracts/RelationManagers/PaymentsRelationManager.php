<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Models\ContractPayment;
use App\Support\Forms\MoneyInput;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Ödemeler';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('payment_date')
                    ->label('Tarih')
                    ->default(now()),

                Select::make('payment_type')
                    ->label('Ödeme Yöntemi')
                    ->options(ContractPayment::PAYMENT_TYPES)
                    ->default('bank_transfer')
                    ->required()
                    ->live(),

                Select::make('status')
                    ->label('Ödeme Durumu')
                    ->options(ContractPayment::STATUSES)
                    ->default('unpaid')
                    ->required(),

                MoneyInput::make('amount'),

                // Çek: yalnız vade. Çek kaydı (Çekler modülü) durum senkronuyla otomatik açılır.
                DatePicker::make('check_due_date')
                    ->label('Çek Vadesi')
                    ->default(fn (Get $get) => $get('payment_date'))
                    ->visible(fn (Get $get): bool => $get('payment_type') === 'check')
                    ->required(fn (Get $get): bool => $get('payment_type') === 'check'),

                Textarea::make('notes')
                    ->label('Notlar')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        $contract = $this->getOwnerRecord();

        $totalAmount     = $contract->reportableTotal();
        $paidAmount      = $contract->paidAmount();
        $remainingAmount = $contract->remainingPaymentAmount();

        $description = $totalAmount > 0
            ? sprintf(
                'Ödenen: %s ₺ / Toplam: %s ₺ — Kalan: %s ₺',
                Money::format($paidAmount),
                Money::format($totalAmount),
                Money::format($remainingAmount),
            )
            : sprintf('Toplam Ödenen: %s ₺', Money::format($paidAmount));

        return $table
            ->recordTitleAttribute('payment_date')
            ->description($description)
            ->columns([
                TextColumn::make('payment_date')
                    ->label('Tarih')
                    ->date('d.m.Y')
                    ->sortable(),

                TextColumn::make('payment_type')
                    ->label('Yöntem')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ContractPayment::PAYMENT_TYPES[$state] ?? $state),

                TextColumn::make('status')
                    ->label('Durum')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ContractPayment::STATUSES[$state] ?? '—')
                    ->color(fn (?string $state): string => $state === 'paid' ? 'success' : 'gray'),

                TextColumn::make('amount')
                    ->label('Tutar')
                    ->numeric(2)
                    ->suffix(' ₺')
                    ->sortable(),

                TextColumn::make('checks.due_date')
                    ->label('Çek Vadesi')
                    ->date('d.m.Y')
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Ödeme Ekle')
                    ->using(function (array $data): ContractPayment {
                        $dueDate = $data['check_due_date'] ?? null;
                        unset($data['check_due_date']);

                        /** @var ContractPayment $payment */
                        $payment = $this->getOwnerRecord()->payments()->create($data);
                        $payment->syncCheck($dueDate);

                        return $payment;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(function (array $data): array {
                        $check = ContractPayment::find($data['id'])?->checks()->first();
                        $data['check_due_date'] = $check?->due_date?->format('Y-m-d');

                        return $data;
                    })
                    ->using(function (ContractPayment $record, array $data): ContractPayment {
                        $dueDate = $data['check_due_date'] ?? null;
                        unset($data['check_due_date']);

                        $record->update($data);
                        $record->syncCheck($dueDate);

                        return $record;
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
