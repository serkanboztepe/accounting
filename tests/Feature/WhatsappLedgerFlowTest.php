<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\PartyStatement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Mockery;
use Tests\TestCase;

/**
 * WhatsApp asistanı — ödeme / satış / tahsilat / bakiye sorusu.
 * AI çağrısı mock'lanır; test edilen şey kayıt mantığı:
 * ödeme MALİYET DEĞİL (Expense yazılmaz), cari bakiyesini düşürür.
 */
class WhatsappLedgerFlowTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905550000000';

    protected function setUp(): void
    {
        parent::setUp();
        // İmza doğrulaması ayrı testte; akış testlerinde kapalı.
        config(['services.twilio.verify_signature' => false]);
        // Dışarı (Twilio) istek gitmesin; "yazıyor…" çağrısı burada yakalanır.
        Http::fake();
    }

    private function fakeAi(array ...$responses): void
    {
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturn(...$responses);
        $this->app->instance(ExpenseExtractor::class, $mock);
    }

    private function entry(array $overrides): array
    {
        return array_merge([
            'kind' => ExpenseExtractor::KIND_EXPENSE,
            'items' => [],
            'payment_type' => null,
            'amount' => 0.0,
            'date' => now()->format('Y-m-d'),
            'due_date' => null,
            'description' => '',
            'project_id' => null,
            'project_name' => null,
            'party_id' => null,
            'party_name' => null,
            'category_id' => null,
            'category_name' => null,
            'paid' => true,
            'confidence' => 'high',
            'question' => null,
            'is_new_entry' => false,
        ], $overrides);
    }

    private function send(string $body): string
    {
        return $this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()
            ->getContent();
    }

    private function balance(Party $party): float
    {
        return PartyStatement::build($party->fresh())['balance'];
    }

    public function test_payment_against_open_debt_reduces_balance_without_new_cost(): void
    {
        $party = Party::create(['name' => 'Ahmet Usta']);
        Expense::create([
            'party_id' => $party->id, 'expense_date' => now(), 'amount' => 150000,
            'payment_status' => 'unpaid', 'description' => 'Malzeme',
        ]);
        $this->assertSame(-150000.0, $this->balance($party));

        $this->fakeAi($this->entry([
            'kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 100000, 'party_id' => $party->id,
            'payment_type' => 'bank_transfer',
        ]));

        $reply = $this->send("Ahmet'e 100 bin havale ettim");
        $this->assertStringContainsString('borcun 150.000,00 → borcun 50.000,00', $reply);

        $this->send('evet');

        $this->assertSame(1, Expense::where('party_id', $party->id)->count(), 'Ödeme yeni gider açmamalı');
        $entry = PartyLedgerEntry::where('party_id', $party->id)->sole();
        $this->assertSame(PartyLedgerEntry::TYPE_PAYMENT, $entry->type);
        $this->assertSame('bank_transfer', $entry->payment_type);
        $this->assertSame(-50000.0, $this->balance($party));
    }

    public function test_payment_without_debt_asks_and_advance_is_recorded(): void
    {
        $party = Party::create(['name' => 'Mehmet Usta']);
        $this->fakeAi($this->entry([
            'kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 20000, 'party_id' => $party->id,
        ]));

        $reply = $this->send("Mehmet'e 20 bin verdim");
        $this->assertStringContainsString('açık borcun görünmüyor', $reply);

        // "evet" seçim yerine geçmez
        $this->assertStringContainsString('Önce seç', $this->send('evet'));
        $this->assertSame(0, PartyLedgerEntry::where('party_id', $party->id)->count());

        $this->send('2');

        $entry = PartyLedgerEntry::where('party_id', $party->id)->sole();
        $this->assertStringStartsWith('Avans', (string) $entry->description);
        $this->assertSame(20000.0, $this->balance($party)); // avans: cari bize borçlu
        $this->assertSame(0, Expense::where('party_id', $party->id)->count());
    }

    public function test_payment_without_debt_choice_one_becomes_paid_expense(): void
    {
        $party = Party::create(['name' => 'Ali Usta']);
        $this->fakeAi($this->entry([
            'kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 5000, 'party_id' => $party->id,
            'description' => 'Yevmiye',
        ]));

        $this->send("Ali'ye 5 bin verdim");
        $this->assertStringContainsString('Ne için ödedin?', $this->send('1'));
        $this->send('evet'); // amacı atla

        $expense = Expense::where('party_id', $party->id)->sole();
        $this->assertSame('paid', $expense->payment_status);
        $this->assertSame(0, PartyLedgerEntry::where('party_id', $party->id)->count());
        $this->assertSame(0.0, $this->balance($party));
    }

    public function test_sale_items_then_collection(): void
    {
        $party = Party::create(['name' => 'Ahmet X']);
        $this->fakeAi(
            $this->entry([
                'kind' => ExpenseExtractor::KIND_SALE, 'amount' => 110000, 'party_id' => $party->id,
                'items' => [
                    ['description' => 'Mimari proje', 'amount' => 80000],
                    ['description' => 'Şantiye şefliği', 'amount' => 30000],
                ],
            ]),
            $this->entry([
                'kind' => ExpenseExtractor::KIND_COLLECTION, 'amount' => 50000, 'party_id' => $party->id,
                'is_new_entry' => true,
            ]),
        );

        $this->send("Ahmet X'e 80 bine proje 30 bine şantiye şefliği yaptım");
        $this->send('evet');
        $this->assertSame(2, PartyLedgerEntry::where('party_id', $party->id)->where('type', PartyLedgerEntry::TYPE_SALE)->count());
        $this->assertSame(110000.0, $this->balance($party));

        $reply = $this->send('Ahmet X 50 bin ödedi');
        $this->assertStringContainsString('→ *sana*', $reply);
        $this->assertStringContainsString('alacağın 110.000,00 → alacağın 60.000,00', $reply);
        $this->send('evet');

        $this->assertSame(60000.0, $this->balance($party));
    }

    public function test_balance_query_answers_without_draft(): void
    {
        $party = Party::create(['name' => 'Kuşak Beton']);
        Expense::create([
            'party_id' => $party->id, 'expense_date' => now(), 'amount' => 42000,
            'payment_status' => 'unpaid', 'description' => 'Beton',
        ]);
        $this->fakeAi($this->entry(['kind' => ExpenseExtractor::KIND_BALANCE_QUERY, 'party_id' => $party->id]));

        $reply = $this->send("Kuşak Beton'a ne kadar borcum var?");

        $this->assertStringContainsString('Kuşak Beton: borcun 42.000,00', $reply);
        $this->assertSame(0, WhatsappPendingExpense::where('phone', self::PHONE)->count());
    }

    public function test_confirm_without_pending_draft_does_not_create_expense(): void
    {
        $this->fakeAi(); // AI hiç çağrılmamalı
        $reply = $this->send('evet');

        $this->assertStringContainsString('Onay bekleyen bir kayıt yok', $reply);
        $this->assertSame(0, WhatsappPendingExpense::where('phone', self::PHONE)->count());
    }

    public function test_statement_request_returns_signed_pdf_link(): void
    {
        $party = Party::create(['name' => 'Ali Boztepe']);
        PartyLedgerEntry::create([
            'party_id' => $party->id, 'entry_date' => '2026-09-10', 'type' => PartyLedgerEntry::TYPE_SALE,
            'amount' => 80000, 'description' => 'Proje',
        ]);
        $this->fakeAi($this->entry([
            'kind' => ExpenseExtractor::KIND_STATEMENT, 'party_id' => $party->id,
            'date_from' => '2026-09-01', 'date_to' => '2026-09-30',
        ]));

        $reply = $this->send("Ali'nin Eylül ekstresini at");

        $this->assertStringContainsString('<Body>📄 Ali Boztepe — Cari Ekstresi', $reply);
        $this->assertStringContainsString('Dönem: 2026-09-01 – 2026-09-30', $reply);
        $this->assertSame(1, preg_match('#<Media>(.+)</Media>#', $reply, $m));
        $url = html_entity_decode($m[1], ENT_XML1 | ENT_QUOTES);
        $this->assertStringContainsString('/whatsapp/ekstre/' . $party->id . '/Ekstre-ali-boztepe.pdf', $url);
        $this->assertSame(0, WhatsappPendingExpense::where('phone', self::PHONE)->count());

        // Girişsiz ama imzalı link → PDF; imzasız → 403
        $pdf = $this->get($url);
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->get('/whatsapp/ekstre/' . $party->id . '/Ekstre.pdf')->assertForbidden();
    }

    public function test_twilio_signature_is_enforced(): void
    {
        config(['services.twilio.verify_signature' => true, 'services.twilio.token' => 'test-token']);
        $this->fakeAi();
        $params = ['From' => self::PHONE, 'Body' => 'evet', 'NumMedia' => '0'];

        $this->post('/whatsapp/webhook', $params, ['X-Twilio-Signature' => 'yanlis'])->assertForbidden();
        $this->post('/whatsapp/webhook', $params)->assertForbidden();

        $url = URL::to('/whatsapp/webhook');
        ksort($params);
        $payload = $url;
        foreach ($params as $k => $v) {
            $payload .= $k . $v;
        }
        $signature = base64_encode(hash_hmac('sha1', $payload, 'test-token', true));

        $this->post('/whatsapp/webhook', $params, ['X-Twilio-Signature' => $signature])->assertOk();
    }

    public function test_totals_query_lists_receivables_and_payables_separately(): void
    {
        $ali = Party::create(['name' => 'Zz Ali Toplam']);
        $veli = Party::create(['name' => 'Zz Veli Toplam']);
        $beton = Party::create(['name' => 'Zz Beton Toplam']);
        PartyLedgerEntry::create(['party_id' => $ali->id, 'entry_date' => now(), 'type' => PartyLedgerEntry::TYPE_SALE, 'amount' => 150000]);
        PartyLedgerEntry::create(['party_id' => $veli->id, 'entry_date' => now(), 'type' => PartyLedgerEntry::TYPE_SALE, 'amount' => 60000]);
        Expense::create(['party_id' => $beton->id, 'expense_date' => now(), 'amount' => 42000, 'payment_status' => 'unpaid', 'description' => 'Beton']);

        $receivables = \App\Support\PartyBalances::receivables();
        $payables = \App\Support\PartyBalances::payables();

        $this->fakeAi($this->entry(['kind' => ExpenseExtractor::KIND_TOTALS_QUERY, 'totals_side' => 'both']));
        $reply = $this->send('Genel durum ne?');

        $this->assertStringContainsString('Toplam alacağın: ' . \App\Support\Money::format($receivables->sum('balance')), $reply);
        $this->assertStringContainsString('Toplam borcun: ' . \App\Support\Money::format($payables->sum('balance')), $reply);
        // Netleştirme yok: Beton borcu alacak tarafından düşülmez
        $this->assertTrue($receivables->contains(fn ($r) => $r['party']->is($ali) && $r['balance'] === 150000.0));
        $this->assertTrue($payables->contains(fn ($r) => $r['party']->is($beton) && $r['balance'] === 42000.0));
        $this->assertFalse($receivables->contains(fn ($r) => $r['party']->is($beton)));
        $this->assertSame(0, WhatsappPendingExpense::where('phone', self::PHONE)->count());

        $this->fakeAi($this->entry(['kind' => ExpenseExtractor::KIND_TOTALS_QUERY, 'totals_side' => 'receivable']));
        $this->assertStringNotContainsString('Toplam borcun', $this->send('Toplam alacağım ne kadar?'));
    }

    public function test_unstated_payment_defaults_unpaid_with_party_paid_without(): void
    {
        $party = Party::create(['name' => 'Zz Varsayılan Cari']);
        $this->fakeAi(
            $this->entry(['amount' => 100000, 'party_id' => $party->id, 'paid' => null, 'description' => 'Malzeme']),
            $this->entry(['amount' => 5000, 'paid' => null, 'description' => 'Yakıt Varsayılan', 'is_new_entry' => true]),
        );

        $this->assertStringContainsString('Durum: Ödenmedi (borç)', $this->send("Ahmet'ten 100 bin malzeme aldım"));
        $this->send('evet');
        $this->assertSame('unpaid', Expense::where('party_id', $party->id)->sole()->payment_status);

        $this->assertStringContainsString('Durum: Ödendi', $this->send('5 bin yakıt'));
        $this->send('evet');
        $this->assertSame('paid', Expense::where('description', 'Yakıt Varsayılan')->sole()->payment_status);
    }

    public function test_typing_indicator_sent_before_ai_but_not_for_quick_replies(): void
    {
        config(['services.twilio.sid' => 'ACtest', 'services.twilio.token' => 'tok']);
        $party = Party::create(['name' => 'Zz Yazıyor']);
        $this->fakeAi($this->entry(['kind' => ExpenseExtractor::KIND_BALANCE_QUERY, 'party_id' => $party->id]));

        $this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => 'Zz Yazıyor borcu ne', 'NumMedia' => 0, 'MessageSid' => 'SMabc'])->assertOk();
        Http::assertSent(fn ($req) => str_contains($req->url(), '/v3/Indicators/Typing.json')
            && $req['messageId'] === 'SMabc' && $req['channel'] === 'WHATSAPP');

        Http::fake();
        $this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => 'evet', 'NumMedia' => 0, 'MessageSid' => 'SMdef'])->assertOk();
        Http::assertNothingSent();
    }

    public function test_turkish_capital_cancel_and_punctuation_confirm(): void
    {
        $this->fakeAi(
            $this->entry(['amount' => 1000, 'description' => 'Yakıt İptal Testi']),
            $this->entry(['amount' => 2000, 'description' => 'Yakıt Onay Testi', 'is_new_entry' => true]),
        );

        $this->send('1000 yakıt');
        $this->assertStringContainsString('İptal edildi', $this->send('İptal'));
        $this->assertSame(0, Expense::where('description', 'Yakıt İptal Testi')->count());

        $this->send('2000 yakıt');
        $this->send(' Evet. ');
        $this->assertSame(1, Expense::where('description', 'Yakıt Onay Testi')->count());
    }

    public function test_zero_amount_does_not_open_draft(): void
    {
        $this->fakeAi($this->entry(['amount' => 0, 'description' => 'Belirsiz']));

        $this->assertStringContainsString('Tutarı anlayamadım', $this->send('asdf qwe'));
        $this->assertSame(0, WhatsappPendingExpense::where('phone', self::PHONE)->count());
    }
}
