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
    Schedule::command('tenants:run "checks:remind"')->dailyAt('09:00')->timezone('Europe/Istanbul');
    // WhatsApp hatırlatmaları ("20 Ekim düğün çekimim var", "her ayın 10'u kart ödemesi").
    Schedule::command('tenants:run "reminders:send"')->everyFiveMinutes()->withoutOverlapping(10);
    // Günlük konuşma analizi: dünün konuşmaları → numaralı rapor (hub) + yöneticiye WhatsApp özeti.
    Schedule::command('conversations:analyze')->dailyAt('08:30')->timezone('Europe/Istanbul');
    // WhatsApp konuşma kaydı 90 günden eskiyse silinir (App\Models\WhatsappMessage tek Prunable model).
    Schedule::command('tenants:run "model:prune"')->daily()->at('04:30');
} else {
    // Çek vadesi hatırlatması (WhatsApp). Ayarlı değilse komut sessizce çıkar.
    Schedule::command('checks:remind')->dailyAt('09:00')->timezone('Europe/Istanbul');
    Schedule::command('model:prune')->daily()->at('04:30');
    Schedule::command('reminders:send')->everyFiveMinutes()->withoutOverlapping(10);
}
