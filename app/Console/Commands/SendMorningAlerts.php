<?php

namespace App\Console\Commands;

use App\Models\HubMessageLog;
use App\Models\Reminder;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\WhatsappSender;
use App\Support\DailyNudge;
use App\Support\MorningAlerts;
use App\Support\TemplateApproval;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Faz 4 (B): sabah 09:00 kendiliğinden haber (firma firma — tenants:run). Modül `proactive_alerts` kapalıysa hiçbir şey
 * yapmaz. Kişi başına günde tek mesaj; son 24 saatte yazana metin, yazmayana onaylı hatırlatma şablonu.
 *
 *   php artisan alerts:morning              gönder
 *   php artisan alerts:morning --dry-run    yalnız göster
 */
class SendMorningAlerts extends Command
{
    protected $signature = 'alerts:morning {--dry-run : Göndermeden göster} {--date= : Gün (Y-m-d), varsayılan bugün}';

    protected $description = 'Girilmeyen kasa + Pazartesi haftalık özet (kendiliğinden haber)';

    public function handle(WhatsappSender $sender): int
    {
        if (! config('modules.proactive_alerts')) {
            $this->info('Kendiliğinden haber modülü kapalı.');

            return self::SUCCESS;
        }
        $today = $this->option('date') ? Carbon::parse($this->option('date'), Reminder::TIMEZONE) : Carbon::now(Reminder::TIMEZONE);
        $dry = (bool) $this->option('dry-run');
        $contentSid = (string) config('services.whatsapp.reminder_content_sid');

        foreach (MorningAlerts::build($today->copy()->startOfDay()) as $phone => $lines) {
            $text = implode("\n\n", $lines);
            $this->line("→ {$phone}:\n{$text}\n");
            if ($dry || ! Cache::add(Tenancy::key('morning-alert:' . $today->toDateString() . ':' . $phone), true, now()->addDays(2))) {
                continue;
            }
            $ok = DailyNudge::inWindow(DailyNudge::lastInbound($phone), now())
                ? $sender->sendText($phone, $text)
                : (TemplateApproval::approved($contentSid)
                    && $sender->sendTemplate($phone, $contentSid, ['1' => mb_substr(preg_replace('/\s*\n+\s*/u', ' · ', str_replace('*', '', $text)), 0, 900)], 'hatirlatma'));
            if ($ok) {
                WhatsappMessage::create(['phone' => $phone, 'direction' => 'out', 'body' => $text, 'kind' => 'reminder']);
                if ($firm = Tenancy::current()) {
                    HubMessageLog::create(['hub_firm_id' => $firm->id, 'phone' => $phone, 'direction' => 'out']);
                }
            }
            $this->line($ok ? '  ✓ gönderildi' : '  ✗ gönderilemedi (pencere dışı ve şablon yok ya da hata)');
        }

        return self::SUCCESS;
    }
}
