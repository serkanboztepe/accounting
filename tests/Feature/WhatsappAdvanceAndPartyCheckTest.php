<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\PartyNameCheck;
use App\Support\PartyStatement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Erkan, Ayten (12-13 Ekim): avansı olan müşteriye teslim → satış avanstan düşülür, para ikinci kez yazılmaz;
 * yazılan ad eşleşen cariden farklıysa "aynı kişi mi?" sorulur. AI mock'lanır.
 */
class WhatsappAdvanceAndPartyCheckTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905558880001';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.twilio.verify_signature' => false,
            'services.twilio.allowed_phones' => ['0555 888 00 01'],
            'modules.smart_assistant' => false, 'services.anthropic.chat_assistant' => false,
            'modules.expenses' => true, 'modules.projects' => false, 'modules.cari_supplier' => true,
        ]);
        Http::fake();
        \App\Models\WhatsappPendingExpense::where('status', 'awaiting_confirmation')->update(['status' => 'superseded']);
    }

    private function entry(array $o): array
    {
        return array_merge([
            'kind' => ExpenseExtractor::KIND_SALE, 'items' => [], 'payment_type' => null, 'amount' => 0.0,
            'date' => now()->format('Y-m-d'), 'due_date' => null, 'description' => 'Peynir', 'project_id' => null,
            'project_name' => null, 'party_id' => null, 'party_name' => null, 'party_said' => null, 'category_id' => null,
            'category_name' => null, 'paid' => null, 'confidence' => 'high', 'question' => null, 'is_new_entry' => true,
        ], $o);
    }

    private function fakeAi(array ...$responses): void
    {
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturn(...$responses);
        $this->app->instance(ExpenseExtractor::class, $mock);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    private function partyWith(string $name, string $type, float $amount, string $description): Party
    {
        $party = Party::create(['name' => $name]);
        PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => now()->subDay()->format('Y-m-d'),
            'type' => $type, 'description' => $description, 'amount' => $amount]);

        return $party;
    }

    private function balance(Party $party): float
    {
        return PartyStatement::build($party)['balance'];
    }

    public function test_delivery_after_customer_advance_is_not_collected_twice(): void
    {
        $party = $this->partyWith('Ayten Avans', PartyLedgerEntry::TYPE_COLLECTION, 6000, 'Avans — 10 kg peynir');
        $this->fakeAi($this->entry(['amount' => 6000, 'paid' => true, 'party_id' => $party->id, 'party_said' => 'Ayten Avans']));

        $reply = $this->send("Ayten'in 10 kilo peynirini gönderdim, hepsini aldım");
        $this->assertStringContainsString('(avanstan düşüldü)', $reply);
        $this->assertStringContainsString('6.000,00 ₺ avanstan düşülür', $reply);
        $this->assertStringNotContainsString('Aynı kişi mi', $reply);

        $this->send('evet');
        $this->assertSame(1, PartyLedgerEntry::where('party_id', $party->id)->where('type', PartyLedgerEntry::TYPE_SALE)->count());
        $this->assertSame(1, PartyLedgerEntry::where('party_id', $party->id)->where('type', PartyLedgerEntry::TYPE_COLLECTION)->count());
        $this->assertSame(0.0, $this->balance($party));
    }

    public function test_sale_larger_than_advance_collects_only_the_rest(): void
    {
        $party = $this->partyWith('Ayten Kalan', PartyLedgerEntry::TYPE_COLLECTION, 6000, 'Avans — peynir');
        $this->fakeAi($this->entry(['amount' => 8000, 'paid' => true, 'party_id' => $party->id]));

        $this->assertStringContainsString('kalan 2.000,00 ₺ tahsil edildi', $this->send('Ayten 8000 peynir gönderdim parasını aldım'));
        $this->send('evet');

        $this->assertEquals(2000, PartyLedgerEntry::where('party_id', $party->id)->where('description', 'like', 'Satış tahsilatı%')->value('amount'));
        $this->assertSame(0.0, $this->balance($party));
    }

    public function test_unpaid_rest_stays_as_receivable(): void
    {
        $party = $this->partyWith('Ayten Borç', PartyLedgerEntry::TYPE_COLLECTION, 6000, 'Avans — peynir');
        $this->fakeAi($this->entry(['amount' => 8000, 'paid' => false, 'party_id' => $party->id]));

        $this->assertStringContainsString('kalan 2.000,00 ₺ sana borç', $this->send('Ayten 8000 peynir gönderdim'));
        $this->send('evet');
        $this->assertSame(2000.0, $this->balance($party));
    }

    public function test_new_money_keeps_old_behaviour(): void
    {
        $party = $this->partyWith('Ayten Yeni', PartyLedgerEntry::TYPE_COLLECTION, 6000, 'Avans — peynir');
        $sale = $this->entry(['amount' => 6000, 'paid' => true, 'party_id' => $party->id]);
        $this->fakeAi($sale, ['is_new_entry' => false] + $sale);

        $this->send('Ayten 6000 peynir verdim parasını aldım');
        $this->assertStringContainsString('(parası alındı)', $this->send('yeni para'));
        $this->send('evet');

        $this->assertSame(2, PartyLedgerEntry::where('party_id', $party->id)->where('type', PartyLedgerEntry::TYPE_COLLECTION)->count());
        $this->assertSame(-6000.0, $this->balance($party));
    }

    /** Recep: borç alıştan (avans değil) — peşin satışın tahsilatı aynen yazılır. */
    public function test_purchase_debt_is_not_treated_as_advance(): void
    {
        $party = $this->partyWith('Recep Alış', PartyLedgerEntry::TYPE_PURCHASE, 12000, 'Yemleme düzeneği');
        $this->fakeAi($this->entry(['amount' => 3000, 'paid' => true, 'party_id' => $party->id]));

        $this->assertStringNotContainsString('avans', $this->send('Recep 3000 peynir parasını aldım'));
        $this->send('evet');
        $this->assertSame(-12000.0, $this->balance($party));
    }

    public function test_work_after_supplier_advance_is_not_paid_twice(): void
    {
        $party = $this->partyWith('Usta Avans', PartyLedgerEntry::TYPE_PAYMENT, 10000, 'Avans — sıva');
        $this->fakeAi($this->entry(['kind' => ExpenseExtractor::KIND_PURCHASE, 'amount' => 10000, 'paid' => true, 'party_id' => $party->id]));

        $this->assertStringContainsString('10.000,00 ₺ avanstan düşülür', $this->send('Usta sıvayı bitirdi 10 bin ödedim'));
        $this->send('evet');
        $this->assertSame(1, PartyLedgerEntry::where('party_id', $party->id)->where('type', PartyLedgerEntry::TYPE_PAYMENT)->count());
        $this->assertSame(0.0, $this->balance($party));
    }

    public function test_different_name_asks_same_person_and_yeni_opens_new_party(): void
    {
        $party = Party::create(['name' => 'Nusaybin bayisi Ayten Test']);
        $this->fakeAi($this->entry(['amount' => 6000, 'paid' => true, 'party_id' => $party->id,
            'party_said' => "Hüseyin BİM'in bacısı Ayten"]));

        $reply = $this->send("Hüseyin BİM'in bacısı ayten'e 10 kilo peynir 6000, parasını aldım");
        $this->assertStringContainsString('*Aynı kişi mi?* Değilse *yeni* yaz', $reply);

        $reply = $this->send('yeni');
        $this->assertStringContainsString("Hüseyin BİM'in bacısı Ayten (yeni cari)", $reply);
        $this->assertStringNotContainsString('Aynı kişi mi', $reply);

        $this->send('evet');
        $this->assertSame(0, PartyLedgerEntry::where('party_id', $party->id)->count());
        $this->assertSame(1, Party::where('name', "Hüseyin BİM'in bacısı Ayten")->count());
    }

    /** Gerçek akış: önce tutar sorulur, cevapta AI adı tekrarlamaz — soru yine çıkar, avans da düşülür. */
    public function test_amount_reply_keeps_name_question_and_offsets_advance(): void
    {
        $party = $this->partyWith('Nusaybin bayisi Ayten İki', PartyLedgerEntry::TYPE_COLLECTION, 6000, 'Avans — peynir');
        $first = $this->entry(['amount' => 0, 'paid' => true, 'party_id' => $party->id, 'party_said' => "Hüseyin BİM'in bacısı Ayten",
            'question' => 'Tutar ne kadardı?']);
        $this->fakeAi($first, ['amount' => 6000, 'party_said' => null, 'question' => null, 'is_new_entry' => false] + $first);

        $this->assertStringContainsString('Tutar ne kadardı?', $this->send("Hüseyin BİM'in bacısı ayten'in peynirini gönderdim"));
        $reply = $this->send('6000 lira hepsini aldım');

        $this->assertStringContainsString('(avanstan düşüldü)', $reply);
        $this->assertStringContainsString('*Aynı kişi mi?*', $reply);
        $this->send('evet');
        $this->assertSame(0.0, $this->balance($party));
    }

    public function test_same_person_confirm_keeps_match(): void
    {
        $party = Party::create(['name' => 'Nusaybin bayisi Ayten Onay']);
        $this->fakeAi($this->entry(['amount' => 6000, 'paid' => false, 'party_id' => $party->id, 'party_said' => 'Hüseyinin bacısı Ayten']));

        $this->send('Hüseyinin bacısı Aytene 6000 peynir');
        $this->send('evet');
        $this->assertSame(6000.0, $this->balance($party));
    }

    /** Vedat (Erkan, 14 Ekim): "Vedat Çağlar 275+170 sebze ekmek getirdim" — yön sorulur, cevapsız kaydedilmez. */
    public function test_unclear_direction_asks_and_blocks_confirm(): void
    {
        $this->fakeAi($this->entry(['kind' => ExpenseExtractor::KIND_PURCHASE, 'amount' => 445, 'paid' => false,
            'party_name' => 'Vedat Yön', 'description' => 'Sebze ekmek', 'direction_unclear' => true]));

        $reply = $this->send('Vedat Yön 275+170 sebze ekmek getirdim');
        $this->assertStringContainsString('*kim kime verdi?*', $reply);
        $this->assertStringContainsString('*sattım* ya da *aldım* yaz', $reply);

        $this->assertStringContainsString('Önce yönü yaz', $this->send('evet'));
        $this->assertSame(0, Party::where('name', 'Vedat Yön')->count());

        $this->assertStringContainsString('🧾 *Satış* → Vedat Yön (yeni cari): 445,00 ₺', $this->send('sattım'));
        $this->send('evet');
        $this->assertSame(445.0, $this->balance(Party::where('name', 'Vedat Yön')->sole()));
    }

    public function test_name_check_rules(): void
    {
        $p = fn (string $n) => new Party(['name' => $n]);

        $this->assertFalse(PartyNameCheck::differs($p('Ali Arıcı'), 'Ali'));
        $this->assertFalse(PartyNameCheck::differs($p('Ali Arıcı'), 'Ali usta'));
        $this->assertFalse(PartyNameCheck::differs($p('Ali Arıcı'), 'arıcı ali'));
        $this->assertFalse(PartyNameCheck::differs($p('Demirci Recep'), "Recep'e"));
        $this->assertFalse(PartyNameCheck::differs($p('Nusaybin bayisi Ayten'), 'Nusaybin bayi Ayten'));
        $this->assertFalse(PartyNameCheck::differs($p("Şeker'in kardeşi"), 'Şekerin kardeşi'));
        $this->assertFalse(PartyNameCheck::differs($p('İbrahim Bey'), 'ibrahim'));
        $this->assertFalse(PartyNameCheck::differs($p('Doktor'), null));

        $this->assertTrue(PartyNameCheck::differs($p('Nusaybin bayisi Ayten'), "Hüseyin BİM'in bacısı Ayten"));
        $this->assertTrue(PartyNameCheck::differs($p('Ahmet Kaya'), 'Ahmet Yılmaz'));
        $this->assertTrue(PartyNameCheck::differs($p('Ali'), "Ali'nin oğlu"));
    }
}
