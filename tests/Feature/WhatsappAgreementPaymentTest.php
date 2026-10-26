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
    }
}
