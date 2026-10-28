<?php

namespace App\Filament\Resources\Purchases\Schemas;

use App\Models\Party;
use App\Models\Purchase;
use App\Support\Forms\MoneyInput;
use App\Support\Forms\TradeLines;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/** Alış düzenleme formu (tezgahtan kaydedilmiş alış). */
class PurchaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('party_id')
                ->label('Kişi / Firma (kimden)')
                ->options(fn () => Party::orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->required(fn (Get $get) => ! $get('paid'))
                ->helperText('Peşin alışta boş bırakılabilir.'),

            DatePicker::make('purchase_date')->label('Tarih')->required(),

            Toggle::make('paid')
                ->label('Peşin (parası verildi)')
                ->helperText('Kişi / firma seçiliyse alış + ödeme birlikte yazılır, bakiye değişmez.')
                ->live(),

            Select::make('payment_type')
                ->label('Ödeme')
                ->options(Purchase::PAYMENT_TYPES)
                ->visible(fn (Get $get) => (bool) $get('paid')),

            TradeLines::repeater('in')->dehydrated(false),

            MoneyInput::make('manual_amount', 'Ürünsüz tutar')->required(false),

            Textarea::make('notes')->label('Açıklama')->rows(2)->columnSpanFull(),
        ]);
    }
}
