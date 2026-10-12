<?php

namespace App\Console\Commands;

use App\Models\HubPhone;
use App\Models\Product;
use App\Models\Reminder;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\WhatsappSender;
use App\Support\StockUsage;
use App\Support\TemplateApproval;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Her sabah 08:00 (firma firma): önce "otomatik stoktan düş" açık ürünlerin günlük kullanımı stoktan
 * düşülür, sonra eşiğe inen ürünler tek WhatsApp mesajında "bitmek üzere" diye bildirilir
 * (ürün başına bir kez; mal girişiyle eşiğin üstüne çıkınca yeniden haber verilir).
 *
 *   php artisan stock:remind            düş + gönder
 *   php artisan stock:remind --dry-run  hiçbir şey yazmadan bitmek üzere olanları göster
 */
class SendStockReminders extends Command
{
    protected $signature = 'stock:remind {--dry-run : Düşme ve gönderme yapmadan göster}';

    protected $description = 'Günlük kullanımı stoktan düşer, bitmek üzere olan ürünleri WhatsApp ile bildirir';

    public function handle(WhatsappSender $sender): int
    {
        if (! config('modules.stock')) {
            $this->info('Stok modülü kapalı.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');

        if (! $dry) {
            foreach (StockUsage::book() as $row) {
                $this->line("Düşüldü: {$row['product']} −" . StockUsage::quantity($row['quantity']) . " ({$row['days']} gün)");
            }
        }

        $items = StockUsage::newlyLow(! $dry);
        if ($items->isEmpty()) {
            $this->info('Bitmek üzere ürün yok.');

            return self::SUCCESS;
        }

        $lines = $items->map(fn ($i) => StockUsage::line($i))->values()->all();
        $plainText = "📦 *Bitmek üzere*\n• " . implode("\n• ", $lines);
        $this->line($plainText);

        if ($dry) {
            return self::SUCCESS;
        }

        $today = Carbon::now(Reminder::TIMEZONE)->toDateString();
        if (! Cache::add(Tenancy::key('stock-reminder:' . $today), true, now()->addDays(2))) {
            $this->line('Bugün zaten gönderildi.');

            return self::SUCCESS;
        }

        $contentSid = (string) config('services.whatsapp.reminder_content_sid');
        $useTemplate = TemplateApproval::approved($contentSid);
        $sent = false;

        foreach ($this->recipients() as $phone) {
            $ok = $useTemplate
                ? $sender->sendTemplate($phone, $contentSid, ['1' => 'Bitmek üzere — ' . implode(' · ', $lines)], 'hatirlatma')
                : $sender->sendText($phone, $plainText . "\n\nAldığında yazman yeterli (ör. *Ak Un'dan 20 çuval un aldım*).");
            $this->line('  → ' . $phone . ($ok ? ' ✓' : ' ✗ (log)'));

            if ($ok) {
                $sent = true;
                WhatsappMessage::create(['phone' => $phone, 'direction' => 'out', 'body' => $plainText, 'kind' => 'stock_reminder']);
            }
        }

        // Kimseye gidemediyse işaretleme: yarın tekrar denensin.
        if ($sent) {
            Product::whereIn('id', $items->pluck('product.id'))->update(['low_stock_alerted_at' => now()]);
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
