<?php

namespace App\Console\Commands;

use App\Services\Whatsapp\WhatsappSender;
use App\Support\Announcements;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * Bekleyen duyuruları gönder (kurallar Announcements'ta). Elle çalıştırılır:
 *   php artisan tenants:run "announcements:push --dry-run"   kime ne gideceğini göster
 *   php artisan tenants:run "announcements:push" --firm=1    gönder
 */
class PushAnnouncements extends Command
{
    protected $signature = 'announcements:push {--dry-run : Göndermeden göster}';

    protected $description = 'Bekleyen duyurular: 24 saat içinde yazana metin, yazmayana "yenilikler" şablonu';

    public function handle(WhatsappSender $sender): int
    {
        if (! Tenancy::current()) {
            $this->line('Firma seçili değil (tenants:run ile çalışır).');

            return self::SUCCESS;
        }

        foreach (Announcements::push(Tenancy::current()->id, $sender, (bool) $this->option('dry-run')) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
