<?php

namespace Tests\Feature;

use App\Models\Check;
use App\Models\Party;
use App\Support\CheckReminders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Çek vadesi hatırlatması (şablon mesaj) + "çekler" cevabı. */
class CheckRemindersTest extends TestCase
{
    use DatabaseTransactions;

    private Party $party;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'modules.checks' => true,
            'services.twilio.sid' => 'ACtest',
            'services.twilio.token' => 'tok',
            'services.twilio.verify_signature' => false,
            'services.twilio.allowed_phones' => ['05453606783'],
            'services.whatsapp.from' => '+14786665916',
            'services.whatsapp.check_reminder_content_sid' => 'HXtest',
            'services.whatsapp.reminder_phones' => ['0545 360 67 83'],
            'services.whatsapp.check_reminder_days' => [3, 0],
        ]);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201), '*' => Http::response('', 200)]);
        // Testte dev verisindeki gerçek çekler karışmasın.
        Check::query()->update(['status' => 'paid']);
        $this->party = Party::create(['name' => 'Deniz Beton Test']);
    }

    private function check(int $daysFromToday, float $amount, string $status = 'issued', array $extra = []): Check
    {
        return Check::create(array_merge([
            'party_id' => $this->party->id,
            'due_date' => CheckReminders::today()->addDays($daysFromToday)->toDateString(),
            'amount' => $amount,
            'status' => $status,
            'check_number' => 'T' . random_int(1000, 9999),
        ], $extra));
    }

    public function test_sends_template_for_checks_due_in_3_days_and_today(): void
    {
        $this->check(3, 100000);
        $this->check(3, 20000.5);
        $this->check(0, 5000);
        $this->check(3, 999, 'paid');      // ödenmiş → sayılmaz
        $this->check(5, 777);              // 5 gün → bugün hatırlatılmaz

        $this->artisan('checks:remind')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(function (ClientRequest $r) {
            $vars = json_decode($r['ContentVariables'], true);

            return str_contains($r->url(), '/Accounts/ACtest/Messages.json')
                && $r['To'] === 'whatsapp:+905453606783'
                && $r['From'] === 'whatsapp:+14786665916'
                && $r['ContentSid'] === 'HXtest'
                && $vars['2'] === '2' && $vars['3'] === '120.000,50'
                && str_ends_with($vars['1'], 'tarihinde');
        });
        Http::assertSent(fn (ClientRequest $r) => json_decode($r['ContentVariables'], true)['1'] === 'bugün');
    }

    public function test_does_not_send_twice_same_day(): void
    {
        $this->check(0, 5000);

        $this->artisan('checks:remind')->assertSuccessful();
        $this->artisan('checks:remind')->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_dry_run_and_missing_config_send_nothing(): void
    {
        $this->check(0, 5000);

        $this->artisan('checks:remind --dry-run')->assertSuccessful();
        config(['services.whatsapp.check_reminder_content_sid' => null]);
        $this->artisan('checks:remind')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_received_customer_checks_are_not_reminded(): void
    {
        $entry = \App\Models\PartyLedgerEntry::create([
            'party_id' => $this->party->id, 'entry_date' => now(), 'type' => \App\Models\PartyLedgerEntry::TYPE_COLLECTION,
            'payment_type' => 'check', 'amount' => 8000, 'description' => 'Müşteri çeki',
        ]);
        $this->check(0, 8000, 'issued', ['party_ledger_entry_id' => $entry->id]);

        $this->artisan('checks:remind')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_cekler_reply_lists_upcoming_and_overdue_checks(): void
    {
        $this->check(-2, 3000);
        $this->check(4, 45000);
        $this->check(30, 1);  // 14 günden sonra → listede yok

        $res = $this->post('/whatsapp/webhook', ['From' => 'whatsapp:+905453606783', 'Body' => 'Çekler', 'NumMedia' => 0])
            ->assertOk()->getContent();

        $this->assertStringContainsString('Deniz Beton Test', $res);
        $this->assertStringContainsString('gecikmiş', $res);
        $this->assertStringContainsString('48.000,00', $res);
        $this->assertStringNotContainsString('1,00 ₺', $res);
    }
}
