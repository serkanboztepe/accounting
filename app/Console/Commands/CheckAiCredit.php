<?php

namespace App\Console\Commands;

use App\Support\CreditAlert;
use Illuminate\Console\Command;

/**
 * Tahmini Anthropic bakiyesi 5 $ altına inince yöneticiye WhatsApp (günde en çok 1). Merkezde, saatlik.
 *
 *   php artisan ai:credit-check
 */
class CheckAiCredit extends Command
{
    protected $signature = 'ai:credit-check';

    protected $description = 'Tahmini AI kredisi azaldıysa yöneticiyi uyarır';

    public function handle(): int
    {
        $remaining = CreditAlert::checkEstimate();
        $this->info($remaining === null ? 'Girilmiş bakiye yok.' : 'Tahmini kalan: ' . round($remaining, 2) . ' $');

        return self::SUCCESS;
    }
}
