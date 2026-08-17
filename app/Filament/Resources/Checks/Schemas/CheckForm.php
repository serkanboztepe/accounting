<?php

namespace App\Filament\Resources\Checks\Schemas;

use App\Support\Money;
use App\Support\Forms\MoneyInput;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CheckForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Çek Bilgileri')
                ->schema([
                    Select::make('contract_payment_id')
                        ->label('Sözleşme Ödemesi')
                        ->relationship('contractPayment', 'id')
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set) {
                            if (! $state) {
                                return;
                            }

                            $payment = \App\Models\ContractPayment::find($state);
                            if ($payment) {
                                $set('project_id', $payment->project_id);
                                $set('party_id', $payment->party_id);
                                $set('amount', Money::format((float) $payment->amount));
                                $set('due_date', $payment->due_date);
                            }
                        }),
                    Select::make('project_id')
                        ->label('Proje')
                        ->relationship('project', 'name')
                        ->searchable()
                        ->preload(),
                    Select::make('party_id')
                        ->label('Cari')
                        ->relationship('party', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    TextInput::make('check_number')
                        ->label('Çek No')
                        ->maxLength(255),
                    TextInput::make('bank_name')
                        ->label('Banka')
                        ->maxLength(255),
                    DatePicker::make('issue_date')
                        ->label('Düzenleme Tarihi'),
                    DatePicker::make('due_date')
                        ->label('Vade Tarihi')
                        ->required(),
                    MoneyInput::make('amount'),
                    Select::make('status')
                        ->label('Durum')
                        ->options([
                            'portfolio' => 'Portföyde',
                            'issued' => 'Verildi',
                            'collected' => 'Tahsil Edildi',
                            'paid' => 'Ödendi',
                            'cancelled' => 'İptal',
                            'bounced' => 'Karşılıksız',
                        ])
                        ->default('portfolio')
                        ->required(),
                    TextInput::make('description')
                        ->label('Açıklama')
                        ->maxLength(255),
                    Textarea::make('notes')
                        ->label('Notlar')
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }
}
