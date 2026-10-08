<?php

namespace Tests\Feature;

use App\Models\HubFirm;
use App\Models\HubMessageLog;
use App\Models\HubPhone;
use App\Models\UsageLog;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\WhatsappSender;
use App\Support\HubSignature;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Maliyet takibi: AI token/$, şablon mesaj, hub mesaj sayıları, firma özet ucu. */
class CostTrackingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_ai_cost_is_computed_from_usage_and_price_table(): void
    {
        UsageLog::recordAi('claude-sonnet-5', [
            'input_tokens' => 4000, 'output_tokens' => 500,
            'cache_creation_input_tokens' => 1000, 'cache_read_input_tokens' => 10000,
        ]);

        // (4000×2 + 500×10 + 1000×2×1,25 + 10000×2×0,1) / 1M
        $this->assertEqualsWithDelta(0.0175, UsageLog::latest('id')->first()->cost_usd, 0.000001);
    }

    public function test_extractor_logs_every_ai_call(): void
    {
        config(['services.anthropic.api_key' => 'sk-test', 'services.anthropic.model' => 'claude-sonnet-5']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'tool_use', 'name' => 'save_entry', 'input' => ['kind' => 'balance_query', 'amount' => 0]]],
            'usage' => ['input_tokens' => 3000, 'output_tokens' => 200],
        ])]);
        $before = UsageLog::where('type', UsageLog::TYPE_AI)->count();

        app(ExpenseExtractor::class)->extract('Ali\'ye borcum ne');

        $this->assertSame($before + 1, UsageLog::where('type', UsageLog::TYPE_AI)->count());
        $this->assertSame(3000, UsageLog::latest('id')->first()->input_tokens);
    }

    public function test_sent_template_is_logged_with_cost(): void
    {
        config(['services.twilio.sid' => 'ACx', 'services.twilio.token' => 't', 'services.whatsapp.from' => '+14786665916']);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        app(WhatsappSender::class)->sendTemplate('05453606783', 'HXx', ['1' => 'a'], 'cek_hatirlatma');

        $log = UsageLog::latest('id')->first();
        $this->assertSame(UsageLog::TYPE_WA_TEMPLATE, $log->type);
        $this->assertSame('cek_hatirlatma', $log->model);
        $this->assertGreaterThan(0, $log->cost_usd);
    }

    public function test_hub_counts_in_and_out_messages_per_firm(): void
    {
        config(['app.role' => 'hub', 'services.twilio.verify_signature' => false]);
        $firm = HubFirm::create(['name' => 'Test Firma', 'url' => 'https://firma.test']);
        HubPhone::create(['phone' => '05321234567', 'hub_firm_id' => $firm->id]);
        Http::fake(['firma.test/*' => Http::response('<?xml version="1.0"?><Response><Message>ok</Message></Response>')]);

        $this->post('/whatsapp/webhook', ['From' => 'whatsapp:+905321234567', 'Body' => 'x'])->assertOk();
        $this->post('/whatsapp/webhook', ['From' => 'whatsapp:+905009990000', 'Body' => 'x'])->assertOk();

        $this->assertSame(1, HubMessageLog::where('hub_firm_id', $firm->id)->where('direction', 'in')->count());
        $this->assertSame(1, HubMessageLog::where('hub_firm_id', $firm->id)->where('direction', 'out')->count());
        $this->assertSame(2, HubMessageLog::whereNull('hub_firm_id')->where('phone', '905009990000')->count());
    }

    public function test_firm_usage_endpoint_requires_hub_signature(): void
    {
        config(['services.hub.secret' => 'firma-sirri']);
        UsageLog::recordAi('claude-sonnet-5', ['input_tokens' => 1000, 'output_tokens' => 100]);
        $params = ['month' => now()->format('Y-m')];

        $this->get('/hub/usage?month=' . $params['month'])->assertForbidden();

        $res = $this->withHeaders(HubSignature::headers($params, 'firma-sirri'))
            ->get('/hub/usage?month=' . $params['month'])
            ->assertOk()->json();

        $this->assertGreaterThanOrEqual(1, $res['ai_calls']);
        $this->assertArrayHasKey('template_cost_usd', $res);
    }

    public function test_hub_fetches_firm_usage(): void
    {
        $firm = HubFirm::create(['name' => 'Test Firma', 'url' => 'https://firma.test']);
        Http::fake(['firma.test/hub/usage*' => Http::response(['ai_calls' => 7, 'ai_cost_usd' => 0.12])]);

        $this->assertSame(7, $firm->fetchUsage('2026-10')['ai_calls']);
        Http::assertSent(fn ($r) => HubSignature::verify(
            ['month' => '2026-10'], $firm->secret,
            $r->header(HubSignature::HEADER)[0], $r->header(HubSignature::TIMESTAMP_HEADER)[0],
        ));
    }
}
