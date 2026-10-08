<?php

namespace App\Console\Commands;

use App\Tenancy\Tenancy;
use App\Services\Whatsapp\WhatsappSender;
use App\Support\CheckReminders;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Çek vadesi hatırlatması (WhatsApp, Meta onaylı şablon). Her sabah zamanlanmış çalışır.
 *
 *   php artisan checks:remind            gönder
 *   php artisan checks:remind --dry-run  sadece ne gideceğini göster
 */
class SendCheckReminders extends Command
{
    protected $signature = 'checks:remind {--dry-run : Göndermeden göster}';

    protected $description = 'Vadesi yaklaşan çekler için WhatsApp hatırlatması gönderir';

    public function handle(WhatsappSender $sender): int
    {
        if (! config('modules.checks')) {
            $this->info('Çek modülü kapalı.');

            return self::SUCCESS;
        }

        $phones = config('services.whatsapp.reminder_phones', []);
        $contentSid = (string) config('services.whatsapp.check_reminder_content_sid');
        $dry = (bool) $this->option('dry-run');

        if (! $dry && ($phones === [] || $contentSid === '')) {
            $this->info('Hatırlatma ayarlı değil (WHATSAPP_REMINDER_PHONES / WHATSAPP_CHECK_REMINDER_SID).');

            return self::SUCCESS;
        }

        $today = CheckReminders::today();

        foreach (config('services.whatsapp.check_reminder_days', [3, 0]) as $offset) {
            $due = $today->copy()->addDays($offset);
            $checks = CheckReminders::dueOn($due);

            if ($checks->isEmpty()) {
                continue;
            }

            // Şablon: "📅 Çek hatırlatması: {{1}} vadesi gelen {{2}} çekin var, toplam {{3}} TL. …"
            $vars = [
                '1' => $offset === 0 ? 'bugün' : $due->format('d.m.Y') . ' tarihinde',
                '2' => (string) $checks->count(),
                '3' => Money::format($checks->sum('amount')),
            ];

            $this->line("{$due->format('d.m.Y')} (+{$offset} gün): {$vars['2']} çek, {$vars['3']} TL");

            if ($dry) {
                continue;
            }

            // Aynı gün iki kez çalışırsa (elle + zamanlanmış) tekrar gönderme.
            if (! Cache::add(Tenancy::key("check-reminder:{$today->toDateString()}:{$offset}"), true, now()->addDays(2))) {
                $this->line('  zaten gönderildi, atlandı');

                continue;
            }

            foreach ($phones as $phone) {
                $ok = $sender->sendTemplate($phone, $contentSid, $vars, 'cek_hatirlatma');
                $this->line('  → ' . $phone . ($ok ? ' ✓' : ' ✗ (log)'));
            }
        }

        return self::SUCCESS;
    }
}
