<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\PartyStatement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
        $this->assertStringContainsString('Gider — kontrol et', $this->send('1'));
        $this->send('evet');

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
}
