<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Support\Forms\MoneyInput;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('expense_date')
                ->label('Tarih')
                ->default(now())
                ->required(),
            MoneyInput::make('amount'),
            Select::make('project_id')
                ->label('Proje')
                ->relationship('project', 'name')
                ->searchable()
                ->preload()
                ->helperText('Boş bırakılırsa genel gider olarak değerlendirilir.'),
            Select::make('party_id')
                ->label('Cari')
                ->relationship('party', 'name')
                ->searchable()
                ->preload(),
            Select::make('expense_category_id')
                ->label('Kategori')
                ->relationship('category', 'name')
                ->searchable()
                ->preload(),
            Select::make('payment_status')
                ->label('Ödeme Durumu')
                ->options([
                    'paid' => 'Ödendi',
                    'unpaid' => 'Ödenmedi',
                    'partial' => 'Kısmi Ödendi',
                ])
                ->default('paid')
                ->required()
                ->live(),
            // Ödenmemiş/kısmi ise opsiyonel vade — hatırlatma için (boş bırakılabilir).
            DatePicker::make('due_date')
                ->label('Vade (opsiyonel)')
                ->helperText('Ödeme için planlanan tarih. Vade yaklaşınca/geçince panelde hatırlatılır.')
                ->visible(fn (Get $get): bool => in_array($get('payment_status'), ['unpaid', 'partial'], true)),
            TextInput::make('description')
                ->label('Açıklama')
                ->maxLength(255),
            Textarea::make('notes')
                ->label('Notlar')
                ->rows(3),
        ]);
    }
}
