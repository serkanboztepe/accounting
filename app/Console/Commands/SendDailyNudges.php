<?php

namespace App\Console\Commands;

use App\Services\Whatsapp\WhatsappSender;
use App\Support\DailyNudge;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * Akşam mesajı (her gün 19:00, firma firma): "Bugün aklında bir şey var mı?" — kurallar DailyNudge'da.
 * Firma "Akşam mesajı" modülü kapalıysa hiç gitmez.
 *
 *   php artisan nudges:send            gönder
 *   php artisan nudges:send --dry-run  sadece kime gideceğini göster
 */
class SendDailyNudges extends Command
{
    protected $signature = 'nudges:send {--dry-run : Göndermeden göster}';

    protected $description = 'Akşam mesajı: bugün yazmamış numaralara "aklında bir şey var mı?" sorar';

    public function handle(WhatsappSender $sender): int
    {
        if (! Tenancy::current()) {
            $this->line('Firma seçili değil (tenants:run ile çalışır).');

            return self::SUCCESS;
        }
        if (! config('modules.daily_nudge')) {
            $this->line('Akşam mesajı bu firmada kapalı.');

            return self::SUCCESS;
        }

        foreach (DailyNudge::run(Tenancy::current()->id, $sender, (bool) $this->option('dry-run')) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
