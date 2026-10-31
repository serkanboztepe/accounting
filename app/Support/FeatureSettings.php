<?php

namespace App\Support;

/**
 * Firmanın SAKLI özellik listesi (hub_firms.settings) ↔ kodun okuduğu config('modules.*') anahtarları.
 *
 * Plan optimized-kindling-rain Faz B (26.10): Çekirdek + 7 kutu. Liste firma başına saklanır, paket varsayılanından
 * HESAPLANMAZ — bir varsayılan değişince canlı firmalar sessizce değişmesin. Listede olmayan = kapalı.
 *
 *   settings.features  = Gider, Yerler (+ Raporlar/ana sayfa alt kutusu), Stok, Anlaşmalar, Hisse, Emlak
 *   settings.assistant = asistan ayarları
 *   settings.legacy    = eski modüller (sözleşme, teklif) — yalnız açık olan firmada görünür
 *   settings.project_label = yer adı (Proje / Şantiye / Şube / Yer)
 *   settings.agreement_direction = anlaşma yönü (purchase / sale / both; boş = both)
 *   settings.profile   = kutulardan TÜRETİLİR (seçilmez) — üslup/yardım metinleri bugünkü gibi ona bakar
 */
final class FeatureSettings
{
    /** Hub'daki 7 kutu (Faz C'de Hayvanlarım eklenecek). */
    public const FEATURES = ['expenses', 'places', 'place_reports', 'stock', 'place_cost', 'agreements', 'land_share', 'property_tax'];

    public const FEATURE_LABELS = [
        'expenses'      => 'Gider',
        'places'        => 'Yerler',
        'place_reports' => 'Raporlar + ana sayfa',
        'stock'         => 'Stok',
        'place_cost'    => 'Alışı yere yaz',
        'agreements'    => 'Anlaşmalar',
        'land_share'    => 'Hisse Dağılımı',
        'property_tax'  => 'Emlak Beyanı',
    ];

    public const ASSISTANT = ['smart_assistant', 'daily_nudge', 'revenue_prompt', 'natural_voice', 'proactive_alerts', 'fast_save', 'assistant_pilot'];

    public const LEGACY = ['contracts', 'purchase_contracts', 'sales_contracts', 'quotes'];

    /** Stok kutusuyla BİRLİKTE gelen eski anahtarlar (ayrı seçilmez). */
    public const STOCK_KEYS = ['stock', 'direct_sales', 'purchases', 'sales_desk', 'report_stock', 'report_sales', 'report_monthly'];

    /** Yerler alt kutusuyla birlikte gelenler. */
    public const PLACE_REPORT_KEYS = ['report_project', 'dashboard'];

    /** Çekirdek: herkeste açık. */
    public const CORE_KEYS = ['cari_supplier', 'checks'];

    public const PLACE_LABELS = ['Proje', 'Şantiye', 'Şube', 'Yer'];

    /** Anlaşma yönü (settings.agreement_direction, 31.10) — değerler Agreement::DIRECTION_* ile aynı + both. */
    public const AGREEMENT_PURCHASE = 'purchase';

    public const AGREEMENT_SALE = 'sale';

    public const AGREEMENT_BOTH = 'both';

    public const AGREEMENT_DIRECTIONS = [self::AGREEMENT_PURCHASE, self::AGREEMENT_SALE, self::AGREEMENT_BOTH];

    public const AGREEMENT_DIRECTION_LABELS = [
        self::AGREEMENT_PURCHASE => 'Yalnız alış',
        self::AGREEMENT_SALE     => 'Satış (alım derse alım)',
        self::AGREEMENT_BOTH     => 'Alış + satış',
    ];

    /** Firma yeni düzene çevrildi mi (saklı liste var mı)? */
    public static function converted(array $settings): bool
    {
        return is_array($settings['features'] ?? null);
    }

    /**
     * Kutular + bağımlılıklar → tam liste. Hisse/Emlak Yerler'i açar; Yerler kapalıysa alt kutusu da kapalı.
     *
     * @return array<string, bool>
     */
    public static function normalizeFeatures(array $features): array
    {
        $f = [];
        foreach (self::FEATURES as $key) {
            $f[$key] = (bool) ($features[$key] ?? false);
        }
        if ($f['land_share'] || $f['property_tax']) {
            $f['places'] = true;
        }
        if (! $f['places']) {
            $f['place_reports'] = false;
        }
        if (! $f['places'] || ! $f['stock']) {
            $f['place_cost'] = false; // Stok + Yerler alt seçimi
        }

        return $f;
    }

    /** @return array<string, bool> */
    private static function pick(array $source, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = (bool) ($source[$key] ?? false);
        }

