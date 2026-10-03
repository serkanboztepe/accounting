<?php

namespace App\Support;

/**
 * Kurulum modları (APP_PROFILE) — config/modules.php için hazır varsayılan setler.
 * Her set, o tipteki ilk canlı kurulumun gerçek ayarlarının kopyasıdır:
 *   mimar     = yildiz   (sadece Mimar grubu + basit cari Satış/Tahsilat)
 *   muteahhit = muhasebe (proje maliyeti: sözleşme, gider, çek, alış/ödeme)
 *   toptanci  = yenyapi  (stoklu satış: direkt satış, teklif, satış sözleşmesi; alış tarafı yok)
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
        'toptanci' => [
            'land_share'         => false,
            'property_tax'       => false,
            'purchase_contracts' => false,
            'cari_supplier'      => false,
        ],
    ];

    /**
     * @return array<string, bool>
     */
    public static function defaults(?string $profile): array
    {
        return self::PROFILES[$profile] ?? [];
    }
}
