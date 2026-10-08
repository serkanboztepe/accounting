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
|
| MOD (APP_PROFILE) — tek satırla hazır set, bkz App\Support\ModuleProfiles:
|   APP_PROFILE=mimar       # Mimar grubu (Kat Karşılığı + Emlak Beyanı) + basit cari Satış/Tahsilat
|   APP_PROFILE=muteahhit   # Proje maliyeti: sözleşme, gider, çek, cari alış/ödeme
|   APP_PROFILE=toptanci    # Stoklu satış: direkt satış, teklif, satış sözleşmesi; alış tarafı yok
|   APP_PROFILE=alacak_verecek  # Esnaf defteri: cari (iki yön) + gider; proje yok
|   MOD_PROJECTS=false      # Projeler menüsü + gider/caride proje seçimi (WhatsApp da proje sormaz)
| Boşsa her şey açık. Tek tek MOD_* satırı her zaman profili ezer (ör. APP_PROFILE=toptanci + MOD_QUOTES=false).
*/

$overrides = [];
foreach (\App\Support\ModuleProfiles::KEYS as $key) {
    $value = env('MOD_' . strtoupper($key));
    if ($value !== null) {
        $overrides[$key] = (bool) $value;
    }
}

// Tek panelde (TENANCY) bu değerler firma seçilince hub'daki firma ayarlarıyla ezilir.
// Anahtarların açıklamaları: App\Support\ModuleProfiles::LABELS.
return \App\Support\ModuleProfiles::resolve(env('APP_PROFILE'), $overrides);
