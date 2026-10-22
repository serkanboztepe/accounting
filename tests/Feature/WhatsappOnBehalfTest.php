<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\ModuleProfiles;
use App\Support\PartyStatement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Müşteri adına üçüncü kişiye ödeme (kullanıcı 22.10, Yıldız 15:28: "Abdullah uçara 26500 tl Necati Çimen ruhsat için ödendi" →
 * "Abdullah Uçar'dan alış + ödendi" yazıldı, müşteri alışı panelden sildi). Kural: bir cari için başkasına ödenen para o carinin
 * hesabına ÖDEME — alacağımız olmasa da, yeni cari olsa da. AI taklit edilir; mesaj müşterinin cümlesi.
 */
class WhatsappOnBehalfTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905550000004';

    private const SAID = 'Abdullah uçara 26500 tl Necati Çimen ruhsat için ödendi';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.twilio.verify_signature' => false,
            'services.twilio.allowed_phones' => ['0555 000 00 04'],
        ]);
        Http::fake();
    }

    private function fakeAi(array ...$responses): void
    {
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturn(...$responses);
        $this->app->instance(ExpenseExtractor::class, $mock);
    }

    private function entry(array $o = []): array
    {
        return array_merge([
            'kind' => ExpenseExtractor::KIND_PAYMENT, 'items' => [], 'payment_type' => null, 'amount' => 26500.0,
            'date' => now()->format('Y-m-d'), 'due_date' => null, 'description' => 'Necati Çimen — ruhsat', 'project_id' => null,
            'project_name' => null, 'party_id' => null, 'party_name' => null, 'category_id' => null, 'category_name' => null,
            'paid' => null, 'confidence' => 'high', 'question' => null, 'is_new_entry' => true, 'payment_purpose' => 'on_behalf',
            'work_status' => null, 'work_status_known' => null, 'party_certain' => true,
            'meaning' => "Abdullah Uçar'ın ruhsatı için Necati Çimen'e 26.500 ₺ ödedin",
        ], $o);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    /** Yıldız'daki durum: 1.333.000 peşin vermiş müşteri. */
    private function abdullah(): Party
    {
        $party = Party::create(['name' => 'ABDULLAH UÇAR']);
        PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => now()->subDays(14)->toDateString(),
            'type' => PartyLedgerEntry::TYPE_COLLECTION, 'amount' => 1333000]);

        return $party;
    }

    public function test_on_behalf_payment_reduces_what_we_owe_the_customer(): void
    {
        $party = $this->abdullah();
        $this->fakeAi($this->entry(['party_id' => $party->id]));

        $card = $this->send(self::SAID);
        $this->assertStringContainsString('onun adına', $card);
        $this->assertStringContainsString('1.306.500', $card, 'kartta yeni bakiye');
        $this->assertStringNotContainsString('ne için', $card);
        $this->send('evet');

        $types = PartyLedgerEntry::where('party_id', $party->id)->pluck('type')->sort()->values()->all();
        $this->assertSame([PartyLedgerEntry::TYPE_PAYMENT, PartyLedgerEntry::TYPE_COLLECTION], $types, 'alış yok, yalnız ödeme');
        $this->assertStringStartsWith('Adına ödendi', PartyLedgerEntry::where('party_id', $party->id)->where('type', PartyLedgerEntry::TYPE_PAYMENT)->value('description'));
        $this->assertEqualsWithDelta(-1306500, PartyStatement::build($party)['balance'], 0.01);
    }

    // Kullanıcı: "müşteriden alacağı olmasa da müşterisine veya yeni bir cari için 3. şahıslara ödenen paralar carinin hesabına yazılır".
    public function test_new_party_on_behalf_payment_makes_them_owe_us_without_questions(): void
    {
        $this->fakeAi($this->entry(['party_name' => 'Ali Bey', 'amount' => 8000.0, 'description' => 'Tapu harcı',
            'meaning' => "Ali Bey'in tapu harcını yatırdın"]));

        $card = $this->send("Ali Bey'in tapu harcını yatırdım 8 bin");
        $this->assertStringNotContainsString('❓', $card, 'iş durumu / ne için sorusu yok');
        $this->send('evet');

        $ali = Party::where('name', 'Ali Bey')->first();
        $this->assertNotNull($ali);
        $this->assertEqualsWithDelta(8000, PartyStatement::build($ali)['balance'], 0.01, 'Ali Bey bize borçlu');
    }

    public function test_purchase_from_customer_is_asked_not_saved_silently(): void
    {
        $party = $this->abdullah();
        $purchase = $this->entry(['kind' => ExpenseExtractor::KIND_PURCHASE, 'payment_purpose' => null, 'paid' => true,
            'party_id' => $party->id, 'description' => 'Necati Çimen ruhsat işi', 'work_status' => 'done', 'work_status_known' => true]);
        $this->fakeAi($purchase, $purchase);

        $ask = $this->send(self::SAID);
        $this->assertStringContainsString('ABDULLAH UÇAR senin müşterin', $ask);
        $this->assertStringContainsString('*1* Onun adına ödedim', $ask);
        $this->assertStringContainsString('ABDULLAH UÇAR senin müşterin', $this->send('evet'), 'soru atlanamaz');

        $card = $this->send('1');
        $this->assertStringContainsString('onun adına', $card);
        $this->send('evet');

        $this->assertSame(0, PartyLedgerEntry::where('party_id', $party->id)->where('type', PartyLedgerEntry::TYPE_PURCHASE)->count());
        $this->assertEqualsWithDelta(-1306500, PartyStatement::build($party)['balance'], 0.01);
    }

    public function test_customer_answer_2_keeps_purchase(): void
    {
        $party = $this->abdullah();
        $purchase = $this->entry(['kind' => ExpenseExtractor::KIND_PURCHASE, 'payment_purpose' => null, 'paid' => true,
            'party_id' => $party->id, 'description' => 'Danışmanlık', 'work_status' => 'done', 'work_status_known' => true]);
        $this->fakeAi($purchase, $purchase);

        $this->send('Abdullah Uçardan danışmanlık aldım 26500 ödedim');
        $this->assertStringContainsString('Alış', $this->send('2'));
        $this->send('0'); // proje: Genel (projeli kurulum)
        $this->send('evet');

        $this->assertSame(1, PartyLedgerEntry::where('party_id', $party->id)->where('type', PartyLedgerEntry::TYPE_PURCHASE)->count());
    }

    public function test_purchase_from_supplier_is_not_asked(): void
    {
        $party = Party::create(['name' => 'Kuşak Beton']);
        PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => now()->subMonth()->toDateString(),
            'type' => PartyLedgerEntry::TYPE_PURCHASE, 'amount' => 1000]);
        $this->fakeAi($this->entry(['kind' => ExpenseExtractor::KIND_PURCHASE, 'payment_purpose' => null, 'paid' => true,
            'party_id' => $party->id, 'description' => 'Beton', 'work_status' => 'done', 'work_status_known' => true]));

        $this->assertStringNotContainsString('müşterin', $this->send('Kuşak betondan 26500 beton aldım ödedim'));
    }

    // Çiftlik 22.10 08:09: önceki mesaj "Ödendi" (ezme) → "Akkuş fırına 50 lt süt gitti" peşin satış kartı (Akkuş hep veresiye alır).
    public function test_sale_to_person_without_paid_said_is_on_account(): void
    {
        config(['modules' => ModuleProfiles::resolve('trade'), 'app.profile' => 'trade']);
        $x = app(ExpenseExtractor::class);
        $base = ['kind' => ExpenseExtractor::KIND_TRADE_SALE, 'amount' => 1500, 'description' => 'Süt', 'party_id' => 1, 'paid' => true,
            'items' => [], 'date' => now()->format('Y-m-d'), 'confidence' => 'high'];

        $this->assertFalse($x->normalizeEntry($base + ['sale_paid_said' => false])['paid'], 'söylenmedi → veresiye');
        $this->assertTrue($x->normalizeEntry($base + ['sale_paid_said' => true])['paid'], '"parasını aldım" → peşin');
        $this->assertTrue($x->normalizeEntry(['party_id' => null] + $base + ['sale_paid_said' => false])['paid'], 'kişisiz satış peşin');
    }
}
