<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Support\DueItems;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ödeme günü hatırlatması: ödeme tarihi bugün/yarın olan işler — ödendiyse listelenmez,
 * kısmen ödendiyse güncel bakiye. "Şimdi": 2031-05-10 08:00 (geliştirme verisi karışmasın).
 */
class DueRemindersTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2031-05-10 08:00', 'Europe/Istanbul'));
        config([
            'modules.expenses' => true,
            'modules.contracts' => false,
            'services.twilio.sid' => 'ACtest',
            'services.twilio.token' => 'tok',
            'services.whatsapp.from' => '+14786665916',
            'services.whatsapp.reminder_content_sid' => 'HXreminder',
            'services.whatsapp.reminder_phones' => ['0545 360 67 83'],
        ]);
        Http::fake([
            'content.twilio.com/*' => Http::response(['whatsapp' => ['status' => 'pending']]),
            'api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ledger(Party $party, string $type, float $amount, ?string $due = null, string $date = '2031-05-01'): void
    {
        PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => $date, 'due_date' => $due, 'type' => $type, 'amount' => $amount, 'description' => 'Bal']);
    }

    public function test_lists_due_today_and_tomorrow_and_skips_paid_ones(): void
    {
        $ali = Party::create(['name' => 'Vade Ali']);          // 45 bin satış, 15 bin ödedi → güncel 30 bin
        $this->ledger($ali, PartyLedgerEntry::TYPE_SALE, 45000, '2031-05-10');
        $this->ledger($ali, PartyLedgerEntry::TYPE_COLLECTION, 15000);

        $veli = Party::create(['name' => 'Vade Veli']);        // satış tamamen ödendi → atlanır
        $this->ledger($veli, PartyLedgerEntry::TYPE_SALE, 10000, '2031-05-11');
        $this->ledger($veli, PartyLedgerEntry::TYPE_COLLECTION, 10000);

        $mehmet = Party::create(['name' => 'Vade Mehmet']);    // borç kaydı, ödenmedi
        $this->ledger($mehmet, PartyLedgerEntry::TYPE_PURCHASE, 15000, '2031-05-11');

        Expense::create(['expense_date' => '2031-05-01', 'due_date' => '2031-05-11', 'amount' => 7000, 'payment_status' => 'unpaid', 'description' => 'Vade kira']);
        Expense::create(['expense_date' => '2031-05-01', 'due_date' => '2031-05-11', 'amount' => 999, 'payment_status' => 'paid', 'description' => 'Ödenmiş gider']);
        Expense::create(['expense_date' => '2031-05-01', 'due_date' => '2031-05-20', 'amount' => 888, 'payment_status' => 'unpaid', 'description' => 'Uzak vade']);

        $lines = DueItems::between(now(), now()->addDay())->map(fn ($i) => $i['date'] . ' ' . DueItems::line($i))->all();

        $this->assertContains('2031-05-10 Tahsilat: Vade Ali — Bal 45.000,00 ₺ (Vade Ali: sana borcu 30.000,00 ₺)', $lines);
        $this->assertContains('2031-05-11 Ödeme: Vade Mehmet — Bal 15.000,00 ₺', $lines);
        $this->assertContains('2031-05-11 Ödeme: Vade kira 7.000,00 ₺', $lines);
        $this->assertCount(3, $lines);
    }

    public function test_sends_one_combined_message_once_per_day(): void
    {
        $ali = Party::create(['name' => 'Vade Ali']);
        $this->ledger($ali, PartyLedgerEntry::TYPE_SALE, 45000, '2031-05-10');
        Expense::create(['expense_date' => '2031-05-01', 'due_date' => '2031-05-11', 'amount' => 7000, 'payment_status' => 'unpaid', 'description' => 'Vade kira']);

        $this->artisan('dues:remind')->assertSuccessful();
        $this->artisan('dues:remind')->assertSuccessful(); // aynı gün ikinci kez → gönderilmez

        $sent = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'api.twilio.com'));
        $this->assertCount(1, $sent);
        $body = (string) $sent->first()[0]['Body'];
        $this->assertStringContainsString("*Bugün:*\n• Tahsilat: Vade Ali — Bal 45.000,00 ₺", $body);
        $this->assertStringContainsString("*Yarın:*\n• Ödeme: Vade kira 7.000,00 ₺", $body);
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'api.twilio.com') && $r['To'] === 'whatsapp:+905453606783');
    }

    public function test_nothing_due_sends_nothing(): void
    {
        $this->artisan('dues:remind')->assertSuccessful();

        Http::assertNothingSent();
    }
}
