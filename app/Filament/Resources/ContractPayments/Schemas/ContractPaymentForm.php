<?php

namespace App\Filament\Resources\ContractPayments\Schemas;

use App\Models\Contract;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use App\Support\Forms\MoneyInput;
use Filament\Forms\Components\TextInput;
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
                        ->live()
                        ->required(),
                    DatePicker::make('payment_date')
                        ->label('Ödeme Tarihi')
                        ->live(),
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
                        ->default('eft')
                        ->live()
                        ->required(),
                    MoneyInput::make('amount'),
                    Textarea::make('notes')
                        ->label('Notlar')
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('Çek Bilgileri')
                ->visible(fn (Get $get): bool => $get('payment_type') === 'check')
                ->schema([
                    TextInput::make('check_number')
                        ->label('Çek No')
                        ->maxLength(255),
                    TextInput::make('bank_name')
                        ->label('Banka')
                        ->maxLength(255),
                    DatePicker::make('check_due_date')
                        ->label('Çek Vade Tarihi')
                        ->default(fn (Get $get) => $get('payment_date')),
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
                        ->default('portfolio')
                        ->required(fn (Get $get): bool => $get('payment_type') === 'check'),
                    TextInput::make('check_description')
                        ->label('Çek Açıklama')
                        ->maxLength(255),
                    Textarea::make('check_notes')
                        ->label('Çek Notları')
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }
}
