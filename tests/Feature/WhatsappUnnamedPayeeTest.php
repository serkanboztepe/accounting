<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\User;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ExpenseContext;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Adsız usta / taşeron ödemesi (kullanıcı 30.10): "Asansörcüye 1 milyon ödedim" → 100 bin ve üstü mesleğin adıyla cariye ödeme
 * ("Asansörcü" — hesap tutulur, sonra "500 binlik iş yaptı" ondan düşer); "Karocuya 50 bin ödedim" → gider. Eşik kodda. AI taklit.
 */
class WhatsappUnnamedPayeeTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905558880032';

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('muteahhit', []), 'app.profile' => 'muteahhit',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 888 00 32'],
            'services.anthropic.chat_assistant' => false]);
        Http::fake();
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        $this->actingAs(User::factory()->create());
        ExpenseContext::$only = ['parties' => []]; // yerel veritabanındaki başka cariler meslek köküne takılmasın
    }

    protected function tearDown(): void
    {
        ExpenseContext::$only = null;
        parent::tearDown();
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    private function ai(array ...$r): void
    {
        $m = Mockery::mock(ExpenseExtractor::class);
        $m->shouldReceive('extract')->andReturn(...$r);
        $this->app->instance(ExpenseExtractor::class, $m);
    }

    private function draft(): array
    {
        return (array) WhatsappPendingExpense::where('phone', self::PHONE)->latest('id')->value('extracted');
    }

    private function expense(array $o = []): array
    {
        return array_merge([
            'kind' => ExpenseExtractor::KIND_EXPENSE, 'items' => [], 'payment_type' => null, 'amount' => 1000000.0,
            'date' => now()->format('Y-m-d'), 'due_date' => null, 'description' => 'Asansör işi', 'project_id' => null, 'project_name' => null,
            'party_id' => null, 'party_name' => null, 'category_id' => null, 'category_name' => null, 'paid' => true,
            'confidence' => 'high', 'question' => null, 'is_new_entry' => true, 'unnamed_payee' => true, 'payee_label' => 'Asansörcü',
        ], $o);
    }

    public function test_big_amount_goes_to_trade_named_party_small_stays_expense(): void
    {
        $paid = ExpenseExtractor::unnamedPayeeToParty($this->expense());
        $this->assertSame(ExpenseExtractor::KIND_PAYMENT, $paid['kind']);
        $this->assertSame('Asansörcü', $paid['party_name']);
        $this->assertNull($paid['category_id']);

        $at = (float) ExpenseExtractor::UNNAMED_PAYEE_PARTY_FROM;
        $this->assertSame(ExpenseExtractor::KIND_PAYMENT, ExpenseExtractor::unnamedPayeeToParty($this->expense(['amount' => $at]))['kind'], 'sınır dahil');
        $this->assertSame(ExpenseExtractor::KIND_EXPENSE, ExpenseExtractor::unnamedPayeeToParty($this->expense(['amount' => 50000.0]))['kind'], 'küçük: gider');
    }

    public function test_untouched_when_not_a_craftsman_or_asked_and_unpaid_goes_to_purchase(): void
    {
        $same = fn (array $o) => ExpenseExtractor::unnamedPayeeToParty($this->expense($o))['kind'];
        $this->assertSame(ExpenseExtractor::KIND_EXPENSE, $same(['unnamed_payee' => false, 'payee_label' => null, 'description' => 'Şantiye kirası']), 'kira');
        $this->assertSame(ExpenseExtractor::KIND_EXPENSE, $same(['unnamed_payee' => false, 'question' => "Bu para Asansör Osman'a mı gitti, yoksa ayrı gider mi?"]), 'cari sorusu');
        $this->assertSame(ExpenseExtractor::KIND_PAYMENT, $same(['question' => 'Asansör takıldı mı, yoksa peşin mi?']), 'iş durumu sorusu dönüşümü engellemez');
        $unpaid = ExpenseExtractor::unnamedPayeeToParty($this->expense(['paid' => false, 'amount' => 80000.0, 'payee_label' => 'Boyacı']));
        $this->assertSame(ExpenseExtractor::KIND_PURCHASE, $unpaid['kind'], 'ödenmedi: borç kimin hesabında — alış + ad sorusu (30.10)');
        $this->assertSame('Kime borçlusun? Adını yazar mısın?', $unpaid['question']);
        $this->assertSame(ExpenseExtractor::KIND_EXPENSE, $same(['paid' => false, 'unnamed_payee' => false]), 'ödenmemiş fatura (kişi değil): gider kalır');
        $this->assertSame(ExpenseExtractor::KIND_EXPENSE, $same(['payee_label' => '']), 'meslek adı yok');
    }

    /** Uçtan uca: kart "Asansörcü (yeni kişi)" ödemesi; ikinci ödeme aynı cariye gider (çift cari açılmaz). */
    public function test_webhook_card_is_payment_to_trade_party_and_reuses_it(): void
    {
        $m = Mockery::mock(ExpenseExtractor::class);
        $m->shouldReceive('extract')->andReturn($this->expense());
        $this->app->instance(ExpenseExtractor::class, $m);

        $card = html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => 'Asansörcüye 1 milyon ödedim', 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
        $this->assertStringContainsString('Asansörcü', $card);
        $this->assertStringNotContainsString('Gider', $card);
        $draft = WhatsappPendingExpense::where('phone', self::PHONE)->latest('id')->first();
        $this->assertSame(ExpenseExtractor::KIND_PAYMENT, $draft->extracted['kind']);

        $existing = Party::create(['name' => 'Asansörcü']);
        ExpenseContext::$only = ['parties' => [$existing->id]];
        $draft->update(['status' => 'superseded']);
        $this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => 'Asansörcüye 50 bin daha ödedim', 'NumMedia' => 0])->assertOk();
        $this->assertSame($existing->id, (int) $this->draft()['party_id'], 'aynı adlı cari: sorulmadan ona (küçük tutar da)');
    }

    /** AI kişisiz "ödeme" seçse de adsız usta işareti aynı akışa girer (eval 30.10 Sonnet: "Karocuya 50 bin" → "Kime?"). */
    public function test_payment_kind_without_party_follows_same_flow(): void
    {
        $asPayment = fn (array $o) => ExpenseExtractor::unnamedPayeeToParty($this->expense(['kind' => ExpenseExtractor::KIND_PAYMENT] + $o));
        $small = $asPayment(['amount' => 50000.0, 'payee_label' => 'Karocu']);
        $this->assertSame(ExpenseExtractor::KIND_EXPENSE, $small['kind']);
        $this->assertNull($small['question']);
        $big = $asPayment(['amount' => 250000.0, 'payee_label' => 'Hafriyatçı']);
        $this->assertSame(ExpenseExtractor::KIND_PAYMENT, $big['kind']);
        $this->assertSame('Hafriyatçı', $big['party_name']);
        $this->assertSame(ExpenseExtractor::KIND_PAYMENT, $asPayment(['party_name' => 'Osman'])['kind'], 'adlı ödemeye dokunulmaz');
        $this->assertSame('Osman', $asPayment(['party_name' => 'Osman'])['party_name']);
    }

    /** Yalnız para verildi ama AI "anlaşmadan iş yapıldı" seçti → o carinin ödemesi (iş yapılmış sayılmaz). */
    public function test_money_only_delivery_becomes_payment_to_agreement_party(): void
    {
        config(['modules.agreements' => true]);
        $ali = Party::create(['name' => 'UP Ali Usta']);
        $product = \App\Models\Product::create(['name' => 'UP Sıva', 'type' => \App\Models\Product::TYPE_SERVICE]);
        $agreement = \App\Models\Agreement::create(['party_id' => $ali->id, 'direction' => 'purchase', 'title' => 'Sıva', 'agreement_date' => now()->toDateString()]);
        $item = \App\Models\AgreementItem::create(['agreement_id' => $agreement->id, 'product_id' => $product->id, 'quantity' => 1000, 'unit_price' => 250, 'amount' => 250000]);
        $delivery = ['kind' => ExpenseExtractor::KIND_AGREEMENT_DELIVERY, 'amount' => null, 'paid' => true,
            'delivery_lines' => [['agreement_item_id' => $item->id, 'quantity' => 200.0, 'amount' => 50000.0, 'unit_price' => null, 'description' => null]]];

        $paid = ExpenseExtractor::moneyOnlyDelivery($delivery + ['money_only' => true]);
        $this->assertSame(ExpenseExtractor::KIND_PAYMENT, $paid['kind']);
        $this->assertSame($ali->id, $paid['party_id']);
        $this->assertEqualsWithDelta(50000, $paid['amount'], 0.01);
        $this->assertSame([], $paid['delivery_lines']);

        $this->assertSame(ExpenseExtractor::KIND_AGREEMENT_DELIVERY, ExpenseExtractor::moneyOnlyDelivery($delivery + ['money_only' => false])['kind'], '"300 m2 sıva yaptı" teslimattır');
    }

    /** "Asansör Osman" varken: kod sorar; *evet* → Osman'a ödeme (gider değil). */
    public function test_matching_party_is_asked_and_yes_pays_that_party(): void
    {
        $osman = Party::create(['name' => 'Asansör Osman']);
        ExpenseContext::$only = ['parties' => [$osman->id]];
        $this->ai($this->expense(['amount' => 300000.0]));

        $ask = $this->send('Asansörcüye 300 bin ödedim');
        $this->assertStringContainsString("Bu para Asansör Osman'a mı gitti, yoksa ayrı gider mi?", $ask);
        $this->assertSame($osman->id, (int) $this->draft()['ask_party_id']);
        $this->assertNull(Party::where('name', 'Asansörcü')->first(), 'çift cari açılmadı');

        $this->send('evet');
        $d = $this->draft();
        $this->assertSame(ExpenseExtractor::KIND_PAYMENT, $d['kind']);
        $this->assertSame($osman->id, (int) $d['party_id']);
        $this->assertEqualsWithDelta(300000, (float) $d['amount'], 0.01);
        $this->assertSame(0, \App\Models\Expense::where('amount', 300000)->count(), 'gider yazılmadı');
    }

    /** "gider" cevabı: gider kalır, soru tekrar sorulmaz. AI'ın işaretlediği aday (payee_party_id) da sorulur. */
    public function test_answer_gider_keeps_expense_and_does_not_ask_again(): void
    {
        $iscisi = Party::create(['name' => 'Seramik İşçisi']);
        ExpenseContext::$only = ['parties' => [$iscisi->id]];
        $this->ai(
            $this->expense(['amount' => 50000.0, 'payee_label' => 'Karocu', 'payee_party_id' => $iscisi->id, 'description' => 'Karo işçiliği']),
            $this->expense(['amount' => 50000.0, 'payee_label' => 'Karocu', 'payee_party_id' => $iscisi->id, 'description' => 'Karo işçiliği', 'is_new_entry' => false]),
        );

        $this->assertStringContainsString("Bu para Seramik İşçisi", $this->send('Karocuya 50 bin ödedim'));
        $card = $this->send('ayrı gider');
        $this->assertStringNotContainsString('mi gitti', $card);
        $d = $this->draft();
        $this->assertSame(ExpenseExtractor::KIND_EXPENSE, $d['kind']);
        $this->assertEmpty($d['ask_party_id'] ?? null);
    }
}
