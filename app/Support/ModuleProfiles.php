<?php

namespace App\Support;

/**
 * Kurulum modları (APP_PROFILE) — config/modules.php için hazır varsayılan setler.
 * Her set, o tipteki ilk canlı kurulumun gerçek ayarlarının kopyasıdır:
 *   mimar     = yildiz   (sadece Mimar grubu + basit cari Satış/Tahsilat)
 *   muteahhit = muhasebe (proje maliyeti: sözleşme, gider, çek, alış/ödeme)
 *   trade     = Stoklu satış (fırın, toptancı, lastikçi…): Satışlar + Alışlar tezgahı, stok, cari iki yön, gider
 *   alacak_verecek = esnaf defteri: sadece cari (iki yön: satış/tahsilat + alış/ödeme) + gider; proje YOK
 * Burada olmayan anahtar = açık (true). Tek tek MOD_* env satırı her zaman profili ezer.
 */
class ModuleProfiles
{
    public const PROFILES = [
        'mimar' => [
            'direct_sales'       => false,
            'stock'              => false,
            'purchase_contracts' => false,
            'sales_contracts'    => false,
            'quotes'             => false,
            'contracts'          => false,
            'checks'             => false,
            'expenses'           => false,
            'dashboard'          => false,
            'cari_supplier'      => false,
            'report_project'     => false,
            'report_stock'       => false,
            'report_sales'       => false,
        ],
        'muteahhit' => [
            'land_share'      => false,
            'property_tax'    => false,
            'direct_sales'    => false,
            'stock'           => false,
            'sales_contracts' => false,
            'quotes'          => false,
            'report_stock'    => false,
            'report_sales'    => false,
        ],
        'alacak_verecek' => [
            'land_share'         => false,
            'property_tax'       => false,
            'projects'           => false,
            'direct_sales'       => false,
            'stock'              => false,
            'purchase_contracts' => false,
            'sales_contracts'    => false,
            'quotes'             => false,
            'contracts'          => false,
            'checks'             => false,
            'dashboard'          => false,
            'report_project'     => false,
            'report_stock'       => false,
            'report_sales'       => false,
        ],
        // Stoklu satış (fırın, toptancı, lastikçi, nalbur…): mal alır, stoklu satar. Sektöre özel paket yok;
        // farklar hub'daki tek tek anahtarlar + ürün kartı ayarlarıyla (otomatik düşüş, eşik).
        'trade' => [
            'land_share'         => false,
            'property_tax'       => false,
            'projects'           => false,
            'contracts'          => false,
            'purchase_contracts' => false,
            'sales_contracts'    => false,
            'quotes'             => false,
            'checks'             => false,
            'report_project'     => false,
            'dashboard'          => false,
            'sales_desk'         => true,
            'purchases'          => true,
            'report_monthly'     => true,
        ],
    ];

    /** Kaldırılan paket adları → yerine geçen (2026-10-13: toptancı paketi "Stoklu satış"a katıldı). */
    public const LEGACY = ['toptanci' => 'trade'];

    /** config('modules') anahtarları — hub firma ayarları ekranı da bu listeyi kullanır. */
    public const KEYS = [
        'land_share', 'direct_sales', 'stock', 'purchase_contracts', 'sales_contracts', 'quotes',
        'property_tax', 'projects', 'contracts', 'checks', 'expenses', 'dashboard', 'cari_supplier',
        'report_project', 'report_stock', 'report_sales', 'smart_assistant', 'daily_nudge',
        'sales_desk', 'purchases', 'report_monthly', 'revenue_prompt', 'natural_voice', 'proactive_alerts',
    ];

    /**
     * Varsayılanı KAPALI olanlar (sektörde de açık değil; hub'dan firma firma açılır).
     * Satışlar/Alışlar tezgahı, Ay Sonucu ve akşam ciro sorusu: canlı firmalarda kendiliğinden AÇILMAZ
     * (yalnız "Stoklu satış" paketinde açık) — mevcut akışlar değişmesin.
     */
    public const DEFAULT_OFF = ['smart_assistant', 'sales_desk', 'purchases', 'report_monthly', 'revenue_prompt', 'natural_voice', 'proactive_alerts'];

    /** Hub ekranındaki Türkçe adlar. */
    public const LABELS = [
        'land_share'         => 'Hisse Dağılımı (Kat Karşılığı)',
        'property_tax'       => 'Emlak Vergisi Bildirimi',
        'projects'           => 'Projeler (gider/caride proje seçimi)',
        'contracts'          => 'Sözleşmeler (teslimat, ödeme, fatura)',
        'purchase_contracts' => 'Alım sözleşmesi',
        'sales_contracts'    => 'Satış sözleşmesi',
        'quotes'             => 'Teklif',
        'checks'             => 'Çekler',
        'expenses'           => 'Direkt giderler',
        'dashboard'          => 'Ana sayfa (özet)',
        'cari_supplier'      => 'Caride tedarikçi tarafı (alış + ödeme)',
        'direct_sales'       => 'Ürün/hizmet kartı + direkt satış',
        'stock'              => 'Stok',
        'report_project'     => 'Proje raporları',
        'report_stock'       => 'Stok raporu',
        'report_sales'       => 'Satış özeti',
        'smart_assistant'    => 'Akıllı WhatsApp asistanı (temel — KAPATMAYIN; yalnız acil durumda eski sisteme dönmek için)',
        'daily_nudge'        => 'Akşam mesajı (19:00 "Bugün aklında bir şey var mı?")',
        'sales_desk'         => 'Satışlar ekranı (peşin + cariye satış, ürünsüz tutar / toplu ciro)',
        'purchases'          => 'Alışlar ekranı (stoklu alış, peşin alış = alış + ödeme)',
        'report_monthly'     => 'Ay Sonucu raporu (ciro − alış − gider)',
        'revenue_prompt'     => 'Akşam ciro sorusu (21:00 "Bugünkü ciro ne?")',
        'natural_voice'      => 'Doğal konuşma (asistan özeti insan gibi yazar — deneme)',
        'proactive_alerts'   => 'Sabah haberi (09:00 girilmeyen kasa + Pazartesi haftalık özet)',
    ];

    /** Sektör bir şey demiyorsa: açık — DEFAULT_OFF hariç. */
    public static function fallback(string $key): bool
    {
        return ! in_array($key, self::DEFAULT_OFF, true);
    }

    /**
     * @return array<string, bool>
     */
    public static function defaults(?string $profile): array
    {
        return self::PROFILES[self::LEGACY[$profile] ?? $profile] ?? [];
    }

    /**
     * Profil varsayılanı + tek tek ezmeler → tam modül listesi (olmayan = açık).
     *
     * @param  array<string, bool|null>  $overrides  null = profile bırak
     * @return array<string, bool>
     */
    public static function resolve(?string $profile, array $overrides = []): array
    {
        $defaults = self::defaults($profile);
        $modules = [];
        foreach (self::KEYS as $key) {
            $modules[$key] = (bool) ($overrides[$key] ?? $defaults[$key] ?? self::fallback($key));
        }

        return $modules;
    }
}
