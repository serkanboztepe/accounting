<?php

namespace App\Filament\Resources\Quotes\Schemas;

use App\Models\Quote;
use App\Support\Forms\MoneyInput;
use App\Support\Forms\PaymentPlanRepeater;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class QuoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Teklif Bilgileri')
                ->schema([
                    Select::make('party_id')
                        ->label('Müşteri / Cari')
                        ->relationship('party', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),

                    Select::make('project_id')
                        ->label('Proje')
                        ->relationship('project', 'name')
                        ->searchable()
                        ->preload()
                        ->helperText('İsteğe bağlı — teklif belirli bir projeye aitse seç.'),

                    TextInput::make('title')
                        ->label('Başlık')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Select::make('status')
                        ->label('Durum')
                        ->options(Quote::STATUSES)
                        ->default(Quote::STATUS_DRAFT)
                        ->required()
                        ->native(false),

                    DatePicker::make('quote_date')
                        ->label('Teklif Tarihi')
                        ->default(now()),

                    DatePicker::make('valid_until')
                        ->label('Geçerlilik Tarihi'),

                    MoneyInput::make('total_amount', 'Toplam Tutar')
                        ->required(false)
                        ->dehydrateStateUsing(fn ($state) => \App\Support\Money::store($state) ?? '0.00')
                        ->helperText('Anlaşılan götürü bedel. Boş bırakırsan tutar kalemlerden hesaplanır; dolu olduğunda kalem tutarları kilitlenir.'),

                    Textarea::make('notes')
                        ->label('Notlar')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),

            Section::make('Ödeme Planı')
                ->description('Çıktıda kalemlerin altında görünecek ödeme takvimi. Dönüştürünce sözleşmeye kopyalanır.')
                ->collapsible()
                ->schema([
                    PaymentPlanRepeater::make(),
                ]),
        ]);
    }
}
