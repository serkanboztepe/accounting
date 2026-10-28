<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\ChatAssistant;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Kullanıcı kararı 19.10 (Akkuş 18.10 "Bizim toptan 18.261 tl gider" → yeni cari açıldı, ne alındığı sorulmadı): stoklu firmada
 * YENİ firmadan, parası açıkça ödenmiş, ne alındığı belli olmayan alış → gider (carisiz, açıklamada firma adı); ödenmemişse cari.
 * Ne alındığı sorulur (engellemez). Mevcut cari, gider kapalı firma, stoksuz firmalar: eskisi gibi.
 */
class WhatsappPaidSupplierTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905557770022';

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm('trade', ['expenses' => true]);
        Http::fake();
        WhatsappMessage::create(['phone' => '905557770022', 'direction' => 'in', 'body' => 'önceki']);
    }

    private function firm(string $profile, array $modules = []): void
    {
        config([
            'modules' => ModuleProfiles::resolve($profile, ['smart_assistant' => true] + $modules), 'app.profile' => $profile,
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 777 00 22'],
        ]);
    }

    private function entry(array $o): array
    {
        return ['type' => 'entry', 'text' => '', 'data' => array_merge(['kind' => ExpenseExtractor::KIND_EXPENSE, 'items' => [], 'amount' => 18261,
            'paid' => null, 'date' => now()->format('Y-m-d'), 'description' => 'Bizim Toptan gideri', 'party_id' => null, 'party_name' => null,
            'project_id' => null, 'project_name' => null, 'category_id' => null, 'category_name' => 'Malzeme', 'confidence' => 'high',
            'question' => null, 'is_new_entry' => true, 'payment_purpose' => null, 'settles_expense_id' => null, 'next_items' => null], $o)];
    }

    private function chat(array ...$results): void
    {
        $mock = Mockery::mock(ChatAssistant::class);
        $mock->shouldReceive('respond')->andReturn(...$results);
        $this->app->instance(ChatAssistant::class, $mock);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    public function test_paid_new_supplier_without_items_is_an_expense_with_firm_name(): void
    {
        $this->chat($this->entry(['party_name' => 'Bizim Toptan', 'paid' => true]));
        $parties = Party::count();

        $card = $this->send('Bizim toptan 18.261 tl gider ödendi');
        $this->assertStringContainsString('📝 *Gider*:', $card);
        $this->assertStringContainsString('— Bizim Toptan gideri', $card);
        $this->assertStringContainsString('Firma: Bizim Toptan (peşin ödendiği için hesap açılmaz)', $card);
        $this->assertStringContainsString('📦 Mal aldıysan', $card);
        $this->send('evet');

        $this->assertSame($parties, Party::count(), 'cari açılmadı');
        $e = Expense::latest('id')->first();
        $this->assertEquals(18261, (float) $e->amount);
        $this->assertSame('paid', $e->payment_status);
        $this->assertNull($e->party_id);
    }

    public function test_unpaid_supplier_stays_on_the_party_account(): void
    {
        $this->chat($this->entry(['party_name' => 'Bizim Toptan', 'paid' => false]));

        $card = $this->send('Bizim toptan 18.261 veresiye');
        $this->assertStringContainsString('Alış', $card);
        $this->assertStringContainsString('📦 Mal aldıysan', $card);
        $this->send('evet');

        $p = Party::where('name', 'Bizim Toptan')->firstOrFail();
        $this->assertEquals(18261, (float) PartyLedgerEntry::where('party_id', $p->id)->where('type', PartyLedgerEntry::TYPE_PURCHASE)->value('amount'));
    }

    public function test_paid_expense_turns_back_to_party_when_corrected_as_unpaid(): void
    {
        $this->chat(
            $this->entry(['party_name' => 'Bizim Toptan', 'paid' => true]),
            $this->entry(['description' => 'Bizim Toptan — Bizim Toptan gideri', 'paid' => false, 'is_new_entry' => false]),
        );

        $this->send('Bizim toptan 18.261 ödendi');
        $this->assertStringContainsString('Alış', $this->send('pardon ödenmedi veresiye'));
        $this->send('evet');

        $this->assertTrue(Party::where('name', 'Bizim Toptan')->exists());
    }

    public function test_existing_supplier_stays_on_the_party_account(): void
    {
        $tokgoz = Party::create(['name' => 'Tokgöz']);
        $this->chat($this->entry(['party_id' => $tokgoz->id, 'paid' => true, 'description' => 'Tokgöz alışı']));

        $this->assertStringContainsString('Alış', $this->send('Tokgözden 18.261 aldım ödendi'));
        $this->send('evet');

        $this->assertSame(1, PartyLedgerEntry::where('party_id', $tokgoz->id)->where('type', PartyLedgerEntry::TYPE_PURCHASE)->count());
    }

    public function test_expense_tracking_off_keeps_the_old_party_purchase(): void
    {
        $this->firm('trade', ['expenses' => false]);
        $this->chat($this->entry(['party_name' => 'Bizim Toptan', 'paid' => true]));

        $this->assertStringContainsString('Alış', $this->send('Bizim toptan 18.261 ödendi'));
        $this->send('evet');

        $this->assertTrue(Party::where('name', 'Bizim Toptan')->exists());
    }

    public function test_firm_without_stock_package_is_unchanged(): void
    {
        $this->firm('alacak_verecek');
        $this->chat($this->entry(['party_name' => 'Bizim Toptan', 'paid' => true]));

        $card = $this->send('Bizim toptan 18.261 ödendi');
        $this->assertStringContainsString('Alış', $card);
        $this->assertStringNotContainsString('Mal aldıysan', $card);
    }

    public function test_plain_expense_has_no_what_bought_line(): void
    {
        $this->chat($this->entry(['amount' => 54000, 'description' => 'Ergenekon dükkan kirası', 'category_name' => 'Kira', 'paid' => true]));

        $this->assertStringNotContainsString('Mal aldıysan', $this->send('Ergenekon kira 54.000 ödendi'));
    }

    /** AI firma adını satıcı alanına yazar (kişi alanı "önce anla" sorusunu tetikliyordu): gider + firma + "ne aldın?". */
    public function test_seller_name_in_text_expense_becomes_expense_with_firm(): void
    {
        $this->chat($this->entry(['seller_name' => 'Bizim Toptan', 'description' => 'Toptan alışveriş']));
        $parties = Party::count();

        $card = $this->send('Bizim toptan 18.261 tl gider');
        $this->assertStringContainsString('— Bizim Toptan — Toptan alışveriş', $card);
        $this->assertStringContainsString('📦 Mal aldıysan', $card);
        $this->send('evet');

        $this->assertSame($parties, Party::count());
        $this->assertSame('Bizim Toptan — Toptan alışveriş', Expense::latest('id')->value('description'));
    }

    public function test_seller_name_unpaid_or_existing_party_goes_to_party_account(): void
    {
        $this->chat($this->entry(['seller_name' => 'Bizim Toptan', 'paid' => false]));
        $this->assertStringContainsString('Alış', $this->send('Bizim toptan 18.261 veresiye'));
        $this->send('evet');
        $this->assertTrue(Party::where('name', 'Bizim Toptan')->exists());

        $tokgoz = Party::create(['name' => 'Tokgöz']);
        $this->chat($this->entry(['seller_name' => 'Tokgöz', 'paid' => true]));
        $this->assertStringContainsString('Alış', $this->send('Tokgöz 5.000 gider'));
        $this->send('evet');
        $this->assertSame(1, PartyLedgerEntry::where('party_id', $tokgoz->id)->where('type', PartyLedgerEntry::TYPE_PURCHASE)->count());
    }

    /** "Ne aldın?" → "20 çuval un": AI miktarı satıra koymazsa mesajdan alınır; belirsizse dokunulmaz. */
    public function test_stated_quantity_is_taken_from_the_message(): void
    {
        $q = fn (string $desc, string $text) => ExpenseExtractor::guardStatedQuantity(['kind' => ExpenseExtractor::KIND_TRADE_PURCHASE,
            'items' => [['description' => $desc, 'quantity' => null]]], $text)['items'][0]['quantity'];

        $this->assertEquals(20, $q('Un', '20 çuval un'));
        $this->assertEquals(50, $q('Susam', '50 kg susam aldım'));
        $this->assertNull($q('Un', 'Bizim toptan 17.000 tl ödendi'));
        $this->assertNull($q('Un', 'un aldım'));
        $this->assertNull($q('Un', '20 çuval un 10 çuval un'), 'iki sayı — tahmin yok');
    }
}
