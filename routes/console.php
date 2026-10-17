<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Otomatik yedekleme (spatie/laravel-backup).
 * Sunucuda tek bir cron gerekir: * * * * * php artisan schedule:run
 * Gece: önce eskileri temizle, sonra yeni yedek al, sonra sağlığı kontrol et.
 */
Schedule::command('backup:clean')->daily()->at('02:30');
Schedule::command('backup:run')->daily()->at('03:00');   // tek panelde: merkez veritabanı
Schedule::command('backup:monitor')->daily()->at('04:00');

if (config('tenancy.enabled')) {
    // Tek panel: her firma veritabanı ayrı yedeklenir (firma-<id> klasörü), hatırlatmalar firma firma.
    Schedule::command('tenants:run "backup:clean"')->daily()->at('02:40');
    Schedule::command('tenants:run "backup:run --only-db"')->daily()->at('03:10');
    Schedule::command('tenants:run "backup:monitor"')->daily()->at('04:10');
    // Sabah hatırlatmaları tek düzende 08:00 (kullanıcı kararı): çek vadeleri + ödeme günleri.
    Schedule::command('tenants:run "checks:remind"')->dailyAt('08:00')->timezone('Europe/Istanbul');
    Schedule::command('tenants:run "dues:remind"')->dailyAt('08:00')->timezone('Europe/Istanbul');
    // Otomatik stoktan düş (günlük kullanım) + "bitmek üzere" (fırın: un 6 çuval kalınca).
    Schedule::command('tenants:run "stock:remind"')->dailyAt('08:00')->timezone('Europe/Istanbul');
    Schedule::command('tenants:run "alerts:morning"')->dailyAt('09:00')->timezone('Europe/Istanbul'); // Faz 4: modül açık firmada
    // Akşam ciro sorusu (yalnız ayarı açık firmada; o gün peşin satış yoksa).
    Schedule::command('tenants:run "revenue:prompt"')->dailyAt('21:00')->timezone('Europe/Istanbul');
    Schedule::command('tenants:run "nudges:send"')->dailyAt('19:00')->timezone('Europe/Istanbul');
    // WhatsApp hatırlatmaları ("20 Ekim düğün çekimim var", "her ayın 10'u kart ödemesi").
    Schedule::command('tenants:run "reminders:send"')->everyFiveMinutes()->withoutOverlapping(10);
    // Yarım kalan kayıt: 30 dk sessizlikte bir kez, ertesi gün bir kez daha (gece ve 24 saat dışı yok).
    Schedule::command('tenants:run "drafts:follow-up"')->everyFiveMinutes()->withoutOverlapping(10);
    // Günlük konuşma analizi: dünün konuşmaları → numaralı rapor (hub) + yöneticiye WhatsApp özeti.
    Schedule::command('conversations:analyze')->dailyAt('08:30')->timezone('Europe/Istanbul');
    Schedule::command('ai:credit-check')->hourly(); // Faz 5: tahmini kredi < 5 $ → yöneticiye WhatsApp (günde 1)
    // WhatsApp konuşma kaydı 90 günden eskiyse silinir (App\Models\WhatsappMessage tek Prunable model).
    Schedule::command('tenants:run "model:prune"')->daily()->at('04:30');
} else {
    // Çek vadesi hatırlatması (WhatsApp). Ayarlı değilse komut sessizce çıkar.
    Schedule::command('checks:remind')->dailyAt('08:00')->timezone('Europe/Istanbul');
    Schedule::command('dues:remind')->dailyAt('08:00')->timezone('Europe/Istanbul');
    Schedule::command('stock:remind')->dailyAt('08:00')->timezone('Europe/Istanbul');
    Schedule::command('revenue:prompt')->dailyAt('21:00')->timezone('Europe/Istanbul');
    Schedule::command('model:prune')->daily()->at('04:30');
    Schedule::command('reminders:send')->everyFiveMinutes()->withoutOverlapping(10);
    Schedule::command('drafts:follow-up')->everyFiveMinutes()->withoutOverlapping(10);
}
