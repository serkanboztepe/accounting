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
Schedule::command('backup:run')->daily()->at('03:00');
Schedule::command('backup:monitor')->daily()->at('04:00');
