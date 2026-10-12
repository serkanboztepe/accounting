<?php

namespace Tests\Feature\Trade;

use App\Support\TradeDesk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Akşam ciro sorusu: yalnız ayarı açık firmada; o gün peşin satış varsa sorulmaz. */
class RevenuePromptTest extends TestCase
{
    use DatabaseTransactions;

    public function test_off_by_default(): void
    {
        config(['modules.revenue_prompt' => false, 'modules.sales_desk' => true]);
        $this->artisan('revenue:prompt', ['--dry-run' => true])->expectsOutputToContain('kapalı')->assertSuccessful();
    }

    public function test_asks_when_no_cash_sale_today_and_skips_after_one(): void
    {
        config(['modules.revenue_prompt' => true, 'modules.sales_desk' => true]);
        \App\Models\Sale::whereNull('party_id')->whereDate('sale_date', now()->toDateString())->delete();

        $this->artisan('revenue:prompt', ['--dry-run' => true])->expectsOutputToContain('Bugünkü ciro')->assertSuccessful();

        TradeDesk::saveSale(['mode' => 'cash', 'manual_amount' => '1000', 'sale_date' => now(\App\Models\Reminder::TIMEZONE)->toDateString()]);
        $this->artisan('revenue:prompt', ['--dry-run' => true])->expectsOutputToContain('sorulmadı')->assertSuccessful();
    }
}
