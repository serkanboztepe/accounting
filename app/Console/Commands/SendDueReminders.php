<?php

namespace App\Console\Commands;

use App\Models\HubPhone;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\WhatsappSender;
use App\Support\ConversationContext;
use App\Support\DueItems;
use App\Support\Reminders;
use App\Support\TemplateApproval;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Ödeme günü hatırlatması (her sabah 08:00, firma firma): ödeme tarihi YARIN ve BUGÜN olan gider,
 * sözleşme ödemesi, cari satış (tahsilat) ve alış/borç kayıtları tek mesajda. Ödendiyse listelenmez
 * (DueItems). Kime: firmada "hatırlatma alsın" işaretli numaralar; hiç işaretli yoksa firmanın tüm
 * aktif numaraları (çoğu firma işaretlemedi, kimseye gitmemesin).
 *
 *   php artisan dues:remind            gönder
 *   php artisan dues:remind --dry-run  sadece ne gideceğini göster
 */
class SendDueReminders extends Command
{
    protected $signature = 'dues:remind {--dry-run : Göndermeden göster}';

    protected $description = 'Ödeme tarihi bugün ve yarın olan işleri WhatsApp ile hatırlatır';

    public function handle(WhatsappSender $sender): int
    {
        $today = Carbon::now(Reminders::TIMEZONE)->startOfDay();
        $items = DueItems::between($today, $today->copy()->addDay());

        if ($items->isEmpty()) {
            $this->info('Bugün ve yarın ödeme günü yok.');

            return self::SUCCESS;
        }

        $parts = [];
        foreach (['Bugün' => $today, 'Yarın' => $today->copy()->addDay()] as $label => $day) {
            $lines = $items->where('date', $day->toDateString())->map(fn ($i) => DueItems::line($i));
            if ($lines->isNotEmpty()) {
                $parts[$label] = $lines->values()->all();
            }
        }

        // Şablon değişkeni tek satır; serbest metin satır satır.
        $templateText = 'Ödeme günleri — ' . collect($parts)->map(fn ($lines, $label) => $label . ': ' . implode(' · ', $lines))->implode(' | ');
        $plainText = "⏰ *Ödeme günleri*\n" . collect($parts)->map(fn ($lines, $label) => "*{$label}:*\n• " . implode("\n• ", $lines))->implode("\n");

        $this->line($plainText);
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        // Aynı gün iki kez çalışırsa (elle + zamanlanmış) tekrar gönderme.
        if (! Cache::add(Tenancy::key('due-reminder:' . $today->toDateString()), true, now()->addDays(2))) {
            $this->line('Bugün zaten gönderildi.');

            return self::SUCCESS;
        }

        $contentSid = (string) config('services.whatsapp.reminder_content_sid');
        $useTemplate = TemplateApproval::approved($contentSid);
        // Tek kalem + cari → "aldım" / "ödedim" cevabı o cariye bağlansın.
        $single = $items->count() === 1 ? $items->first() : null;

        foreach ($this->recipients() as $phone) {
            $ok = $useTemplate
                ? $sender->sendTemplate($phone, $contentSid, ['1' => $templateText], 'odeme_gunu')
                : $sender->sendText($phone, $plainText . "\n\nPara geldiyse ya da ödediysen yazman yeterli (ör. *Ali 20 bin ödedi*).");
            $this->line('  → ' . $phone . ($ok ? ' ✓' : ' ✗ (log)'));

            if ($ok) {
                WhatsappMessage::create(['phone' => $phone, 'direction' => 'out', 'body' => $plainText, 'kind' => 'due_reminder']);
                if ($single && $single['party_id']) {
                    ConversationContext::remember($phone, $single['party_id'], $single['title'], 12 * 60);
                }
            }
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function recipients(): array
    {
        $phones = config('services.whatsapp.reminder_phones', []);
        if ($phones === [] && Tenancy::current()) {
            $phones = HubPhone::where('hub_firm_id', Tenancy::current()->id)->where('is_active', true)->pluck('phone')->all();
        }

        return array_values(array_unique($phones));
    }
}
