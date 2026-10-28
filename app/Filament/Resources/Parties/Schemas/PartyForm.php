<?php

namespace App\Filament\Resources\Parties\Schemas;

use App\Models\Party;
use Filament\Forms\Components\ToggleButtons;
use Illuminate\Support\HtmlString;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PartyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::components())->columns(2);
    }

    /**
     * Yeni cari ve cari sayfasındaki ⚙ Ayarlar aynı pencere alanları (27.10: sayfa / açılır bölüm yerine pencere).
     * $requireKind: yeni caride tip zorunlu; ayarlarda eski (tipsiz) cari zorlanmaz.
     */
    public static function components(bool $requireKind = true): array
    {
        return [
            TextInput::make('name')->label('İsim')->required()->maxLength(255),
            TextInput::make('phone')->label('Telefon')->maxLength(255),
            // Cari tipi (27.10): cari sayfasında yalnız bu tipin düğmeleri çıkar. Açarken zorunlu; eski (boş) kayıtta seçilebilir.
            // Kutu düğmeler (28.10, kullanıcı: radyo yerine): başlık + küçük açıklama; renkler sayfadaki düğmelerle aynı.
            ToggleButtons::make('kind')->label('Bu kişi / firmayla ne tür iş yapıyorsun?')
                ->options(collect(Party::KINDS)->map(fn (string $label, string $kind) => new HtmlString(e($label)
                    . '<span class="block text-xs font-normal opacity-75">' . e(Party::KIND_HINTS[$kind]) . '</span>'))->all())
                ->icons([Party::KIND_CUSTOMER => 'heroicon-o-arrow-up-tray', Party::KIND_SUPPLIER => 'heroicon-o-arrow-down-tray',
                    Party::KIND_BOTH => 'heroicon-o-arrows-right-left', Party::KIND_EMPLOYEE => 'heroicon-o-user'])
                ->colors([Party::KIND_CUSTOMER => 'info', Party::KIND_SUPPLIER => 'warning', Party::KIND_BOTH => 'gray',
                    Party::KIND_EMPLOYEE => 'success'])
                ->columns(['default' => 1, 'sm' => 2])->live()->columnSpanFull()
                ->required($requireKind),
            Textarea::make('notes')->label('Notlar')->columnSpanFull(),
            // Personel: maaş ve ödeme günü (maaş günü hatırlatması, avans mahsubu). Ayrı modül yok, cari + tip.
            TextInput::make('salary_amount')->label('Aylık maaş (₺)')
                ->numeric()->minValue(0)
                ->visible(fn (Get $get) => $get('kind') === Party::KIND_EMPLOYEE),
            TextInput::make('salary_day')->label('Maaş günü (ayın kaçı)')
                ->numeric()->minValue(1)->maxValue(31)
                ->helperText('Girilince her ay o gün (ve bir gün önce) 08:00\'de WhatsApp\'tan maaş hatırlatması gelir; Takvim\'de görünür.')
                ->visible(fn (Get $get) => $get('kind') === Party::KIND_EMPLOYEE),
            Toggle::make('salary_auto')->label('Maaşı her ay maaş gününde kendiliğinden işle')
                ->default(true)->columnSpanFull()
                ->helperText('Maaş günü sabah "Maaş — Ay" yazılır, avans ve kesintiler düşülür; sana yalnız ödemeyi yazmak kalır.')
                ->visible(fn (Get $get) => $get('kind') === Party::KIND_EMPLOYEE),
        ];
    }
}
