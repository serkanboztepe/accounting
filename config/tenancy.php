<?php

/*
| Tek panel (Model 3): tüm firmalar panel.hesapasistanim.com'dan girer; giriş yapan
| kullanıcının firmasının AYRI veritabanı seçilir. Bkz App\Tenancy\Tenancy.
| Kapalıyken (varsayılan) eski düzen: kurulum başına tek firma, ayarlar .env'den.
*/
return [
    'enabled' => (bool) env('TENANCY', false),

    // Yeni firma veritabanı adı: önek + firma kısa adı (hesap_yildiz).
    'database_prefix' => env('TENANCY_DB_PREFIX', 'hesap_'),

    // "Firma Oluştur" CREATE DATABASE için yetkili kullanıcı (boşsa merkezin kullanıcısı).
    'admin_username' => env('TENANCY_ADMIN_DB_USERNAME'),
    'admin_password' => env('TENANCY_ADMIN_DB_PASSWORD'),
];
