<?php

namespace App\Filament\Hub\HubFirms;

use App\Support\FeatureSettings;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Hub "Özellikler" alanları — firma oluşturma ve firma ayarları penceresi aynısını kullanır (plan 26.10).
 * Çekirdek bilgi satırı + 7 kutu (bağımlılık: Hisse/Emlak Yerler'i açar ve kilitler) + asistan + eski.
 * İşletme türü yalnız bilgidir, hiçbir kutuyu değiştirmez.
 */
final class FeatureFields
{
    /** @return array<int, \Filament\Schemas\Components\Component|\Filament\Forms\Components\Field> */
    public static function schema(bool $showLegacy = true): array
    {
        $placesLocked = fn (Get $get): bool => (bool) $get('feat_land_share') || (bool) $get('feat_property_tax');
        $lockPlaces = function ($state, Set $set): void {
            if ($state) {
                $set('feat_places', true);
            }
        };

        return [
            Select::make('sector')->label('İşletme türü')
                ->options(collect(config('sectors'))->map(fn (array $s) => $s['label'])->all())
                ->placeholder('Seçilmedi')
                ->helperText('Yalnız bilgi — asistan karşısındakinin işini bilir. Hiçbir kutuyu açıp kapatmaz.'),
            TextInput::make('business_note')->label('İşletme notu (isteğe bağlı)')
                ->placeholder('Ör. Konut inşaatı yapar, çoğunlukla vadeli çekle öder.')
                ->maxLength(300),

            Section::make('Çekirdek — herkeste var')
                ->schema([
                    Placeholder::make('core_info')->hiddenLabel()
                        ->content('Cariler + ekstre · Satış Yaptım / Ödeme Aldım / Alım Yaptım / Ödeme Yaptım · Çekler · Hatırlatmalar + Takvim · İşlem Geçmişi · WhatsApp asistanı'),
                ]),

            Section::make('Özellikler')
                ->columns(1)
                ->schema([
                    Toggle::make('feat_expenses')->label('Gider')
                        ->helperText('Giderler, gider kategorileri, düzenli gider. Kapalıysa hiçbir yoldan gider yazılmaz.'),
                    Toggle::make('feat_stock')->label('Stok')->live()
                        ->helperText('Satış/Alım Yaptım ürünlü olur (tutar da yazılır), iade, ürün kartları, sayım, Satışlar/Alışlar ekranı, stok raporu, satış özeti, Ay Sonucu, "bitmek üzere" uyarısı — hepsi birlikte. Ödeme/tahsilat değişmez.'),
                    Toggle::make('feat_places')->label('Yerler')->live()
                        ->disabled($placesLocked)
                        ->dehydrated()
                        ->helperText(fn (Get $get) => $placesLocked($get)
                            ? '🔒 Hisse Dağılımı / Emlak Beyanı için açık.'
                            : 'Şube / şantiye / ahır: kayıtlarda "hangi yer?", yere göre sonuç.'),
                    Select::make('project_label')->label('Yer adı')
                        ->options(array_combine(FeatureSettings::PLACE_LABELS, FeatureSettings::PLACE_LABELS))
                        ->selectablePlaceholder(false)
                        ->default('Proje')
                        ->visible(fn (Get $get) => (bool) $get('feat_places') || $placesLocked($get))
                        ->helperText('Panelde ve WhatsApp\'ta görünen ad. Yalnız ad — başka bir şeyi değiştirmez.'),
                    Toggle::make('feat_place_reports')->label('Raporlar + ana sayfa')
                        ->visible(fn (Get $get) => (bool) $get('feat_places') || $placesLocked($get))
                        ->helperText('Yere göre rapor (proje raporu) ve ana sayfa özeti.'),
                    Toggle::make('feat_agreements')->label('Anlaşmalar')
                        ->helperText('Cariyle kalem kalem anlaşma, gelen/kalan; WhatsApp\'ta sözleşme fotoğrafı okuma. Stok açıksa gelen mal stoğa girer.'),
                    Toggle::make('feat_land_share')->label('Hisse Dağılımı')->live()->afterStateUpdated($lockPlaces)
                        ->helperText('Kat karşılığı hisse dağılımı. Yerler\'i de açar.'),
                    Toggle::make('feat_property_tax')->label('Emlak Beyanı')->live()->afterStateUpdated($lockPlaces)
                        ->helperText('Emlak vergisi bildirimi. Yerler\'i de açar.'),
                ]),

            Section::make('Asistan')
                ->columns(2)
                ->schema([
                    Toggle::make('asst_daily_nudge')->default(true)->label('Akşam mesajı (19:00)'),
                    Toggle::make('asst_proactive_alerts')->default(true)->label('Sabah haberi (09:00)'),
                    Toggle::make('asst_revenue_prompt')->label('Akşam ciro sorusu (21:00)')
                        ->visible(fn (Get $get) => (bool) $get('feat_stock')),
                    Toggle::make('asst_fast_save')->label('Hızlı kayıt'),
                    Toggle::make('asst_natural_voice')->default(true)->label('Doğal konuşma'),
                    Toggle::make('asst_assistant_pilot')->label('Asistan pilotu (deneme)'),
                ]),

            Section::make('Gelişmiş')
                ->collapsed()
                ->schema([
                    Toggle::make('asst_smart_assistant')->default(true)->label('Akıllı WhatsApp asistanı')
                        ->helperText('KAPATMAYIN — yalnız acil durumda eski sisteme dönmek için.'),
                ]),

            Section::make('Eski')
                ->description('Yeni firmaya açılmaz; yalnız kullanan firmada görünür.')
                ->collapsed()
                ->visible(fn (Get $get) => $showLegacy && collect(FeatureSettings::LEGACY)->contains(fn (string $k) => (bool) $get("legacy_{$k}")))
                ->columns(2)
                ->schema([
                    Toggle::make('legacy_contracts')->label('Sözleşmeler'),
                    Toggle::make('legacy_purchase_contracts')->label('Alım sözleşmesi'),
                    Toggle::make('legacy_sales_contracts')->label('Satış sözleşmesi'),
                    Toggle::make('legacy_quotes')->label('Teklif'),
                ]),
        ];
    }
}
