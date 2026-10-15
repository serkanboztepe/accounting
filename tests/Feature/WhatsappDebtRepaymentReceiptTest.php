<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\PartyStatement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Taner (13 Ekim): "Hazal'a borcum ödendi" borç kaydı sanıldı + dekont PDF'i okunamadı.
 * Borç ödemesi = ödeme (kayıtlı borç yoksa eski borç + ödeme, bakiye 0); PDF dekont okunur, banka masrafı gidere. AI mock'lanır.
 */
class WhatsappDebtRepaymentReceiptTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905558880002';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.twilio.verify_signature' => false,
            'services.twilio.allowed_phones' => ['0555 888 00 02'],
            'services.twilio.sid' => 'AC_test', 'services.twilio.token' => 'x',
            'modules.smart_assistant' => false, 'services.anthropic.chat_assistant' => false,
            'modules.expenses' => true, 'modules.projects' => false, 'modules.cari_supplier' => true,
        ]);
        Http::fake(['*' => Http::response('%PDF-1.4 dekont', 200, ['Content-Type' => 'application/pdf'])]);
        WhatsappPendingExpense::where('status', 'awaiting_confirmation')->update(['status' => 'superseded']);
    }

    private function entry(array $o): array
    {
        return array_merge([
            'kind' => ExpenseExtractor::KIND_PAYMENT, 'items' => [], 'payment_type' => null, 'amount' => 0.0,
            'date' => now()->format('Y-m-d'), 'due_date' => null, 'description' => '', 'project_id' => null,
            'project_name' => null, 'party_id' => null, 'party_name' => null, 'party_said' => null, 'category_id' => null,
            'category_name' => null, 'paid' => null, 'confidence' => 'high', 'question' => null, 'is_new_entry' => true,
            'debt_repayment' => false, 'bank_fee' => null,
        ], $o);
    }

    private function fakeAi(array ...$responses): Mockery\MockInterface
    {
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturn(...$responses);
        $this->app->instance(ExpenseExtractor::class, $mock);

        return $mock;
    }

    private function send(string $body, array $extra = []): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', $extra + ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    private function sendPdf(string $body = ''): string
    {
        return $this->send($body, ['NumMedia' => 1, 'MediaUrl0' => 'https://api.twilio.com/media/ME1', 'MediaContentType0' => 'application/pdf']);
    }

    private function balance(string $name): float
    {
        return PartyStatement::build(Party::where('name', $name)->sole())['balance'];
    }

    /** AI "borç kaydı" dese de kod ödemeye çevirir; kayıtlı borç yok → eski borç + ödeme, bakiye 0. */
    public function test_borcum_odendi_with_no_recorded_debt_writes_debt_and_payment(): void
    {
        $this->fakeAi($this->entry(['kind' => ExpenseExtractor::KIND_DEBT_NOTE, 'debt_side' => 'payable', 'amount' => 100000,
            'party_name' => 'Hazal Test', 'description' => 'Hatır çeki borcu']));

        $reply = $this->send("Hazal test'e verilen hatır çekinden borcum ödendi 100000");
        $this->assertStringContainsString('*borcunu ödedin*', $reply);
        $this->assertStringContainsString('Bu borç kayıtlı değildi', $reply);
        $this->assertStringNotContainsString('Ne için verdin', $reply);
        $this->assertStringContainsString('→ 0', $reply);

        $this->send('evet');
        $party = Party::where('name', 'Hazal Test')->sole();
        $this->assertSame(['alis', 'odeme'], PartyLedgerEntry::where('party_id', $party->id)->orderBy('id')->pluck('type')->all());
        $this->assertSame(0.0, $this->balance('Hazal Test'));
    }

    public function test_partly_recorded_debt_adds_only_missing_part(): void
    {
        $party = Party::create(['name' => 'Kısmi Borç']);
        PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => now()->subDay(), 'type' => 'alis', 'description' => 'Borç kaydı', 'amount' => 40000]);
        $this->fakeAi($this->entry(['amount' => 100000, 'party_id' => $party->id, 'debt_repayment' => true]));

        $this->assertStringContainsString('40.000,00 ₺ kayıtlıydı', $this->send('Kısmi borcumu ödedim 100 bin'));
        $this->send('evet');
        $this->assertEquals(60000, PartyLedgerEntry::where('party_id', $party->id)->where('description', 'like', '%kayıtlı değildi%')->value('amount'));
        $this->assertSame(0.0, $this->balance('Kısmi Borç'));
    }

    public function test_fully_recorded_debt_is_just_closed(): void
    {
        $party = Party::create(['name' => 'Tam Borç']);
        PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => now()->subDay(), 'type' => 'alis', 'description' => 'Borç kaydı', 'amount' => 100000]);
        $this->fakeAi($this->entry(['amount' => 100000, 'party_id' => $party->id, 'debt_repayment' => true]));

        $this->assertStringNotContainsString('kayıtlı değildi', $this->send('Tam borcumu kapattım 100 bin'));
        $this->send('evet');
        $this->assertSame(2, PartyLedgerEntry::where('party_id', $party->id)->count());
        $this->assertSame(0.0, $this->balance('Tam Borç'));
    }

    public function test_guard_only_fires_on_our_debt_plus_past_payment(): void
    {
        $note = fn (string $side) => $this->entry(['kind' => ExpenseExtractor::KIND_DEBT_NOTE, 'debt_side' => $side, 'amount' => 5]);
        $g = fn (array $d, string $t) => ExpenseExtractor::guardDebtRepayment($d, $t)['kind'];

        foreach (["Hazal'a borcum ödendi", 'Ali\'ye borcumu ödedim', 'borcumu kapattım', 'Veli\'ye borcumu iade ettim', 'borcum kapandı'] as $t) {
            $this->assertSame(ExpenseExtractor::KIND_PAYMENT, $g($note('payable'), $t), $t);
        }
        foreach (["Ali'ye 50 bin borcum var", 'Ali\'ye borcumu ödeyeceğim', 'Ali borcunu ödedi', 'çuvalları iade ettim', 'Mehmet\'e 15 bin borcum var'] as $t) {
            $this->assertSame(ExpenseExtractor::KIND_DEBT_NOTE, $g($note('payable'), $t), $t);
        }
        $this->assertSame(ExpenseExtractor::KIND_DEBT_NOTE, $g($note('receivable'), 'Ali\'nin borcum ödendi'), 'alacak notuna dokunulmaz');
    }

    /** Dekont PDF'i okunur; banka masrafı gider açıksa ayrı gider. */
    public function test_pdf_receipt_payment_with_bank_fee_as_expense(): void
    {
        $this->fakeAi($this->entry(['amount' => 100000, 'party_name' => 'Evrim Dekont', 'payment_type' => 'eft',
            'description' => 'borç iade', 'debt_repayment' => true, 'bank_fee' => 16.76]));

        $reply = $this->sendPdf();
        $this->assertStringContainsString('Banka masrafı 16,76 ₺ → gider', $reply);
        $this->send('evet');

        $this->assertSame(0.0, $this->balance('Evrim Dekont'));
        $fee = Expense::where('description', 'like', 'Banka masrafı%Evrim Dekont')->sole();
        $this->assertEquals(16.76, (float) $fee->amount);
        $this->assertSame('paid', $fee->payment_status);
    }

    public function test_bank_fee_not_written_when_expenses_off(): void
    {
        config(['modules.expenses' => false]);
        $this->fakeAi($this->entry(['amount' => 5000, 'party_name' => 'Gidersiz Dekont', 'debt_repayment' => true, 'bank_fee' => 16.76]));

        $this->assertStringContainsString('gider takibi açık olmadığı için kaydetmedim', $this->sendPdf());
        $before = Expense::count();
        $this->send('evet');
        $this->assertSame($before, Expense::count());
    }

    /** Taner sırası: yazı → tutar sorusu → PDF: belge bekleyen kayda eklenir, yeni kayıt açılmaz. */
    public function test_pdf_after_amount_question_completes_pending_draft(): void
    {
        $mock = $this->fakeAi(
            $this->entry(['kind' => ExpenseExtractor::KIND_DEBT_NOTE, 'debt_side' => 'payable', 'party_name' => 'Hazal Sıra', 'question' => 'Ne kadardı?']),
            $this->entry(['amount' => 100000, 'party_name' => 'Hazal Sıra', 'debt_repayment' => true, 'bank_fee' => 16.76, 'is_new_entry' => false]),
        );

        $this->assertStringContainsString('Ne kadardı?', $this->send('Hazal sıra verilen hatır çekinden borcum ödendi'));
        $reply = $this->sendPdf();
        $this->assertStringContainsString('*borcunu ödedin*', $reply);

        $mock->shouldHaveReceived('extract')->withArgs(fn ($text, $image, $previous) => $image !== null && $previous !== null);
        $this->assertSame(1, WhatsappPendingExpense::where('phone', self::PHONE)->where('status', 'awaiting_confirmation')->count());
        $this->send('evet');
        $this->assertSame(0.0, $this->balance('Hazal Sıra'));
    }

    /** Dekontta resmî tam ad ("Evrim Hazal Doğan") carinin adını içeriyor → sorulmaz, bilgi satırı; "o değil" → yeni cari. */
    public function test_document_full_name_containing_party_name_is_not_asked(): void
    {
        $party = Party::create(['name' => 'Hazal Belge']);
        $this->fakeAi($this->entry(['amount' => 5000, 'party_id' => $party->id, 'party_said' => 'Evrim Hazal Belge', 'debt_repayment' => true]));

        $reply = $this->sendPdf();
        $this->assertStringNotContainsString('Aynı kişi mi', $reply);
        $this->assertStringContainsString('Dekonttaki ad: Evrim Hazal Belge', $reply);

        $this->assertStringContainsString('Evrim Hazal Belge (yeni cari)', $this->send('o değil'));
    }

    public function test_document_name_not_containing_party_name_still_asks(): void
    {
        $party = Party::create(['name' => 'Nusaybin bayisi Ayten Belge']);
        $this->fakeAi($this->entry(['amount' => 5000, 'party_id' => $party->id, 'party_said' => 'Ayten Kaya']));

        $this->assertStringContainsString('*Aynı kişi mi?*', $this->sendPdf());
    }

    /** Elle yazılan mesajda kural aynen: fazladan söz başka biri olabilir. */
    public function test_typed_message_with_extra_words_still_asks(): void
    {
        $party = Party::create(['name' => 'Ali Metin']);
        $this->fakeAi($this->entry(['amount' => 5000, 'party_id' => $party->id, 'party_said' => "Ali Metin'in oğlu"]));

        $this->assertStringContainsString('*Aynı kişi mi?*', $this->send("Ali Metin'in oğluna 5000 ödedim"));
    }

    public function test_other_documents_still_get_clear_answer(): void
    {
        $this->assertStringContainsString('PDF', $this->send('', ['NumMedia' => 1, 'MediaUrl0' => 'https://x/y', 'MediaContentType0' => 'application/vnd.ms-excel']));
    }
}
