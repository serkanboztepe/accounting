<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Models\Check;
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
use Filament\Forms\Components\TextInput;
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
                    ->label('Ödeme Tarihi'),

                Select::make('payment_type')
                    ->label('Ödeme Türü')
                    ->options([
                        'cash' => 'Nakit',
                        'eft' => 'EFT',
                        'bank_transfer' => 'Havale',
                        'check' => 'Çek',
                        'promissory_note' => 'Senet',
                        'other' => 'Diğer',
                    ])
                    ->default('bank_transfer')
                    ->required()
                    ->live(),

                MoneyInput::make('amount'),

                Textarea::make('notes')
                    ->label('Notlar')
                    ->columnSpanFull(),

                TextInput::make('check_number')
                    ->label('Çek No')
                    ->visible(fn (Get $get): bool => $get('payment_type') === 'check'),

                TextInput::make('bank_name')
                    ->label('Banka')
                    ->visible(fn (Get $get): bool => $get('payment_type') === 'check'),

                DatePicker::make('check_due_date')
                    ->label('Çek Vade Tarihi')
                    ->required(fn (Get $get) => $get('payment_type') === 'check')
                    ->visible(fn (Get $get): bool => $get('payment_type') === 'check'),

                Select::make('check_status')
                    ->label('Çek Durumu')
                    ->options([
                        'portfolio' => 'Portföyde',
                        'issued' => 'Verildi',
                        'collected' => 'Tahsil Edildi',
                        'paid' => 'Ödendi',
                        'cancelled' => 'İptal',
                        'bounced' => 'Karşılıksız',
                    ])
                    ->default('issued')
                    ->visible(fn (Get $get): bool => $get('payment_type') === 'check'),

                TextInput::make('check_description')
                    ->label('Çek Açıklama')
                    ->visible(fn (Get $get): bool => $get('payment_type') === 'check'),

                Textarea::make('check_notes')
                    ->label('Çek Notları')
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => $get('payment_type') === 'check'),
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
            ->recordTitleAttribute('description')
            ->description($description)
            ->columns([
                TextColumn::make('payment_date')
                    ->label('Ödeme Tarihi')
                    ->date()
                    ->sortable(),

                TextColumn::make('payment_type')
                    ->label('Ödeme Türü')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'cash'             => 'Nakit',
                        'eft'              => 'EFT',
                        'bank_transfer'    => 'Havale',
                        'check'            => 'Çek',
                        'promissory_note'  => 'Senet',
                        'other'            => 'Diğer',
                        default            => $state,
                    }),

                TextColumn::make('amount')
                    ->label('Tutar')
                    ->numeric(2)
                    ->suffix(' ₺')
                    ->sortable(),

                TextColumn::make('checks.check_number')
                    ->label('Çek No')
                    ->placeholder('-'),

                TextColumn::make('checks.due_date')
                    ->label('Vade')
                    ->date('d.m.Y')
                    ->placeholder('-'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Ödeme Ekle')
                    ->using(function (array $data): ContractPayment {
                        $contract = $this->getOwnerRecord();

                        $checkNumber  = $data['check_number'] ?? null;
                        $bankName     = $data['bank_name'] ?? null;
                        $checkDueDate = $data['check_due_date'] ?? $data['payment_date'] ?? now()->toDateString();
                        $checkStatus  = $data['check_status'] ?? 'issued';
                        $checkDesc    = $data['check_description'] ?? null;
                        $checkNotes   = $data['check_notes'] ?? null;

                        unset($data['check_number'], $data['bank_name'], $data['check_due_date'],
                              $data['check_status'], $data['check_description'], $data['check_notes']);

                        /** @var ContractPayment $payment */
                        $payment = $contract->payments()->create($data);

                        if ($payment->payment_type === 'check') {
                            Check::create([
                                'party_id'            => $contract->party_id,
                                'project_id'          => $contract->project_id,
                                'contract_payment_id' => $payment->id,
                                'check_number'        => $checkNumber,
                                'bank_name'           => $bankName,
                                'issue_date'          => $payment->payment_date,
                                'due_date'            => $checkDueDate,
                                'amount'              => $payment->amount,
                                'status'              => $checkStatus,
                                'description'         => $checkDesc,
                                'notes'               => $checkNotes,
                            ]);
                        }

                        return $payment;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(function (array $data): array {
                        $check = Check::where('contract_payment_id', $data['id'])->first();
                        if ($check) {
                            $data['check_number']      = $check->check_number;
                            $data['bank_name']         = $check->bank_name;
                            $data['check_due_date']    = $check->due_date?->format('Y-m-d');
                            $data['check_status']      = $check->status;
                            $data['check_description'] = $check->description;
                            $data['check_notes']       = $check->notes;
                        }
                        return $data;
                    })
                    ->using(function (ContractPayment $record, array $data): ContractPayment {
                        $contract = $this->getOwnerRecord();

                        $checkNumber  = $data['check_number'] ?? null;
                        $bankName     = $data['bank_name'] ?? null;
                        $checkDueDate = $data['check_due_date'] ?? $data['payment_date'] ?? now()->toDateString();
                        $checkStatus  = $data['check_status'] ?? 'issued';
                        $checkDesc    = $data['check_description'] ?? null;
                        $checkNotes   = $data['check_notes'] ?? null;

                        unset($data['check_number'], $data['bank_name'], $data['check_due_date'],
                              $data['check_status'], $data['check_description'], $data['check_notes']);

                        $record->update($data);

                        $existingCheck = $record->checks()->first();

                        if ($record->payment_type === 'check') {
                            $payload = [
                                'party_id'            => $contract->party_id,
                                'project_id'          => $contract->project_id,
                                'contract_payment_id' => $record->id,
                                'check_number'        => $checkNumber,
                                'bank_name'           => $bankName,
                                'issue_date'          => $record->payment_date,
                                'due_date'            => $checkDueDate,
                                'amount'              => $record->amount,
                                'status'              => $checkStatus,
                                'description'         => $checkDesc,
                                'notes'               => $checkNotes,
                            ];
                            $existingCheck ? $existingCheck->update($payload) : Check::create($payload);
                        } else {
                            $existingCheck?->delete();
                        }

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
