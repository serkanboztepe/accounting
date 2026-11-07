<?php

namespace App\Console\Commands;

use App\Models\Reminder;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\WhatsappSender;
use App\Support\SalaryFollowUp;
use App\Support\TemplateApproval;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * "Maaş ödemesi görünmüyor" sorusu (plan v4 madde 10) — her sabah 08:10, firma firma. Maaş gününden 2 gün sonra ödeme yoksa sorulur,
 * cevap gelmezse haftada bir, 3 cevapsızda durur. "hatirlatma" şablonuyla gider (24 saat dışı da teslim).
 *
 *   php artisan salaries:follow-up            gönder
 *   php artisan salaries:follow-up --dry-run  göstermekle kal
 */
class SendSalaryFollowUps extends Command
{
    protected $signature = 'salaries:follow-up {--dry-run : Göndermeden göster}';

    protected $description = 'Maaşı yazılıp ödemesi görünmeyen personeli sorar';

    public function handle(WhatsappSender $sender): int
    {
        $dry = (bool) $this->option('dry-run');
        $contentSid = (string) config('services.whatsapp.reminder_content_sid');
        $useTemplate = TemplateApproval::approved($contentSid);
        $today = now(Reminder::TIMEZONE)->startOfDay();

        foreach (SalaryFollowUp::due($today) as $phone => $items) {
            $plain = SalaryFollowUp::text($items);
            $this->line($phone . ': ' . str_replace("\n", ' / ', $plain));
            if ($dry) {
                continue;
            }
            if (SalaryFollowUp::busy($phone)) {
                $this->line('  açık kaydı var — bugün sorulmadı (sayılmaz)');

                continue;
            }
            if (! Cache::add(Tenancy::key('salary-followup:' . $phone . ':' . $today->toDateString()), true, now()->addDays(2))) {
                $this->line('  bugün zaten soruldu');

                continue;
            }
            $ok = $useTemplate
                ? $sender->sendTemplate($phone, $contentSid, ['1' => SalaryFollowUp::text($items, oneLine: true)], 'hatirlatma')
                : $sender->sendText($phone, $plain);
            $this->line('  → ' . ($ok ? '✓' : '✗ (log)'));
            if ($ok) {
                WhatsappMessage::create(['phone' => $phone, 'direction' => 'out', 'body' => $plain, 'kind' => SalaryFollowUp::KIND]);
                SalaryFollowUp::markAsked($phone, $items, $plain, $today);
            }
        }

        return self::SUCCESS;
    }
}
