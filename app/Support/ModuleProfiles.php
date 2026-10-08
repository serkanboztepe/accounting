<?php

namespace App\Support;

/**
 * Kurulum modları (APP_PROFILE) — config/modules.php için hazır varsayılan setler.
 * Her set, o tipteki ilk canlı kurulumun gerçek ayarlarının kopyasıdır:
 *   mimar     = yildiz   (sadece Mimar grubu + basit cari Satış/Tahsilat)
 *   muteahhit = muhasebe (proje maliyeti: sözleşme, gider, çek, alış/ödeme)
 *   toptanci  = yenyapi  (stoklu satış: direkt satış, teklif, satış sözleşmesi; alış tarafı yok)
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
        'toptanci' => [
            'land_share'         => false,
            'property_tax'       => false,
            'purchase_contracts' => false,
            'cari_supplier'      => false,
        ],
    ];

    /** config('modules') anahtarları — hub firma ayarları ekranı da bu listeyi kullanır. */
    public const KEYS = [
        'land_share', 'direct_sales', 'stock', 'purchase_contracts', 'sales_contracts', 'quotes',
        'property_tax', 'projects', 'contracts', 'checks', 'expenses', 'dashboard', 'cari_supplier',
        'report_project', 'report_stock', 'report_sales',
    ];

    /** Hub ekranındaki Türkçe adlar. */
    public const LABELS = [
        'land_share'         => 'Kat Karşılığı / Hisse Dağıtımı',
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
    ];

    /**
     * @return array<string, bool>
     */
    public static function defaults(?string $profile): array
    {
        return self::PROFILES[$profile] ?? [];
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
            $modules[$key] = (bool) ($overrides[$key] ?? $defaults[$key] ?? true);
        }

        return $modules;
    }
}
