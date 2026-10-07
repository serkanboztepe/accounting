<?php

namespace App\Console\Commands;

use App\Support\CariLock;
use Illuminate\Console\Command;

/**
 * Unutulan Cari PIN'ini kaldırır. Sonrasında kilit panel giriş şifresini sorar;
 * kullanıcı Ayarlar → Cari PIN'den yeni PIN belirler.
 *
 *   php artisan cari:pin-reset
 */
class ResetCariPin extends Command
{
    protected $signature = 'cari:pin-reset';

    protected $description = 'Cari kilidi PIN\'ini kaldırır (unutulduğunda)';

    public function handle(): int
    {
        CariLock::setPin(null);
        $this->info('Cari PIN kaldırıldı. Kilit artık giriş şifresini soracak; Ayarlar → Cari PIN ile yenisi belirlenebilir.');

        return self::SUCCESS;
    }
}
