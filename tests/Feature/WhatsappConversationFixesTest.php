<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\HubFirm;
use App\Models\HubPhone;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\PartyStatement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Arda vakası (9 Ekim, alacak-verecek): 5 dakikada 17 mesaj, çoğu çıkmaza girdi.
 * Alacak/borç kaydı, cari listesi, "anlamadım" yönlendirmesi, carisiz ödenmemiş gider borçta,
 * konuşma kaydı ve ilk mesajda rehber. AI mock'lanır; gerçek AI ile tekrar oynatma ayrıca yapıldı.
 */
class WhatsappConversationFixesTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905557770000';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.twilio.verify_signature' => false,
            'services.twilio.allowed_phones' => ['0555 777 00 00'],
            'modules.expenses' => true,
            'modules.cari_supplier' => true,
        ]);
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
            'kind' => ExpenseExtractor::KIND_EXPENSE, 'items' => [], 'totals_side' => null,
            'date_from' => null, 'date_to' => null, 'payment_type' => null, 'amount' => 0.0,
            'date' => now()->format('Y-m-d'), 'due_date' => null, 'description' => '',
            'project_id' => null, 'project_name' => null, 'party_id' => null, 'party_name' => null,
            'category_id' => null, 'category_name' => null, 'paid' => null, 'confidence' => 'high',
            'question' => null, 'is_new_entry' => false, 'debt_side' => null, 'reply' => null,
            'payment_purpose' => null, 'settles_expense_id' => null,
        ], $overrides);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()
            ->getContent(), ENT_QUOTES | ENT_XML1);
    }

    /** Eski kullanıcı gibi davran: ilk mesaj rehberi araya girmesin (rehber ayrı testte). */
    private function notFirstContact(): void
    {
        WhatsappMessage::create(['phone' => '905557770000', 'direction' => 'in', 'body' => 'önceki']);
    }

    public function test_debt_note_receivable_creates_ledger_receivable_for_new_party(): void
    {
        $this->notFirstContact();
        $this->fakeAi($this->entry([
            'kind' => ExpenseExtractor::KIND_DEBT_NOTE, 'debt_side' => 'receivable',
            'amount' => 40000, 'party_name' => 'Arda Test Ali', 'description' => 'Alacak kaydı',
        ]));

        $summary = $this->send("Arda Test Ali'den 40.000 tl alacağım var");
        $this->assertStringContainsString('Alacak kaydı', $summary);
        $this->assertStringContainsString('sana 40.000,00 ₺ borçlu', $summary);

        $this->assertStringContainsString('Kaydedildi', $this->send('evet'));
        $party = Party::where('name', 'Arda Test Ali')->firstOrFail();
        $entry = PartyLedgerEntry::where('party_id', $party->id)->sole();
        $this->assertSame(PartyLedgerEntry::TYPE_SALE, $entry->type);
        $this->assertSame('Alacak kaydı', $entry->description);
        $this->assertEqualsWithDelta(40000, PartyStatement::build($party)['balance'], 0.001); // bize borçlu
        $this->assertSame(0, Expense::where('party_id', $party->id)->count());          // maliyet değil
    }

    public function test_debt_note_without_name_asks_and_keeps_draft_until_name_given(): void
    {
        $this->notFirstContact();
        $this->fakeAi(
            $this->entry(['kind' => ExpenseExtractor::KIND_DEBT_NOTE, 'debt_side' => 'receivable', 'amount' => 40000]),
            $this->entry(['kind' => ExpenseExtractor::KIND_DEBT_NOTE, 'debt_side' => 'receivable', 'amount' => 40000, 'party_name' => 'Arda Test Veli']),
        );

        $this->assertStringContainsString('Kimden alacağın var?', $this->send('40.000 tl alacağım var'));
        $this->assertSame(1, WhatsappPendingExpense::where('phone', self::PHONE)->where('status', 'awaiting_confirmation')->count());

        $this->assertStringContainsString('Arda Test Veli (yeni cari) → sana 40.000,00 ₺ borçlu', $this->send('Arda Test Veli'));
        $this->send('evet');
        $this->assertEqualsWithDelta(40000, PartyStatement::build(Party::where('name', 'Arda Test Veli')->sole())['balance'], 0.001);
    }

    public function test_debt_note_payable_lowers_balance_and_is_refused_without_supplier_side(): void
    {
        $this->notFirstContact();
        $payable = $this->entry(['kind' => ExpenseExtractor::KIND_DEBT_NOTE, 'debt_side' => 'payable', 'amount' => 15000, 'party_name' => 'Arda Test Mehmet']);

        $this->fakeAi($payable);
        $this->assertStringContainsString('sen ona 15.000,00 ₺ borçlusun', $this->send("Arda Test Mehmet'e 15 bin borcum var"));
        $this->send('evet');
        $party = Party::where('name', 'Arda Test Mehmet')->sole();
        $this->assertSame(PartyLedgerEntry::TYPE_PURCHASE, PartyLedgerEntry::where('party_id', $party->id)->sole()->type);
        $this->assertEqualsWithDelta(-15000, PartyStatement::build($party)['balance'], 0.001);

        config(['modules.cari_supplier' => false]); // mimar / toptancı
        $this->fakeAi($payable);
        $this->assertStringContainsString('borç kaydı (biz borçluyuz) açık değil', $this->send("Arda Test Mehmet'e 15 bin borcum var"));
    }

    /** "Ne için verdin?" — numaralı menü yerine serbest cevap; niyet AI'dan, kayıttan önce özette gösterilir. */
    public function test_payment_without_debt_asks_purpose_and_reads_loan_intent(): void
    {
        $this->notFirstContact();
        $payment = ['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 8000, 'party_name' => 'Arda Test Ahmet', 'description' => "Ahmet'e ödeme"];
        $this->fakeAi($this->entry($payment), $this->entry($payment + ['payment_purpose' => 'loan']));

        $question = $this->send("Arda Test Ahmet'e 8 bin verdim");
        $this->assertStringContainsString('Ne için verdin?', $question);
        $this->assertStringNotContainsString('1)', $question);

        $summary = $this->send('geri alacağım');
        $this->assertStringContainsString('Tür: Borç verdin (geri alacaksın)', $summary);

        $this->send('evet');
        $party = Party::where('name', 'Arda Test Ahmet')->sole();
        $entry = PartyLedgerEntry::where('party_id', $party->id)->sole();
        $this->assertSame(PartyLedgerEntry::TYPE_PAYMENT, $entry->type);
        $this->assertSame("Borç verildi — Ahmet'e ödeme", $entry->description);
        $this->assertEqualsWithDelta(8000, PartyStatement::build($party)['balance'], 0.001); // bize borçlu
        $this->assertSame(0, Expense::where('party_id', $party->id)->count());               // maliyet değil
    }

    public function test_advance_stated_up_front_skips_purpose_question_without_duplicate_label(): void
    {
        $this->notFirstContact();
        $this->fakeAi($this->entry([
            'kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 20000, 'party_name' => 'Arda Test Mehmet A',
            'description' => 'Avans ödemesi', 'payment_purpose' => 'advance',
        ]));

        $summary = $this->send("Arda Test Mehmet A'ya 20 bin avans verdim");
        $this->assertStringContainsString('Tür: Avans (iş sonra yapılacak)', $summary);
        $this->assertStringNotContainsString('Ne için verdin?', $summary);

        $this->send('evet');
        $party = Party::where('name', 'Arda Test Mehmet A')->sole();
        $this->assertSame('Avans ödemesi', PartyLedgerEntry::where('party_id', $party->id)->sole()->description);
    }

    private function openRent(float $amount = 20000): Expense
    {
        return Expense::create(['expense_date' => '2031-01-05', 'amount' => $amount, 'payment_status' => 'unpaid', 'description' => 'Test ev kirası']);
    }

    /** "Kirayı ödedim": açık borç kapanır, yeni gider açılmaz — aynı kira iki kez sayılmaz. */
    public function test_paying_open_unpaid_expense_closes_it_without_new_cost(): void
    {
        $this->notFirstContact();
        $rent = $this->openRent();
        $before = Expense::count();
        $this->fakeAi($this->entry(['amount' => 0, 'paid' => true, 'description' => 'Ev kirası', 'settles_expense_id' => $rent->id]));

        $summary = $this->send('kirayı ödedim');
        $this->assertStringContainsString('Borç ödemesi', $summary);
        $this->assertStringContainsString('Ödenen: 20.000,00 ₺', $summary); // tutar söylenmedi → borcun tamamı
        $this->assertStringContainsString('Borç kapanır', $summary);

        $this->assertStringContainsString('Borç kapandı', $this->send('evet'));
        $this->assertSame($before, Expense::count());
        $this->assertSame('paid', $rent->fresh()->payment_status);
    }

    /** Kısmi ödeme: gider ikiye bölünür (ödenen + kalan), toplam gider değişmez. */
    public function test_partial_payment_splits_expense_and_keeps_total(): void
    {
        $this->notFirstContact();
        $rent = $this->openRent();
        $this->fakeAi($this->entry(['amount' => 10000, 'paid' => true, 'description' => 'Ev kirası', 'settles_expense_id' => $rent->id]));

        $this->assertStringContainsString('Kalan borç: 10.000,00 ₺', $this->send('kiranın 10 binini ödedim'));
        $this->send('evet');

        $rent->refresh();
        $rest = Expense::where('description', 'Test ev kirası (kalan)')->sole();
        $this->assertSame('paid', $rent->payment_status);
        $this->assertEqualsWithDelta(10000, (float) $rent->amount, 0.001);
        $this->assertSame('unpaid', $rest->payment_status);
        $this->assertEqualsWithDelta(10000, (float) $rest->amount, 0.001);
        $this->assertSame('2031-01-05', $rest->expense_date->toDateString());
    }

    public function test_yeni_turns_suggested_settlement_into_a_separate_expense(): void
    {
        $this->notFirstContact();
        $rent = $this->openRent();
        $before = Expense::count();
        $this->fakeAi($this->entry(['amount' => 20000, 'paid' => true, 'description' => 'Kira', 'settles_expense_id' => $rent->id]));

        $this->send('20 bin kira ödedim');
        $this->assertStringContainsString('Gider — kontrol et', $this->send('yeni'));
        $this->send('evet');

        $this->assertSame($before + 1, Expense::count());
        $this->assertSame('unpaid', $rent->fresh()->payment_status);
    }

    public function test_payment_larger_than_open_debt_is_treated_as_new_expense(): void
    {
        $this->notFirstContact();
        $rent = $this->openRent();
        $this->fakeAi($this->entry(['amount' => 25000, 'paid' => true, 'description' => 'Kira', 'settles_expense_id' => $rent->id]));

        $summary = $this->send('25 bin kira ödedim');

        $this->assertStringContainsString('Gider — kontrol et', $summary);
        $this->assertStringNotContainsString('Borç ödemesi', $summary);
    }

    public function test_unpaid_expense_without_party_shows_hint_not_question(): void
    {
        $this->notFirstContact();
        $this->fakeAi($this->entry(['amount' => 20000, 'paid' => false, 'description' => 'Ev kirası']));

        $summary = $this->send('Borç olarak 20 bin ev kirası');

        $this->assertStringContainsString('💡 Kime borçlu olduğunu da yazarsan', $summary);
        $this->assertStringContainsString('Onaylamak için *evet*', $summary);
    }

    /**
     * Sınırlı bağlam: son konuşulan cari (30 dk) AI'a verilir ("ondan 20 bin aldım"); süre dolunca unutulur.
     * Mesajın gerçekten o cariye bağlanıp bağlanmadığı AI'ın işi — gerçek AI ile ayrıca tekrar oynatıldı.
     *
     * @return list<array|null> extract()'a her çağrıda verilen son-cari bağlamı
     */
    private function captureContext(array ...$responses): \ArrayObject
    {
        $seen = new \ArrayObject;
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturnUsing(function ($text, $image = null, $previous = null, $lastParty = null) use ($seen, &$responses) {
            $seen[] = $lastParty;

            return array_shift($responses);
        });
        $this->app->instance(ExpenseExtractor::class, $mock);

        return $seen;
    }

    public function test_last_talked_party_is_given_to_ai_and_forgotten_after_30_minutes(): void
    {
        $this->notFirstContact();
        $ali = Party::create(['name' => 'Bağlam Test Ali']);
        PartyLedgerEntry::create(['party_id' => $ali->id, 'entry_date' => now()->toDateString(), 'type' => PartyLedgerEntry::TYPE_SALE, 'amount' => 40000]);
        $seen = $this->captureContext(
            $this->entry(['kind' => ExpenseExtractor::KIND_BALANCE_QUERY, 'party_id' => $ali->id]),
            $this->entry(['kind' => ExpenseExtractor::KIND_COLLECTION, 'party_id' => $ali->id, 'amount' => 20000]),
            $this->entry(['kind' => ExpenseExtractor::KIND_HELP, 'reply' => 'Kimden?']),
        );

        $this->send("Bağlam Test Ali'nin borcu ne");
        $this->assertNull($seen[0]);                       // henüz kimse konuşulmadı

        $this->send('ondan 20 bin aldım');
        $this->assertSame($ali->id, $seen[1]['party_id']);
        $this->assertEqualsWithDelta(40000, $seen[1]['balance'], 0.001); // "hepsini" tutarı için
        $this->send('iptal');

        $this->travel(31)->minutes();
        $this->send('ondan 5 bin aldım');
        $this->assertNull($seen[2]);                       // 30 dk sonra unutuldu
    }

    public function test_party_created_by_a_record_becomes_context_for_next_message(): void
    {
        $this->notFirstContact();
        $seen = $this->captureContext(
            $this->entry(['kind' => ExpenseExtractor::KIND_COLLECTION, 'party_name' => 'Bağlam Test Yeni', 'amount' => 10000]),
            $this->entry(['kind' => ExpenseExtractor::KIND_HELP, 'reply' => '—']),
        );

        $this->send('Bağlam Test Yeni 10 bin ödedi');
        $this->send('evet');
        $this->send('5 bin daha verdi');

        $this->assertSame(Party::where('name', 'Bağlam Test Yeni')->sole()->id, $seen[1]['party_id']);
    }

    public function test_party_list_shows_balances(): void
    {
        $this->notFirstContact();
        $party = Party::create(['name' => 'Arda Test Liste']);
        PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => now()->toDateString(), 'type' => PartyLedgerEntry::TYPE_SALE, 'amount' => 99999999]);
        $this->fakeAi($this->entry(['kind' => ExpenseExtractor::KIND_PARTY_LIST]));

        $reply = $this->send('Hangi carim var');

        $this->assertStringContainsString('Carilerin', $reply);
        $this->assertStringContainsString('Arda Test Liste: sana borcu 99.999.999,00 ₺', $reply);
    }

    public function test_help_kind_returns_ai_guidance_without_draft_and_is_logged_as_unclear(): void
    {
        $this->notFirstContact();
        $this->fakeAi($this->entry(['kind' => ExpenseExtractor::KIND_HELP, 'reply' => "Cari, bir işlemle açılır. Örnek: \"Ali'den 40 bin alacağım var\""]));

        $this->assertStringContainsString('Cari, bir işlemle açılır', $this->send('Cari hesap kayıt'));
        $this->assertSame(0, WhatsappPendingExpense::where('phone', self::PHONE)->count());

        $in = WhatsappMessage::where('phone', '905557770000')->where('direction', 'in')->latest('id')->first();
        $this->assertSame('Cari hesap kayıt', $in->body);
        $this->assertSame('help', $in->kind);
        $this->assertContains('help', WhatsappMessage::UNCLEAR_KINDS);
        $out = WhatsappMessage::where('phone', '905557770000')->where('direction', 'out')->latest('id')->first();
        $this->assertStringContainsString('Cari, bir işlemle açılır', $out->body);
    }

    public function test_total_debt_includes_unpaid_expenses_without_party(): void
    {
        $this->notFirstContact();
        Expense::create(['expense_date' => now()->toDateString(), 'amount' => 98765432, 'payment_status' => 'unpaid', 'description' => 'Arda test ev kirası']);
        $this->fakeAi($this->entry(['kind' => ExpenseExtractor::KIND_TOTALS_QUERY, 'totals_side' => 'payable']));

        $reply = $this->send('Tüm borç');

        $this->assertStringContainsString('Ödenmemiş giderler (cari yok)', $reply);
        $this->assertStringContainsString('Arda test ev kirası', $reply);
        $this->assertStringNotContainsString('Toplam borcun: 0 ₺', $reply);
    }

    public function test_first_message_gets_guide_as_second_bubble_only_once(): void
    {
        $firm = HubFirm::create(['name' => 'Karşılama Test', 'url' => 'https://example.test']);
        HubPhone::create(['phone' => '0555 777 00 00', 'hub_firm_id' => $firm->id])
            ->forceFill(['created_at' => '2026-10-12 10:00:00'])->save(); // karşılamadan sonra eklenen numara
        $this->fakeAi(
            $this->entry(['amount' => 47, 'description' => 'Otobüs', 'paid' => true]),
            $this->entry(['amount' => 50, 'description' => 'Çay', 'paid' => true]),
        );

        $first = $this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => '47 tl otobüs', 'NumMedia' => 0])->getContent();
        $this->assertSame(2, substr_count($first, '<Message>'));
        $this->assertStringContainsString('Hoş geldin', $first);
        $this->assertStringContainsString('Kaydetmek için', $first);

        $this->send('evet');
        $second = $this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => '50 tl çay', 'NumMedia' => 0])->getContent();
        $this->assertSame(1, substr_count($second, '<Message>'));
    }

    /** Kullanıcı kararı: karşılamadan önce eklenmiş numaralar (mevcut müşteriler) hiç almaz. */
    public function test_phone_registered_before_welcome_feature_never_gets_welcome(): void
    {
        $firm = HubFirm::create(['name' => 'Eski Müşteri Test', 'url' => 'https://example.test']);
        $phone = HubPhone::create(['phone' => '0555 777 00 00', 'hub_firm_id' => $firm->id]);
        $phone->forceFill(['created_at' => '2026-10-09 21:00:00'])->save();
        $this->fakeAi($this->entry(['amount' => 47, 'description' => 'Otobüs', 'paid' => true]));

        $this->assertStringNotContainsString('Hoş geldin', $this->send('47 tl otobüs'));
    }

    public function test_phone_not_in_hub_gets_no_welcome(): void
    {
        $this->fakeAi($this->entry(['amount' => 47, 'description' => 'Otobüs', 'paid' => true]));

        $this->assertStringNotContainsString('Hoş geldin', $this->send('47 tl otobüs'));
    }

    public function test_nasil_returns_sector_guide_without_ai(): void
    {
        $this->notFirstContact();
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldNotReceive('extract');
        $this->app->instance(ExpenseExtractor::class, $mock);

        $reply = $this->send('Nasıl?');

        $this->assertStringContainsString('Kaydetmek için', $reply);
        $this->assertStringContainsString("Ali'den 40 bin alacağım var", $reply);
        $this->assertStringContainsString('Bu ay ne kadar harcadım?', $reply);
    }
}
