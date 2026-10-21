<?php

namespace Tests\Feature\Trade;

use App\Models\AssistantMemory;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\StockChoice;
use App\Support\ModuleProfiles;
use App\Support\PartyStatement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Kartı olmayan mal alışında stok sorusu (kullanıcı 21.10, Akkuş 20.10 20:12 Emre: "25.000 börek unu ödemesi yapıldı" →
 * "Uncu muhyiddin" → stoksuz alış + ödeme; "bence burada stok sormalıydık"). AI taklit edilir; mesajlar Emre'nin cümleleri.
 */
class WhatsappStockChoiceTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905550000003';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.twilio.verify_signature' => false,
            'services.twilio.allowed_phones' => ['0555 000 00 03'],
            'modules' => ModuleProfiles::resolve('trade'),
            'app.profile' => 'trade',
        ]);
        Http::fake();
    }

    private function fakeAi(array ...$responses): void
    {
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturn(...$responses);
        $this->app->instance(ExpenseExtractor::class, $mock);
    }

    /** Emre'nin 20.10 20:13 mesajının AI çıktısı: yeni cariye peşin alış, mal = börek unu, adet yok. */
    private function flourPayment(array $o = []): array
    {
        return array_merge([
            'kind' => ExpenseExtractor::KIND_PURCHASE, 'items' => [], 'payment_type' => null, 'amount' => 25000.0,
            'date' => now()->format('Y-m-d'), 'due_date' => null, 'description' => 'Börek unu', 'project_id' => null,
            'project_name' => null, 'party_id' => null, 'party_name' => 'Uncu Muhyiddin', 'category_id' => null,
            'category_name' => null, 'paid' => true, 'confidence' => 'high', 'question' => null, 'is_new_entry' => true,
            'work_status' => 'done', 'work_status_known' => true, 'party_certain' => true,
            'meaning' => "Uncu Muhyiddin'e börek unu için 25.000 ₺ ödeme yapıldı", 'goods_name' => 'Börek unu',
        ], $o);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    private function muhyiddin(): ?Party
    {
        return Party::where('name', 'Uncu Muhyiddin')->first();
    }

    public function test_emre_message_asks_where_the_flour_is_and_saves_nothing(): void
    {
        $this->fakeAi($this->flourPayment());

        $reply = $this->send('25.000 börek unu ödemesi yapıldı');

        $this->assertStringContainsString('*Börek unu* nerede?', $reply);
        $this->assertStringContainsString('*1* Geldi, elimizde', $reply);
        $this->assertStringContainsString("*2* Uncu Muhyiddin'de duruyor", $reply);
        $this->assertStringContainsString('*0* Stok tutmuyorum', $reply);
        $this->assertNull($this->muhyiddin(), 'soru cevaplanmadan kayıt yok');
    }

    public function test_arrived_with_quantity_opens_card_and_enters_stock(): void
    {
        $this->fakeAi($this->flourPayment());
        $this->send('25.000 börek unu ödemesi yapıldı');

        $card = $this->send('1 20 çuval');
        $this->assertStringContainsString('Börek unu', $card);
        $this->send('evet');

        $flour = Product::where('name', 'Börek unu')->first();
        $this->assertNotNull($flour, 'kart açıldı');
        $this->assertTrue($flour->isProduct());
        $this->assertSame(20.0, $flour->currentStock());
        $purchase = Purchase::latest('id')->first();
        $this->assertSame([$this->muhyiddin()->id, true, '25000.00'], [$purchase->party_id, (bool) $purchase->paid, $purchase->total_amount]);
        $this->assertEqualsWithDelta(0, PartyStatement::build($this->muhyiddin())['balance'], 0.01, 'peşin: alış + ödeme');
    }

    public function test_quantity_asked_when_only_1_and_plain_quantity_accepted(): void
    {
        $this->fakeAi($this->flourPayment());
        $this->send('25.000 börek unu ödemesi yapıldı');

        $this->assertStringContainsString('Kaç tane geldi?', $this->send('1'));
        $this->send('20 çuval');
        $this->send('evet');

        $this->assertSame(20.0, Product::where('name', 'Börek unu')->first()?->currentStock());
    }

    public function test_at_supplier_is_advance_and_no_stock(): void
    {
        $this->fakeAi($this->flourPayment());
        $purchases = Purchase::count();
        $this->send('25.000 börek unu ödemesi yapıldı');

        $this->send('2');
        $this->send('evet');

        $this->assertNull(Product::where('name', 'Börek unu')->first(), 'tedarikçideki mal stoğa girmez');
        $this->assertSame($purchases, Purchase::count(), 'alış belgesi yok');
        $this->assertSame([PartyLedgerEntry::TYPE_PAYMENT], PartyLedgerEntry::where('party_id', $this->muhyiddin()->id)->pluck('type')->all(), 'yalnız ödeme');
        $this->assertEqualsWithDelta(25000, PartyStatement::build($this->muhyiddin())['balance'], 0.01, 'Muhyiddin bize borçlu (avans)');
    }

    public function test_no_stock_keeps_old_record_and_is_remembered(): void
    {
        $this->fakeAi($this->flourPayment(), $this->flourPayment(['amount' => 10000.0]));
        $this->send('25.000 börek unu ödemesi yapıldı');

        $this->send('0');
        $this->send('evet');

        $this->assertNull(Product::where('name', 'Börek unu')->first());
        $types = PartyLedgerEntry::where('party_id', $this->muhyiddin()->id)->pluck('type')->sort()->values()->all();
        $this->assertSame([PartyLedgerEntry::TYPE_PURCHASE, PartyLedgerEntry::TYPE_PAYMENT], $types, 'eskisi gibi alış + ödeme');
        $this->assertTrue(StockChoice::remembered('Börek unu'));
        $this->assertSame(1, AssistantMemory::where('subject', 'Stok tutulmuyor: Börek unu')->count());

        $second = $this->send('10.000 börek unu ödemesi yapıldı');
        $this->assertStringNotContainsString('nerede?', $second, 'bir daha sorulmaz');
    }

    public function test_question_cannot_be_skipped_with_yes(): void
    {
        $this->fakeAi($this->flourPayment());
        $this->send('25.000 börek unu ödemesi yapıldı');

        $again = $this->send('evet');

        $this->assertStringContainsString('*Börek unu* nerede?', $again);
        $this->assertNull($this->muhyiddin());
    }

    public function test_no_question_for_service_or_known_card(): void
    {
        Product::create(['name' => 'Un', 'type' => Product::TYPE_PRODUCT]);
        $this->fakeAi(
            $this->flourPayment(['goods_name' => null, 'description' => 'Nakliye', 'party_name' => 'Nakliyeci Ali']),
            $this->flourPayment(['goods_name' => 'Un', 'description' => 'Un']),
        );

        $this->assertStringNotContainsString('nerede?', $this->send('Nakliyeci Aliye 3000 ödedim'));
        $this->send('iptal');
        $this->assertStringNotContainsString('nerede?', $this->send('25.000 un ödemesi yapıldı'), 'kartı olan mal: mevcut stoklu akış');
    }

    public function test_cash_shop_purchase_without_party_has_no_supplier_option(): void
    {
        $this->fakeAi($this->flourPayment(['kind' => ExpenseExtractor::KIND_EXPENSE, 'party_name' => null, 'goods_name' => 'Susam',
            'description' => 'Susam', 'amount' => 3000.0]));

        $reply = $this->send('3000 tl susam aldım');

        $this->assertStringContainsString('*Susam* nerede?', $reply);
        $this->assertStringNotContainsString('*2*', $reply);
    }

    public function test_quantity_parser_ignores_money(): void
    {
        $this->assertSame([20.0, 'çuval'], StockChoice::quantity('20 çuval'));
        $this->assertSame([1.5, 'ton'], StockChoice::quantity('1,5 ton'));
        $this->assertSame([20.0, null], StockChoice::quantity('20 tane'));
        $this->assertNull(StockChoice::quantity('20 bin'));
        $this->assertNull(StockChoice::quantity('500 tl'));
        $this->assertNull(StockChoice::quantity('geldi'));
    }

    // Gerçek AI (eval 21.10): Emre'nin cümlesi "ödeme", iş durumu belirsiz → eskiden "ne için? 1 mal geldi · 2 peşin · 3 eski borç"
    // geliyordu; mal belliyken tek soru: stok sorusu + eski borç seçeneği.
    public function test_unclear_payment_gets_one_merged_question_with_old_debt(): void
    {
        Product::create(['name' => 'Un', 'type' => Product::TYPE_PRODUCT]); // "Börek unu" bu kart DEĞİL
        $payment = $this->flourPayment(['kind' => ExpenseExtractor::KIND_PAYMENT, 'work_status' => null, 'work_status_known' => false]);
        $this->fakeAi($payment, $payment);

        $reply = $this->send('25.000 börek unu ödemesi yapıldı');
        $this->assertStringContainsString('*Börek unu* nerede?', $reply);
        $this->assertStringContainsString('*3* Eski borcu ödedim', $reply);
        $this->assertStringNotContainsString('ne için?', $reply, 'iki soru üst üste yok');

        $this->send('1 20 çuval');
        $this->send('evet');
        $card = Product::where('name', 'Börek unu')->first();
        $this->assertNotNull($card, 'Un kartına yazılmadı, yeni kart');
        $this->assertSame(20.0, $card->currentStock());
        $this->assertTrue((bool) Purchase::latest('id')->first()->paid);
    }

    public function test_old_debt_choice_is_plain_payment_without_stock(): void
    {
        $party = Party::create(['name' => 'Uncu Muhyiddin']);
        PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => now()->subMonth()->toDateString(), 'type' => PartyLedgerEntry::TYPE_PURCHASE, 'amount' => 25000]);
        $this->fakeAi($this->flourPayment(['kind' => ExpenseExtractor::KIND_PAYMENT, 'party_id' => $party->id, 'party_name' => null,
            'work_status' => null, 'work_status_known' => false]));
        $purchases = Purchase::count();

        $this->send('25.000 börek unu ödemesi yapıldı');
        $this->send('3');
        $this->send('evet');

        $this->assertSame($purchases, Purchase::count());
        $this->assertNull(Product::where('name', 'Börek unu')->first());
        $this->assertEqualsWithDelta(0, PartyStatement::build($party)['balance'], 0.01, 'eski borç kapandı');
    }

    // Canlıda akıllı asistan açık: stok cevabı ("1 20 çuval") AI'a gitmez, kod okur (eval 21.10 Sonnet: AI'a gidince soru tekrarlandı).
    public function test_stock_answer_bypasses_chat_assistant(): void
    {
        config(['modules.smart_assistant' => true]);
        $chat = Mockery::mock(\App\Services\Whatsapp\ChatAssistant::class);
        $chat->shouldNotReceive('respond');
        $this->app->instance(\App\Services\Whatsapp\ChatAssistant::class, $chat);
        \App\Models\WhatsappPendingExpense::create(['phone' => self::PHONE, 'status' => 'awaiting_confirmation',
            'summary' => 'stok sorusu', 'extracted' => $this->flourPayment(['stock_choice' => true, 'question' => 'nerede?'])]);

        $card = $this->send('1 20 çuval');

        $this->assertStringContainsString('20 çuval Börek unu', $card);
    }
}
