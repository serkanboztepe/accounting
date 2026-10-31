<?php

namespace Tests\Feature;

use App\Models\Agreement;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\PaymentAgreementChoice;
use App\Support\AgreementBalances;
use App\Support\AgreementDesk;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * WhatsApp "Hangisi için?" (kullanıcı 26.10): anlaşması olan cariye ödeme / ondan tahsilat her seferinde sorulur; seçenek yanında
 * tek satır sonuç cümlesi; anlaşma seçilince "ne için?" sorusu yok ve ödeme anlaşmaya bağlanır; "Diğer" bugünkü akış. AI taklit.
 */
class WhatsappAgreementPaymentTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905558880031';

    private Party $party;

    private Agreement $demir;

    private Agreement $cimento;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('muteahhit', ['agreements' => true]), 'app.profile' => 'muteahhit',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 888 00 31'],
            'services.anthropic.chat_assistant' => false]);
        Http::fake();
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        $this->actingAs(User::factory()->create());
        $this->party = Party::create(['name' => 'WAP Demirci Ali']);
        $project = Project::create(['name' => 'WAP A Blok', 'status' => 'active']);
        $this->demir = $this->agreement('Demir', 10, 8000, $project->id);       // 80.000 geldi
        $this->cimento = $this->agreement('Çimento', 5, 5000, null);           // 25.000 geldi
    }

    private function agreement(string $title, float $qty, float $price, ?int $projectId): Agreement
    {
        $product = Product::create(['name' => 'WAP ' . $title, 'type' => Product::TYPE_SERVICE, 'is_active' => true]);
        $a = AgreementDesk::create($this->party, ['direction' => Agreement::DIRECTION_PURCHASE, 'agreement_date' => now()->subDays(3)->toDateString(),
            'title' => $title, 'project_id' => $projectId, 'lines' => [['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => $price]]]);
        AgreementDesk::deliver($this->party, Agreement::DIRECTION_PURCHASE, [['agreement_item_id' => $a->items()->first()->id, 'quantity' => $qty]],
            now()->subDay()->toDateString());

        return $a->fresh();
    }

    private function entry(array $o = []): array
    {
        return array_merge([
            'kind' => ExpenseExtractor::KIND_PAYMENT, 'items' => [], 'payment_type' => 'eft', 'amount' => 25000.0,
            'date' => now()->format('Y-m-d'), 'due_date' => null, 'description' => null, 'project_id' => null, 'project_name' => null,
            'party_id' => $this->party->id, 'party_name' => null, 'category_id' => null, 'category_name' => null, 'paid' => null,
            'confidence' => 'high', 'question' => null, 'is_new_entry' => true, 'payment_purpose' => null, 'work_status' => null,
            'work_status_known' => null, 'party_certain' => true, 'meaning' => "Demirci Ali'ye 25.000 ₺ ödedin",
        ], $o);
    }

    private function ai(array ...$r): void
    {
        $m = Mockery::mock(ExpenseExtractor::class);
        $m->shouldReceive('extract')->andReturn(...$r);
        $this->app->instance(ExpenseExtractor::class, $m);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    private function payment(): PartyLedgerEntry
    {
        return PartyLedgerEntry::where('party_id', $this->party->id)->where('type', PartyLedgerEntry::TYPE_PAYMENT)->sole();
    }

    public function test_payment_asks_which_agreement_and_number_links_it(): void
    {
        $this->ai($this->entry());
        $ask = $this->send("Demirci Ali'ye 25 bin eft yaptım");
        $this->assertStringContainsString("WAP Demirci Ali'ye 25.000,00 ₺ ödedin. Hangisi için?", $ask);
        $this->assertStringContainsString("*1* Çimento: 25.000 borcun vardı, borcun kalmayacak ✅", $ask);
        $this->assertStringContainsString("*2* Demir (WAP A Blok): 80.000 borcun vardı, 55.000'e düşecek", $ask);
        $this->assertStringContainsString('*3* Diğer alışlar: borcun yok, 25.000 alacaklı olacaksın', $ask);

        $card = $this->send('2');
        $this->assertStringContainsString("🤝 Demir (WAP A Blok): 80.000 borcun vardı, 55.000'e düşecek", $card);
        $this->assertStringNotContainsString('ne için', mb_strtolower($card));
        $this->send('evet');

        $this->assertSame($this->demir->id, (int) $this->payment()->agreement_id);
        $this->assertEqualsWithDelta(55000, AgreementBalances::balance($this->demir->fresh()->loadForCard()), 0.01);
        $this->assertSame(1, PartyLedgerEntry::where('party_id', $this->party->id)->whereNull('purchase_id')->count(), 'eski borç satırı yazılmadı');
    }

    public function test_answer_in_words_and_card_cannot_be_skipped(): void
    {
        $this->ai($this->entry());
        $this->send("Demirci Ali'ye 25 bin eft yaptım");
        $this->assertStringContainsString('Hangisi için?', $this->send('evet'), 'seçmeden kayıt yok');
        $this->assertSame(0, PartyLedgerEntry::where('party_id', $this->party->id)->where('type', PartyLedgerEntry::TYPE_PAYMENT)->count());

        $this->send('demir için');
        $this->send('evet');
        $this->assertSame($this->demir->id, (int) $this->payment()->agreement_id);
    }

    /** Sohbet asistanı açık firmada yazıyla seçim AI'a gitmeden kodda çözülür (30.10: "anlaşmasından düş" sorusu tekrarlanıyordu). */
    public function test_answer_in_words_with_chat_assistant_stays_in_code(): void
    {
        $this->ai($this->entry());
        $this->send("Demirci Ali'ye 25 bin eft yaptım");

        config(['services.anthropic.chat_assistant' => true]);
        $this->app->instance(\App\Services\Whatsapp\ChatAssistant::class, Mockery::mock(\App\Services\Whatsapp\ChatAssistant::class)); // çağrılırsa test düşer

        $card = $this->send('demir için');
        $this->assertStringContainsString("🤝 Demir (WAP A Blok): 80.000 borcun vardı, 55.000'e düşecek", $card);
        $this->send('evet');
        $this->assertSame($this->demir->id, (int) $this->payment()->agreement_id);
    }

    /** Adsız gider + "Bu para X'e mi gitti?" → cevap anlaşmayı gösteriyorsa "Hangisi için?" ikinci kez sorulmaz (30.10). */
    public function test_answer_naming_agreement_skips_second_question(): void
    {
        $this->ai(
            $this->entry(['kind' => ExpenseExtractor::KIND_EXPENSE, 'party_id' => null, 'paid' => true, 'description' => 'Demir işçiliği',
                'question' => "Bu para WAP Demirci Ali'ye mi gitti (Demir anlaşması), yoksa ayrı gider mi?"]),
            $this->entry(['is_new_entry' => false]),
        );
        $this->assertStringContainsString("Demirci Ali'ye mi gitti", $this->send('Demir işçiliğine 25 bin ödedim'));

        $card = $this->send("Ali'nin demir anlaşmasından düş");
        $this->assertStringNotContainsString('Hangisi için?', $card);
        $this->assertStringContainsString("🤝 Demir (WAP A Blok): 80.000 borcun vardı, 55.000'e düşecek", $card);
        $this->send('evet');
        $this->assertSame($this->demir->id, (int) $this->payment()->agreement_id);
    }

    public function test_other_keeps_todays_flow_and_writes_without_agreement(): void
    {
        $this->ai($this->entry(['work_status' => 'done', 'work_status_known' => true, 'debt_repayment' => true]));
        $this->send("Demirci Ali'ye 25 bin eft yaptım");
        $card = $this->send('3');
        $this->assertStringNotContainsString('🤝', $card);
        $this->send('evet');
        $this->assertNull($this->payment()->agreement_id);
    }

    public function test_collection_from_customer_with_sale_agreement(): void
    {
        $customer = Party::create(['name' => 'WAP Ahmet']);
        $product = Product::create(['name' => 'WAP Daire', 'type' => Product::TYPE_SERVICE, 'is_active' => true]);
        $daire = AgreementDesk::create($customer, ['direction' => Agreement::DIRECTION_SALE, 'title' => 'Daire satışı',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100000]]]);
        AgreementDesk::deliver($customer, Agreement::DIRECTION_SALE, [['agreement_item_id' => $daire->items()->first()->id, 'quantity' => 1]],
            now()->toDateString());

        $this->ai($this->entry(['kind' => ExpenseExtractor::KIND_COLLECTION, 'party_id' => $customer->id, 'amount' => 40000.0,
            'meaning' => "Ahmet'ten 40.000 ₺ aldın"]));
        $ask = $this->send("Ahmet'ten 40 bin aldım");
        $this->assertStringContainsString("WAP Ahmet'ten 40.000,00 ₺ aldın. Hangisi için?", $ask);
        $this->assertStringContainsString("*1* Daire satışı: 100.000 alacağın vardı, 60.000'e düşecek", $ask);
        $this->assertStringContainsString('*2* Diğer satışlar: alacağın yok, 40.000 borçlu olacaksın', $ask);
        $this->send('1');
        $this->send('evet');
        $this->assertSame($daire->id, (int) PartyLedgerEntry::where('party_id', $customer->id)
            ->where('type', PartyLedgerEntry::TYPE_COLLECTION)->sole()->agreement_id);
    }

    public function test_paid_money_marked_as_agreement_stays_payment_not_memory(): void
    {
        // Sonnet 30.10: "Ali ustanın anlaşmasından 50 bin ödedim" → work_status=agreement → para "🧠 Hatırlayayım mı?" notuna dönüyordu.
        $this->ai($this->entry(['work_status' => \App\Services\Whatsapp\Understanding::AGREEMENT, 'work_status_known' => true]));
        $ask = $this->send('Demirci Aliye anlaşmasından 25 bin ödedim');
        $this->assertStringContainsString('Hangisi için?', $ask);
        $this->assertStringNotContainsString('Hatırlayayım', $ask);
        $this->assertSame(ExpenseExtractor::KIND_PAYMENT, $this->draft()['kind']);
    }

    public function test_money_only_purchase_to_agreement_party_becomes_payment(): void
    {
        // Sonnet 30.10: "Ali ustaya 3 çek verdim" → alış (iş yapıldı) seçildi; yalnız para verildi → ödeme + "Hangisi için?".
        $this->ai($this->entry(['kind' => ExpenseExtractor::KIND_PURCHASE, 'paid' => true, 'money_only' => true]));
        $this->assertStringContainsString('Hangisi için?', $this->send('Demirci Aliye 25 bin çek verdim'));
        $this->assertSame(ExpenseExtractor::KIND_PAYMENT, $this->draft()['kind']);

        $plain = Party::create(['name' => 'WAP Anlaşmasız']);
        $kept = ExpenseExtractor::moneyOnlyPurchase($this->entry(['kind' => ExpenseExtractor::KIND_PURCHASE, 'paid' => true,
            'money_only' => true, 'party_id' => $plain->id]));
        $this->assertSame(ExpenseExtractor::KIND_PURCHASE, $kept['kind'], 'anlaşması olmayan cariye dokunulmaz');
        $this->assertSame(ExpenseExtractor::KIND_PURCHASE, ExpenseExtractor::moneyOnlyPurchase($this->entry(['kind' => ExpenseExtractor::KIND_PURCHASE,
            'paid' => true, 'money_only' => false]))['kind'], 'iş yapıldı denmişse alış kalır');
    }

    public function test_future_payment_with_recorded_debt_becomes_reminder(): void
    {
        // Eval 30.10: borç 105.000 kayıtlıyken "yarın 40 bin vereceğim" → yeni 40.000 borç kartı (borç iki kez yazılırdı).
        $tomorrow = now()->addDay()->toDateString();
        $note = $this->entry(['kind' => ExpenseExtractor::KIND_DEBT_NOTE, 'debt_side' => 'payable', 'future_payment' => true,
            'amount' => 40000.0, 'due_date' => $tomorrow]);
        $this->ai($note);
        $before = PartyLedgerEntry::count();
        $card = $this->send("Demirci Ali'ye yarın 40 bin vereceğim");
        $this->assertStringContainsString('borcun zaten 105.000,00 ₺ kayıtlı — yeni borç yazmıyorum', $card);
        $this->assertSame(ExpenseExtractor::KIND_REMINDER, $this->draft()['kind']);
        $this->assertSame($tomorrow, $this->draft()['event_date']);
        $this->send('evet');
        $this->assertSame($before, PartyLedgerEntry::count(), 'borç yazılmadı');
        $this->assertSame(1, \App\Models\Reminder::where('party_id', $this->party->id)->where('text', 'like', '%40.000,00 ₺ ödeme%')->count());

        $this->assertNull(ExpenseExtractor::futurePaymentReminder(array_merge($note, ['amount' => 200000.0])), 'borçtan büyük: eski akış');
        $this->assertNull(ExpenseExtractor::futurePaymentReminder(array_merge($note, ['future_payment' => false])), 'borç bildirimi: eski akış');
    }

    public function test_future_payment_without_date_asks_when(): void
    {
        $this->ai($this->entry(['kind' => ExpenseExtractor::KIND_DEBT_NOTE, 'debt_side' => 'payable', 'future_payment' => true, 'amount' => 40000.0]));
        $ask = $this->send("Demirci Ali'ye 40 bin vereceğim");
        $this->assertStringContainsString('Ne zaman hatırlatayım?', $ask);
        $this->assertStringNotContainsString('neden', $ask);
    }

    public function test_payment_within_open_debt_is_not_asked_what_for(): void
    {
        // Eval 30.10: 120.000 borç varken "Demirci Hasan'a 50 bin ödedim" → "Bu ödeme hangi iş/mal için?" (bakiye zaten doğru düşüyor).
        $hasan = Party::create(['name' => 'WAP Demirci Hasan']);
        PartyLedgerEntry::create(['party_id' => $hasan->id, 'type' => PartyLedgerEntry::TYPE_PURCHASE, 'amount' => 120000,
            'entry_date' => now()->subDays(5)->toDateString()]);
        $ask = 'Bu ödeme hangi iş/mal için: önceden mi anlaşmıştınız, iş bitti de mi ödedin?';
        $this->ai($this->entry(['party_id' => $hasan->id, 'amount' => 50000.0, 'work_status_known' => false, 'question' => $ask]));
        $card = $this->send("Demirci Hasan'a 50 bin ödedim");
        $this->assertStringNotContainsString('hangi iş', $card);
        $this->assertStringContainsString('borcun 70.000,00', $card);
        $this->send('evet');
        $this->assertSame(1, PartyLedgerEntry::where('party_id', $hasan->id)->where('type', PartyLedgerEntry::TYPE_PAYMENT)->count());
        $this->assertSame(2, PartyLedgerEntry::where('party_id', $hasan->id)->count(), 'eski borç satırı yazılmadı');

        $entry = $this->entry(['party_id' => $hasan->id, 'amount' => 150000.0, 'work_status_known' => false, 'question' => $ask]);
        $this->assertFalse(\App\Services\Whatsapp\Understanding::paymentPurposeIsMoot($entry), 'borcu aşan ödeme sorulur');
        $this->assertFalse(\App\Services\Whatsapp\Understanding::paymentPurposeIsMoot(['party_id' => $this->party->id, 'amount' => 1000.0] + $entry),
            'anlaşmalı cari: Hangisi için? sorulur');
    }

    private function draft(): array
    {
        return (array) WhatsappPendingExpense::where('phone', self::PHONE)->latest('id')->value('extracted');
    }

    public function test_party_without_agreement_is_not_asked(): void
    {
        $plain = Party::create(['name' => 'WAP Anlaşmasız']);
        $this->assertFalse(PaymentAgreementChoice::applies($this->entry(['party_id' => $plain->id])));
        $this->assertTrue(PaymentAgreementChoice::applies($this->entry()));
        $this->assertFalse(PaymentAgreementChoice::applies($this->entry(['payment_purpose' => 'on_behalf'])), 'adına ödeme sorulmaz');
        $this->assertFalse(PaymentAgreementChoice::applies($this->entry(['agreement_choice_done' => true])), 'bir kez');
    }

    public function test_match_words(): void
    {
        $d = PaymentAgreementChoice::prepare($this->entry());
        $this->assertSame(1, PaymentAgreementChoice::match($d, 'çimentoya'));
        $this->assertSame(2, PaymentAgreementChoice::match($d, 'Demir için verdim'));
        $this->assertSame(3, PaymentAgreementChoice::match($d, 'diğer'));
        $this->assertSame(3, PaymentAgreementChoice::match($d, 'anlaşma dışı'));
        $this->assertNull(PaymentAgreementChoice::match($d, 'bilmiyorum'));
        $this->assertNull(PaymentAgreementChoice::match($d, 'anlaşmasından düş'), 'iki anlaşma varken hangisi belli değil');

        // Tek anlaşma + diğer (30.10): "Ali ustanın anlaşmasından düş" o anlaşmadır; "anlaşma dışı" yine diğer.
        $one = ['agreement_choices' => [$d['agreement_choices'][0], null]];
        $this->assertSame(1, PaymentAgreementChoice::match($one, 'Ali ustanın anlaşmasından düş'));
        $this->assertSame(2, PaymentAgreementChoice::match($one, 'anlaşma dışı'));
    }
}
