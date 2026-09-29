<?php

namespace App\Filament\Resources\Contracts\Schemas;

use App\Models\Contract;
use App\Support\Forms\MoneyInput;
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
                ->schema(static::fields()),
        ]);
    }

    /**
     * Sözleşmenin kendi alanları (party/proje/başlık/durum/tarih/tutar/tür/not).
     * Create ekranında Section içinde, Edit ekranında ⚙ Ayarlar modalında kullanılır.
     * 'direction' burada YOK — edit'te modaldan yanlışlıkla değiştirilmesin (satış→alım kazası).
     */
    public static function fields(): array
    {
        return [
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

                    // Durum: oluştururken otomatik 'active' (CreateContract), sonradan
                    // "Kapat/Bitir" butonuyla değişecek — formda yer kaplamasın.
                    // Sözleşme tarihi + başlangıç tarihi = oluşturma tarihi (otomatik);
                    // bitiş tarihi sözleşme kapatılınca dolar. Hepsi formdan çıkarıldı.

                    Select::make('contract_type')
                        ->label('Sözleşme Türü')
                        ->options(Contract::TYPES)
                        ->required()
                        ->native(false),

                    MoneyInput::make('total_amount', 'Toplam Tutar')
                        ->required(false)
                        ->dehydrateStateUsing(fn ($state) => \App\Support\Money::store($state) ?? '0.00')
                        ->helperText('Anlaşılan götürü bedel. Boş bırakırsan kalemlerden otomatik hesap yapılmaz; bu alan dolu olduğunda kalem tutarları kilitlenir.'),

                    Textarea::make('notes')
                        ->label('Notlar')
                        ->rows(3)
                        ->columnSpanFull(),
        ];
    }
}
