<?php

/*
|--------------------------------------------------------------------------
| Modül aç/kapa (kurulum başına, .env'den)
|--------------------------------------------------------------------------
| Her müşteri kurulumu yalnız kullandığı modülleri görsün. Kullanıcı bunları
| göremez/değiştiremez — sadece .env ile (yönetici) açılıp kapatılır.
| Çekirdek (cari, sözleşme, proje, gider, çek, teslimat/hakediş) her zaman açık.
| Varsayılan: açık (mevcut kurulumlar etkilenmesin). Kapatmak için .env'e:
|   MOD_LAND_SHARE=false          # Kat Karşılığı / Hisse Dağıtımı
|   MOD_DIRECT_SALES=false        # Hizmet/Ürün kartı + Direkt Satış + Satış Özeti
|   MOD_STOCK=false               # Stok Hareketleri + Stok Raporu + "Mevcut Stok" kolonu (envanter)
|   MOD_SALES_CONTRACTS=false     # "Satış Sözleşmesi" oluşturma butonu
|   MOD_PURCHASE_CONTRACTS=false   # "Alım Sözleşmesi" butonu (sadece satış yapan firma)
|   MOD_QUOTES=false              # Teklif modülü
|   MOD_PROPERTY_TAX=false        # Emlak Vergisi Bildirimi (bina beyanname + kroki Excel çıktısı)
|   MOD_REPORT_PROJECT=false      # Proje Raporları (maliyet/kârlılık)
|   MOD_REPORT_STOCK=false        # Stok Raporu (MOD_STOCK da açık olmalı)
|   MOD_REPORT_SALES=false        # Satış Özeti (MOD_DIRECT_SALES de açık olmalı)
| Notlar:
|   - Hizmet/Ürün kartı (katalog) direct_sales VEYA stock açıksa görünür.
|   - Envantersiz satış: MOD_DIRECT_SALES=true + MOD_STOCK=false (ör. mimar hizmet satışı).
|   - Alım/satış butonlarının ikisi birden kapalıysa sözleşme oluşturulamaz (liste yine gösterir).
*/

return [
    'land_share'         => (bool) env('MOD_LAND_SHARE', true),
    'direct_sales'       => (bool) env('MOD_DIRECT_SALES', true),
    'stock'              => (bool) env('MOD_STOCK', true),
    'purchase_contracts' => (bool) env('MOD_PURCHASE_CONTRACTS', true),
    'sales_contracts'    => (bool) env('MOD_SALES_CONTRACTS', true),
    'quotes'             => (bool) env('MOD_QUOTES', true),
    'property_tax'       => (bool) env('MOD_PROPERTY_TAX', true), // Emlak Vergisi Bildirimi (proje→blok→daire, Excel çıktı)

    // Raporlar (menüde "Raporlar" grubu) — her biri ayrı aç/kapa.
    // Stok/Satış raporu ilgili modül de açıksa görünür.
    'report_project'     => (bool) env('MOD_REPORT_PROJECT', true),
    'report_stock'       => (bool) env('MOD_REPORT_STOCK', true),
    'report_sales'       => (bool) env('MOD_REPORT_SALES', true),
];
