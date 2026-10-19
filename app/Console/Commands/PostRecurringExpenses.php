<?php

namespace App\Console\Commands;

use App\Models\RecurringExpense;
use Illuminate\Console\Command;

/**
 * Her sabah (firma firma): zamanı gelen düzenli giderleri yazar (ekmek her gün, kira her ayın 1'i). Kaçan günler telafi
 * edilir, aynı gün iki kez yazılmaz (expenses.recurring_expense_id + expense_date tekil).
 *
 *   php artisan expenses:recurring
 */
class PostRecurringExpenses extends Command
{
    protected $signature = 'expenses:recurring';

    protected $description = 'Zamanı gelen düzenli giderleri kaydeder';

    public function handle(): int
    {
        if (! config('modules.expenses')) {
            $this->info('Gider modülü kapalı.');

            return self::SUCCESS;
        }
        $total = 0;
        foreach (RecurringExpense::where('is_active', true)->get() as $r) {
            $n = $r->post();
            if ($n > 0) {
                $this->line("Yazıldı: {$r->description} ×{$n}");
            }
            $total += $n;
        }
        $this->info("Toplam {$total} gider.");

        return self::SUCCESS;
    }
}
