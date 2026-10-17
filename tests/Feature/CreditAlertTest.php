<?php

namespace Tests\Feature;

use App\Support\CreditAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Faz 5: kredi bitince yöneticiye hemen WhatsApp (saatte 1); başka API hatasında sessiz. */
class CreditAlertTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.admin_phones' => ['905550000001'], 'services.twilio.sid' => 'ACx', 'services.twilio.token' => 't',
            'services.whatsapp.from' => '+14786665916']);
        Cache::forget('ai-credit-empty');
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    }

    public function test_credit_error_alerts_admin_once_per_hour(): void
    {
        $body = '{"type":"error","error":{"type":"invalid_request_error","message":"Your credit balance is too low to access the Anthropic API."}}';
        CreditAlert::fromApiError($body);
        CreditAlert::fromApiError($body);

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains((string) ($r['Body'] ?? ''), 'Anthropic kredisi bitti'));
    }

    public function test_other_api_errors_are_silent(): void
    {
        CreditAlert::fromApiError('{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}');

        Http::assertNothingSent();
    }
}