        return $out;
    }

    /**
     * Saklı liste → config('modules') (ModuleProfiles::KEYS'in hepsi, açıkça).
     *
     * @return array<string, bool>
     */
    public static function toModules(array $settings): array
    {
        $f = self::normalizeFeatures((array) ($settings['features'] ?? []));
        $assistant = self::pick((array) ($settings['assistant'] ?? []), self::ASSISTANT);
        $legacy = self::pick((array) ($settings['legacy'] ?? []), self::LEGACY);

        $modules = array_fill_keys(ModuleProfiles::KEYS, false);
        foreach (self::CORE_KEYS as $key) {
            $modules[$key] = true;
        }
        $modules['expenses'] = $f['expenses'];
        $modules['projects'] = $f['places'];
        foreach (self::PLACE_REPORT_KEYS as $key) {
            $modules[$key] = $f['place_reports'];
        }
        foreach (self::STOCK_KEYS as $key) {
            $modules[$key] = $f['stock'];
        }
        $modules['place_cost'] = $f['place_cost'];
        $modules['agreements'] = $f['agreements'];
        $modules['land_share'] = $f['land_share'];
        $modules['property_tax'] = $f['property_tax'];

        return array_merge($modules, $assistant, $legacy);
    }

    /**
     * Bugünkü çözülmüş modül listesi → saklı liste (dönüşüm). Grup içinde biri açıksa kutu açık.
     *
     * @param  array<string, bool>  $modules  ModuleProfiles::resolve() çıktısı
     * @return array{features: array<string,bool>, assistant: array<string,bool>, legacy: array<string,bool>}
     */
    public static function fromModules(array $modules): array
    {
        $any = fn (array $keys) => (bool) array_filter(array_map(fn ($k) => (bool) ($modules[$k] ?? false), $keys));

        return [
            'features' => self::normalizeFeatures([
                'expenses'      => (bool) ($modules['expenses'] ?? false),
                'places'        => (bool) ($modules['projects'] ?? false),
                'place_reports' => $any(self::PLACE_REPORT_KEYS),
                'stock'         => $any(self::STOCK_KEYS),
                'place_cost'    => (bool) ($modules['place_cost'] ?? false),
                'agreements'    => (bool) ($modules['agreements'] ?? false),
                'land_share'    => (bool) ($modules['land_share'] ?? false),
                'property_tax'  => (bool) ($modules['property_tax'] ?? false),
            ]),
            'assistant' => self::pick($modules, self::ASSISTANT),
            'legacy'    => self::pick($modules, self::LEGACY),
        ];
    }

    /**
     * Kutulardan paket adı — seçilmez, türetilir. Üslup ve yardım metinleri (13 yer: "inşaat şirketinin asistanısın",
     * mimarda proje başlığı, müteahhitte maliyet özeti…) bugünkü gibi config('app.profile')'a bakar.
     * Canlı 11 firmanın hepsinde bugünkü paketle aynı çıkar (FeatureSettingsTest).
     */
    public static function deriveProfile(array $features, ?string $placeLabel): string
    {
        $f = self::normalizeFeatures($features);

        return match (true) {
            $f['land_share'] || $f['property_tax'] => 'mimar',
            $f['stock'] => 'trade',
            $f['places'] && in_array($placeLabel, ['Proje', 'Şantiye'], true) => 'muteahhit',
            default => 'alacak_verecek',
        };
    }

    /** Paket adına göre bugün görünen yer adı (Labels::project'in eski kuralı) — dönüşümde saklanır ki ad değişmesin. */
    public static function legacyPlaceLabel(?string $profile): string
    {
        return in_array($profile, ['trade', 'alacak_verecek'], true) ? 'Şube' : 'Proje';
    }

    /**
     * Eski → yeni farkı: [anahtar => [önce, sonra, beklenen mi, açıklama]].
     *
     * @return array<string, array{0:bool, 1:bool, 2:bool, 3:string}>
     */
    public static function diff(array $before, array $after): array
    {
        $out = [];
        foreach (ModuleProfiles::KEYS as $key) {
            $b = (bool) ($before[$key] ?? false);
            $a = (bool) ($after[$key] ?? false);
            if ($a === $b) {
                continue;
            }
            [$expected, $why] = match (true) {
                $key === 'checks' && $a => [true, 'Çekler herkese açılır (karar 26.10)'],
                $key === 'cari_supplier' && $a => [true, 'Alış/ödeme çekirdekte (karar 26.10)'],
                in_array($key, self::STOCK_KEYS, true) && $a && ($after['stock'] ?? false) => [true, 'Stok kutusuyla birlikte gelir (karar 26.10)'],
                default => [false, 'BEKLENMEYEN'],
            };
            $out[$key] = [$b, $a, $expected, $why];
        }

        return $out;
    }
}
