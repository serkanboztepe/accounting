<?php

namespace App\Console\Commands;

use App\Models\HubPhone;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\DraftFollowUp;
use App\Services\Whatsapp\WhatsappSender;
use App\Support\Phone;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * Yarım kalan WhatsApp kayıtlarını hatırlatır (5 dakikada bir, firma firma — tenants:run):
 * 30 dk sessizlikte bir kez, ertesi gün bir kez daha; 24 saat penceresi dışında ve gece gönderilmez.
 * AI kullanılmaz; mesaj başına Twilio ücreti var.
 *
 *   php artisan drafts:follow-up            gönder
 *   php artisan drafts:follow-up --dry-run  sadece ne gideceğini göster
 */
class FollowUpDrafts extends Command
{
    protected $signature = 'drafts:follow-up {--dry-run : Göndermeden göster}';

    protected $description = 'Yarım kalan WhatsApp kayıtlarını hatırlatır';

    public function handle(WhatsappSender $sender): int
    {
        $dry = (bool) $this->option('dry-run');

        foreach (DraftFollowUp::dueForMessage() as $draft) {
            $text = DraftFollowUp::messageText($draft);
            $this->line("{$draft->phone} (#{$draft->id}): {$text}");
            if ($dry) {
                continue;
            }
            if (! $this->phoneStillActive($draft->phone)) {
                $draft->update(['status' => 'superseded']);
                $this->line('  numara artık bu firmada değil, kapatıldı');

                continue;
            }

            // Önce işaretle: gönderim yarıda kalsa da aynı taslağa üst üste mesaj gitmesin.
            DraftFollowUp::markSent($draft);
            if ($sender->sendText($draft->phone, $text)) {
                WhatsappMessage::create(['phone' => Phone::normalize($draft->phone), 'direction' => 'out', 'body' => $text, 'kind' => 'draft_followup']);
                $this->line('  ✓');
            } else {
                $this->line('  ✗ gönderilemedi (log)');
            }
        }

        return self::SUCCESS;
    }

    /** Tek panel: numara hub'dan silindiyse / başka firmaya geçtiyse artık gönderme. */
    private function phoneStillActive(string $phone): bool
    {
        if (! Tenancy::enabled() || ! Tenancy::current()) {
            return true;
        }

        return HubPhone::where('phone', Phone::normalize($phone))
            ->where('hub_firm_id', Tenancy::current()->id)
            ->where('is_active', true)
            ->exists();
    }
}
