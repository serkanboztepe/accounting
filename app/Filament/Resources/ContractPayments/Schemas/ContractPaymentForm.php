<?php

namespace App\Filament\Resources\ContractPayments\Schemas;

use App\Models\ContractPayment;
use App\Support\Forms\MoneyInput;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ContractPaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ödeme Bilgileri')
                ->schema([
                    Select::make('contract_id')
                        ->label('Sözleşme')
                        ->relationship('contract', 'title')
                        ->searchable()
                        ->preload()
                        ->required(),

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
                        ->required()
                        ->live(),

                    MoneyInput::make('amount'),

                    // Çek: yalnız vade. Çek kaydı durum senkronuyla otomatik açılır.
                    DatePicker::make('check_due_date')
                        ->label('Çek Vadesi')
                        ->default(fn (Get $get) => $get('payment_date'))
                        ->visible(fn (Get $get): bool => $get('payment_type') === 'check')
                        ->required(fn (Get $get): bool => $get('payment_type') === 'check'),

                    // Çek dışı ödenmemiş taahhüt → opsiyonel vade (hatırlatma için).
                    DatePicker::make('due_date')
                        ->label('Vade (opsiyonel)')
                        ->helperText('Ödeme için planlanan tarih. Vade yaklaşınca/geçince panelde hatırlatılır.')
                        ->visible(fn (Get $get): bool => $get('status') === 'unpaid' && $get('payment_type') !== 'check'),

                    Textarea::make('notes')
                        ->label('Notlar')
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }
}
