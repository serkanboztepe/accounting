<?php

namespace App\Console\Commands;

use App\Models\HubPhone;
use App\Models\Reminder;
use App\Models\Sale;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\WhatsappSender;
use App\Support\TemplateApproval;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Akşam ciro sorusu (21:00, firma firma) — yalnız "Akşam ciro sorusu" (revenue_prompt) açık firmada.
 * O gün peşin satış girildiyse sorulmaz. Cevap ("10 bin nakit 15 bin kart") WhatsApp'ta peşin
 * ürünsüz satış olur (trade_sale).
 *
 *   php artisan revenue:prompt            gönder
 *   php artisan revenue:prompt --dry-run  göndermeden göster
 */
class SendRevenuePrompt extends Command
{
    protected $signature = 'revenue:prompt {--dry-run : Göndermeden göster}';

    protected $description = 'Akşam 21:00 "bugünkü ciro ne?" sorusunu WhatsApp ile gönderir';

    public const TEXT = 'Bugünkü ciro ne kadar? Nakit ve kartı ayrı yazabilirsin (ör. "10 bin nakit 15 bin kart").';

    public function handle(WhatsappSender $sender): int
    {
        if (! config('modules.revenue_prompt') || ! config('modules.sales_desk')) {
            $this->info('Akşam ciro sorusu kapalı.');

            return self::SUCCESS;
        }

        $today = Carbon::now(Reminder::TIMEZONE)->toDateString();
        if (Sale::whereNull('party_id')->whereDate('sale_date', $today)->exists()) {
            $this->info('Bugün peşin satış girilmiş, sorulmadı.');

            return self::SUCCESS;
        }

        $this->line(self::TEXT);
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        if (! Cache::add(Tenancy::key('revenue-prompt:' . $today), true, now()->addDays(2))) {
            $this->line('Bugün zaten soruldu.');

            return self::SUCCESS;
        }

        $contentSid = (string) config('services.whatsapp.reminder_content_sid');
        $useTemplate = TemplateApproval::approved($contentSid);

        foreach ($this->recipients() as $phone) {
            $ok = $useTemplate
                ? $sender->sendTemplate($phone, $contentSid, ['1' => self::TEXT], 'hatirlatma')
                : $sender->sendText($phone, '🧾 ' . self::TEXT);
            $this->line('  → ' . $phone . ($ok ? ' ✓' : ' ✗ (log)'));
            if ($ok) {
                WhatsappMessage::create(['phone' => $phone, 'direction' => 'out', 'body' => self::TEXT, 'kind' => 'revenue_prompt']);
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
