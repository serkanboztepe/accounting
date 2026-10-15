<?php

namespace Tests\Feature\Trade;

use App\Models\Expense;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\TradeDraft;
use App\Support\MonthlyResult;
use App\Support\TradeDesk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Akkuş (13-15 Ekim, 17 kez): "bugün / dün / 14.10 / iki günlük ciro" o güne göre cevaplanır, ay sonucu değil. */
class DailyRevenueTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.stock' => true, 'modules.expenses' => true, 'modules.report_monthly' => true, 'modules.sales_desk' => true]);
        Carbon::setTestNow(Carbon::parse('2031-03-15 22:00', 'Europe/Istanbul'));
        TradeDesk::saveSale(['mode' => 'cash', 'payment' => 'mixed', 'cash_part' => '5000', 'manual_amount' => '24483', 'sale_date' => '2031-03-14']);
        TradeDesk::saveSale(['mode' => 'cash', 'manual_amount' => '21845', 'sale_date' => '2031-03-15']);
        TradeDesk::saveSale(['mode' => 'cash', 'manual_amount' => '1000', 'sale_date' => '2031-03-02']);
        Expense::create(['expense_date' => '2031-03-14', 'amount' => 1630, 'payment_status' => 'paid', 'description' => 'Ergenekon gideri']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ask(?string $from, ?string $to = null): string
    {
        return TradeDraft::answer(['kind' => ExpenseExtractor::KIND_MONTH_RESULT, 'date_from' => $from, 'date_to' => $to, 'month' => null]);
    }

    public function test_single_day(): void
    {
        $r = MonthlyResult::forRange(Carbon::parse('2031-03-14'), Carbon::parse('2031-03-14'));
        $this->assertSame('14 Mart', $r['label']);
        $text = $this->ask('2031-03-14', '2031-03-14');
        $this->assertStringContainsString('14 Mart sonucu', $text);
        $this->assertStringContainsString('Ciro: 24.483,00', $text);
        $this->assertStringContainsString('Gider: 1.630,00', $text);
    }

    public function test_range_and_month_unchanged(): void
    {
        $two = MonthlyResult::forRange(Carbon::parse('2031-03-14'), Carbon::parse('2031-03-15'));
        $this->assertSame('14–15 Mart', $two['label']);
        $this->assertEqualsWithDelta(24483 + 21845, $two['revenue'], 0.01);

        $month = $this->ask(null);
        $this->assertStringContainsString('Mart 2031 sonucu (yaklaşık)', $month);
        $this->assertStringContainsString('Ciro: 47.328,00', $month);
    }

    public function test_after_midnight_today_means_previous_business_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2031-03-15 00:12', 'Europe/Istanbul'));
        $text = $this->ask('2031-03-15', '2031-03-15');
        $this->assertStringContainsString('14 Mart sonucu', $text);
        $this->assertStringContainsString('Gece yarısını geçtiği için dünü', $text);
        $this->assertStringContainsString('Ciro: 24.483,00', $text);
    }
}
