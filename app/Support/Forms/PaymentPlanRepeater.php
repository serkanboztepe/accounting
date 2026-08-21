<?php

namespace App\Support\Forms;

use App\Models\ContractPayment;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

class PaymentPlanRepeater
{
    /**
     * Ödeme planı (belge/çıktı için) — tip + tarih + tutar.
     * Gerçek tahsilat DEĞİLDİR; "kalan" hesaplarına girmez.
     */
    public static function make(string $name = 'payment_plan'): Repeater
    {
        return Repeater::make($name)
            ->label('Ödeme Planı')
            ->helperText('Çıktıda kalemlerin altında görünür. Bu bir plandır — gerçek tahsilat sayılmaz, "kalan" hesabını etkilemez.')
            ->addActionLabel('Ödeme Satırı Ekle')
            ->reorderable()
            ->columns(3)
            ->schema([
                Select::make('payment_type')
                    ->label('Ödeme Tipi')
                    ->options(ContractPayment::PAYMENT_TYPES)
                    ->default('bank_transfer')
                    ->native(false)
                    ->required(),

                DatePicker::make('date')
                    ->label('Tarih'),

                MoneyInput::make('amount', 'Tutar'),

                TextInput::make('note')
                    ->label('Açıklama')
                    ->maxLength(255)
                    ->columnSpanFull(),
            ])
            ->default([])
            ->columnSpanFull();
    }
}
