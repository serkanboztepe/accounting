<?php

namespace App\Console\Commands;

use App\Models\HubPhone;
use App\Models\Reminder;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\WhatsappSender;
use App\Support\ConversationContext;
use App\Support\Phone;
use App\Support\Reminders;
use App\Support\TemplateApproval;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * Zamanı gelen WhatsApp hatırlatmalarını gönderir (5 dakikada bir, firma firma — tenants:run).
 * Onaylı "hatirlatma" şablonu varsa onunla (24 saat penceresi dışına da gider), yoksa serbest metin.
 * Gönderilen hatırlatma konuşma kaydına yazılır ve "son konuşulan" bağlam olur: "aldım" / "tamam" /
 * "yarın tekrar hatırlat" cevapları ona bağlanır.
 *
 *   php artisan reminders:send            gönder
 *   php artisan reminders:send --dry-run  sadece ne gideceğini göster
 */
class SendReminders extends Command
{
    protected $signature = 'reminders:send {--dry-run : Göndermeden göster}';

    protected $description = 'Zamanı gelen WhatsApp hatırlatmalarını gönderir';

    private const MAX_ATTEMPTS = 3;

    public function handle(WhatsappSender $sender): int
    {
        $dry = (bool) $this->option('dry-run');

        // Gönderirken süreç öldüyse 'sending'de takılı kalmasın.
        Reminder::where('status', 'sending')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'active']);

        $due = Reminder::where('status', 'active')->whereNotNull('next_fire_at')->where('next_fire_at', '<=', now())
            ->with('party')->orderBy('next_fire_at')->get();

        foreach ($due as $reminder) {
            // Sunucu kapalı kaldıysa aynı olayın birikmiş hatırlatmalarını art arda gönderme: geçmiş olanların sonuncusu.
            while (($later = $reminder->nextAlarmAfter($reminder->next_fire_at)) && $later[0]->lte(now())) {
                $reminder->next_fire_at = $later[0];
                $reminder->event_date = $later[1]->toDateString();
            }

            $text = Reminders::fireText($reminder, $reminder->next_fire_at);
            $this->line("{$reminder->phone}: {$text}");
            if ($dry) {
                continue;
            }

            // Sahiplen: iki süreç aynı anda çalışırsa ikinci gönderemesin.
            if (! Reminder::whereKey($reminder->id)->where('status', 'active')->update(['status' => 'sending'])) {
                continue;
            }
            // Veritabanında artık 'sending'; modelin "orijinali" de öyle olsun ki sonda 'active'/'done' gerçekten
            // yazılsın (yoksa değişmemiş sayılıp ikinci hatırlatması olan kayıt 'sending'de takılı kalıyordu).
            $reminder->status = 'sending';
            $reminder->syncOriginalAttribute('status');
            $reminder->status = 'active';

            if (! $this->phoneStillActive($reminder->phone)) {
                $reminder->forceFill(['status' => 'cancelled'])->save();
                $this->line('  numara artık bu firmada değil, iptal edildi');

                continue;
            }

            $firedAt = $reminder->next_fire_at;
            if ($this->deliver($sender, $reminder->phone, $text)) {
                $reminder->last_sent_at = now();
                $reminder->attempts = 0;
                $reminder->scheduleNext($firedAt);
                $reminder->save();

                WhatsappMessage::create(['phone' => $reminder->phone, 'direction' => 'out', 'body' => "⏰ Hatırlatma: {$text}", 'kind' => 'reminder']);
                ConversationContext::remember($reminder->phone, $reminder->party_id, $reminder->text, Reminder::REPLY_WINDOW_HOURS * 60);
                $this->line('  ✓' . ($reminder->next_fire_at ? ' sonraki: ' . Reminders::when($reminder->next_fire_at) : ''));

                continue;
            }

            // Gönderilemedi: 3 denemeden sonra bu hatırlatma anını atla (hatırlatmanın tamamını değil).
            $reminder->attempts++;
            if ($reminder->attempts >= self::MAX_ATTEMPTS) {
                $reminder->attempts = 0;
                $reminder->scheduleNext($firedAt);
            }
            $reminder->save();
            $this->line('  ✗ gönderilemedi (log)');
        }

        return self::SUCCESS;
    }

    private function deliver(WhatsappSender $sender, string $phone, string $text): bool
    {
        $contentSid = (string) config('services.whatsapp.reminder_content_sid');

        return TemplateApproval::approved($contentSid)
            ? $sender->sendTemplate($phone, $contentSid, ['1' => $text], 'hatirlatma')
            : $sender->sendText($phone, "⏰ Hatırlatma: {$text}\n\nİşin bitince *tamam* yaz. Para geldiyse ya da ödediysen yazman yeterli (ör. *aldım*).");
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
