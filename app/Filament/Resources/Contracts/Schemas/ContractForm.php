<?php

namespace App\Filament\Resources\Contracts\Schemas;

use App\Models\Contract;
use App\Support\Forms\MoneyInput;
use App\Support\Forms\PaymentPlanRepeater;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContractForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            // Yön: oluştururken butondan (?direction=), düzenlerken kayıttan gelir.
            // Param yoksa etkin modüle göre varsayılan (sadece satış yapan firmada satış).
            Hidden::make('direction')
                ->default(function () {
                    $q = request()->query('direction');
                    if ($q === Contract::DIRECTION_SALE) {
                        return Contract::DIRECTION_SALE;
                    }
                    if ($q === Contract::DIRECTION_PURCHASE) {
                        return Contract::DIRECTION_PURCHASE;
                    }

                    return config('modules.purchase_contracts', true)
                        ? Contract::DIRECTION_PURCHASE
                        : Contract::DIRECTION_SALE;
                }),

            Section::make('Sözleşme Bilgileri')
                ->collapsible()
                ->collapsed()
                ->persistCollapsed()
                ->schema([
                    Select::make('party_id')
                        ->label('Cari')
                        ->relationship('party', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),

                    Select::make('project_id')
                        ->label('Proje')
                        ->relationship('project', 'name')
                        ->searchable()
                        ->preload()
                        ->helperText('Sözleşme tek bir şantiyeye özelse seç; birden fazla şantiyeye iş/teslimat yapılacaksa (çapraz proje, ör. m² usulü) boş bırak — projeyi her teslimatta ayrı seçersin.'),

                    TextInput::make('title')
                        ->label('Başlık')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Select::make('status')
                        ->label('Durum')
                        ->options([
                            'draft'     => 'Taslak',
                            'active'    => 'Aktif',
                            'completed' => 'Tamamlandı',
                            'cancelled' => 'İptal',
                        ])
                        ->default('active')
                        ->required(),

                    DatePicker::make('contract_date')
                        ->label('Sözleşme Tarihi'),

                    Select::make('contract_type')
                        ->label('Sözleşme Türü')
                        ->options(Contract::TYPES)
                        ->required()
                        ->native(false),

                    MoneyInput::make('total_amount', 'Toplam Tutar')
                        ->required(false)
                        ->dehydrateStateUsing(fn ($state) => \App\Support\Money::store($state) ?? '0.00')
                        ->helperText('Anlaşılan götürü bedel. Boş bırakırsan kalemlerden otomatik hesap yapılmaz; bu alan dolu olduğunda kalem tutarları kilitlenir.'),

                    DatePicker::make('start_date')
                        ->label('Başlangıç Tarihi'),

                    DatePicker::make('end_date')
                        ->label('Bitiş Tarihi'),

                    Textarea::make('notes')
                        ->label('Notlar')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),

            Section::make('Ödeme Planı')
                ->description('Çıktıda görünecek ödeme takvimi. Bu bir plandır — gerçek tahsilat/ödeme "Ödemeler" sekmesinden işlenir.')
                ->collapsible()
                ->collapsed()
                ->schema([
                    PaymentPlanRepeater::make(),
                ]),
        ]);
    }
}
