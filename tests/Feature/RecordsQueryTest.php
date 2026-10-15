<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use App\Models\Unit;
use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ConversationLookup;
use App\Services\Whatsapp\RecordsQuery;
use App\Support\ModuleProfiles;
use App\Support\TradeDesk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Okuma aracı (Faz 1): rakamları kod hesaplar, çift sayım yok, maaş alış değil, ürün geçmişi, kaydın konuşması. */
class RecordsQueryTest extends TestCase
{
    use DatabaseTransactions;

    private string $from;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('trade'), 'app.profile' => 'trade']);
        $this->from = now()->addYears(5)->toDateString(); // yerel eski veriden ayrı: test kayıtları ileri tarihte
    }

    private function q(array $in): array
    {
        return RecordsQuery::run($in + ['date_from' => $this->from]);
    }

    public function test_document_sale_is_not_counted_twice(): void
    {
        $party = Party::create(['name' => 'Defter Testi Müşteri']);
        TradeDesk::saveSale(['mode' => 'party', 'party_id' => $party->id, 'manual_amount' => '6000', 'sale_date' => $this->from]); // belge + cari aynası
        PartyLedgerEntry::create(['party_id' => $party->id, 'type' => 'satis', 'amount' => 1000, 'entry_date' => $this->from, 'description' => 'Basit satış']);

        $r = $this->q(['types' => ['satis']]);

        $this->assertSame(2, $r['bulunan']);
        $this->assertEqualsWithDelta(7000, $r['türe_göre']['Satış']['toplam'], 0.01);
    }

    public function test_salary_is_not_purchase_and_grouping_by_party(): void
    {
        $emp = Party::create(['name' => 'Defter Testi Personel', 'is_employee' => true]);
        $sup = Party::create(['name' => 'Defter Testi Tedarikçi']);
        PartyLedgerEntry::create(['party_id' => $emp->id, 'type' => 'alis', 'amount' => 30000, 'entry_date' => $this->from, 'description' => 'Maaş — Ekim']);
        PartyLedgerEntry::create(['party_id' => $sup->id, 'type' => 'alis', 'amount' => 5000, 'entry_date' => $this->from, 'description' => 'Malzeme']);
        PartyLedgerEntry::create(['party_id' => $sup->id, 'type' => 'odeme', 'amount' => 3000, 'entry_date' => $this->from]);
        PartyLedgerEntry::create(['party_id' => $emp->id, 'type' => 'odeme', 'amount' => 30000, 'entry_date' => $this->from]);

        $r = $this->q(['types' => ['alis', 'maas']]);
        $this->assertEqualsWithDelta(5000, $r['türe_göre']['Alış']['toplam'], 0.01);
        $this->assertEqualsWithDelta(30000, $r['türe_göre']['Maaş']['toplam'], 0.01);

        $r = $this->q(['types' => ['odeme'], 'group_by' => 'party']);
        $this->assertSame('Defter Testi Personel', $r['gruplar'][0]['grup']);
        $this->assertEqualsWithDelta(30000, $r['gruplar'][0]['toplam'], 0.01);
        $this->assertEqualsWithDelta(3000, $r['gruplar'][1]['toplam'], 0.01);
    }

    public function test_text_search_spans_expense_and_purchase_and_limit_keeps_totals(): void
    {
        $sup = Party::create(['name' => 'Defter Testi Usta']);
        Expense::create(['amount' => 1500, 'expense_date' => $this->from, 'description' => 'Traktör yağı', 'payment_status' => 'paid']);
        Expense::create(['amount' => 500, 'expense_date' => $this->from, 'description' => 'traktor lastik', 'payment_status' => 'paid']);
        PartyLedgerEntry::create(['party_id' => $sup->id, 'type' => 'alis', 'amount' => 7000, 'entry_date' => $this->from, 'description' => 'Traktör tamiri']);
        Expense::create(['amount' => 999, 'expense_date' => $this->from, 'description' => 'Kira', 'payment_status' => 'paid']);

        $r = $this->q(['types' => ['gider', 'alis'], 'text' => 'trakt', 'limit' => 1, 'sort' => 'amount_desc']);

        $this->assertSame(3, $r['bulunan']);
        $this->assertCount(1, $r['satırlar']);
        $this->assertEqualsWithDelta(7000, $r['satırlar'][0]['tutar'], 0.01);
        $this->assertEqualsWithDelta(2000, $r['türe_göre']['Gider']['toplam'], 0.01, 'limit toplamı kesmez');
        $this->assertStringContainsString('TÜM 3', $r['not']);
    }

    public function test_product_history_shows_quantity_price_and_supplier(): void
    {
        $sack = Unit::firstOrCreate(['code' => 'cuval-defter'], ['name' => 'çuval']);
        $flour = Product::create(['name' => 'Defter Unu', 'type' => Product::TYPE_PRODUCT, 'unit_id' => $sack->id]);
        $sup = Party::create(['name' => 'Defter Testi Un']);
        TradeDesk::savePurchase(['mode' => 'party', 'party_id' => $sup->id, 'purchase_date' => $this->from,
            'lines' => [['product_id' => $flour->id, 'quantity' => '20', 'unit_price' => '850']]]);

        $r = $this->q(['product_id' => $flour->id, 'types' => ['alis'], 'limit' => 1]);

        $row = $r['satırlar'][0];
        $this->assertSame('Defter Testi Un', $row['cari']);
        $this->assertEqualsWithDelta(20, $row['miktar'], 0.01);
        $this->assertEqualsWithDelta(850, $row['birim_fiyat'], 0.01);
        $this->assertSame('çuval', $row['birim']);
    }

    public function test_record_origin_returns_its_conversation(): void
    {
        $e = Expense::create(['amount' => 25113, 'expense_date' => now(), 'description' => 'Karışık', 'payment_status' => 'paid']);
        $phone = '905550000888';
        WhatsappMessage::create(['phone' => $phone, 'direction' => 'in', 'body' => 'Akkuş ergenekon 5000 nakit 19483 kart 1630 gider']);
        WhatsappPendingExpense::create(['phone' => 'whatsapp:+' . $phone, 'status' => 'confirmed', 'summary' => 's',
            'extracted' => ['kind' => 'expense', 'created_records' => [['expense', $e->id]]]]);

        $r = ConversationLookup::run(['ref' => 'expense:' . $e->id]);

        $this->assertStringContainsString('19483 kart', $r['mesajlar'][0]['mesaj']);
        $this->assertArrayHasKey('not', ConversationLookup::run(['ref' => 'expense:999999999']));
    }
}
