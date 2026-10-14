<?php

namespace Tests\Feature;

use App\Models\AiCredit;
use App\Models\ConversationReport;
use App\Models\DevUsage;
use App\Support\HubCosts;
use App\Support\HubSignature;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Toplu maliyet: yerel denemelerin maliyeti imzalı istekle hub'a gelir; bakiye = girilen − sonraki harcama. */
class HubCostsTest extends TestCase
{
    use DatabaseTransactions;

    private array $row = ['model' => 'claude-sonnet-4-5', 'input_tokens' => '1000', 'output_tokens' => '200',
        'cache_write_tokens' => '0', 'cache_read_tokens' => '0', 'cost_usd' => '0.012'];

    public function test_signed_dev_usage_is_stored_unsigned_rejected(): void
    {
        config(['services.dev_usage.secret' => 'dev-secret']);
        $before = DevUsage::count();

        $this->post('/hub/dev-usage', $this->row, HubSignature::headers($this->row, 'wrong'))->assertForbidden();
        $this->post('/hub/dev-usage', $this->row)->assertForbidden();
        $this->post('/hub/dev-usage', $this->row, HubSignature::headers($this->row, 'dev-secret'))->assertOk();

        $this->assertSame($before + 1, DevUsage::count());
        $this->assertSame(0.012, DevUsage::latest('id')->first()->cost_usd);
    }

    public function test_no_secret_on_server_rejects_everything(): void
    {
        config(['services.dev_usage.secret' => null]);
        $this->post('/hub/dev-usage', $this->row, HubSignature::headers($this->row, ''))->assertForbidden();
    }

    public function test_credit_remaining_subtracts_spend_after_entry_only(): void
    {
        Carbon::setTestNow('2026-10-14 16:00');
        DevUsage::create(['model' => 'x', 'cost_usd' => 5])->forceFill(['created_at' => now()->subHour()])->save(); // önce: sayılmaz
        AiCredit::create(['balance_usd' => 21.83]);
        Carbon::setTestNow('2026-10-14 17:00');
        DevUsage::create(['model' => 'x', 'cost_usd' => 0.5]);
        ConversationReport::create(['report_date' => '2026-10-15', 'message_count' => 1, 'firm_count' => 1,
            'summary' => 's', 'body' => 'b', 'model' => 'x', 'cost_usd' => 0.33]);

        $credit = HubCosts::credit();
        $this->assertEqualsWithDelta(0.83, $credit['spent'], 0.0001);
        $this->assertEqualsWithDelta(21.0, $credit['remaining'], 0.0001);
        Carbon::setTestNow();
    }
}
