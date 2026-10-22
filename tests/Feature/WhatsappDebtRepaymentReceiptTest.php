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

    private function writerIs(string $name): void
    {
        \App\Models\HubPhone::where('phone', '905558880002')->delete();
        $firm = \App\Models\HubFirm::create(['name' => 'Biz Firma Test', 'url' => 'https://example.test']);
        \App\Models\HubPhone::create(['phone' => '905558880002', 'hub_firm_id' => $firm->id, 'name' => $name]);
    }

    /** Ferhat (15 Ekim): dekontta alıcı kendisi → AI "Sen → FERHAT YILDIZ ödedin" dese de tahsilat, gönderen sorulur. */
    public function test_receipt_with_our_name_as_party_becomes_collection_and_asks_sender(): void
    {
        $this->writerIs('Ferhat Biz Yıldız');
        $this->fakeAi(
            $this->entry(['amount' => 75000, 'party_name' => 'FERHAT BİZ YILDIZ', 'party_said' => 'FERHAT BİZ YILDIZ', 'payment_type' => 'eft']),
            $this->entry(['kind' => ExpenseExtractor::KIND_COLLECTION, 'amount' => 75000, 'party_name' => 'Fuat Gönderen', 'is_new_entry' => false]),
        );

        $reply = $this->sendPdf();
        $this->assertStringContainsString('Bu para kimden geldi?', $reply);
        $this->assertStringNotContainsString('ödedin', $reply);
        $this->assertSame(0, Party::where('name', 'FERHAT BİZ YILDIZ')->count());

        $this->assertStringContainsString('Fuat Gönderen (yeni cari) → *sana*: 75.000,00', $this->send('Fuat Gönderen'));
        $this->send('evet');
        $this->assertSame(-75000.0, $this->balance('Fuat Gönderen'));
        $this->assertSame(0, Party::where('name', 'FERHAT BİZ YILDIZ')->count(), 'kendi adı cari açılmaz');
    }

    /** "BANA GELDİ" düzeltmesi: AI borç kaydı dese de tahsilat. */
    public function test_bana_geldi_turns_payment_or_debt_note_into_collection(): void
    {
        $d = $this->entry(['kind' => ExpenseExtractor::KIND_DEBT_NOTE, 'debt_side' => 'payable', 'amount' => 5, 'party_name' => 'Ali Test']);
        $this->assertSame(ExpenseExtractor::KIND_COLLECTION, ExpenseExtractor::guardSelfParty($d, [], 'BANA GELDİ')['kind']);
        $p = $this->entry(['amount' => 5, 'party_name' => 'Ali Test']);
        $this->assertSame(ExpenseExtractor::KIND_COLLECTION, ExpenseExtractor::guardSelfParty($p, [], 'hesabıma geldi')['kind']);
        $this->assertSame(ExpenseExtractor::KIND_PAYMENT, ExpenseExtractor::guardSelfParty($p, [], 'Ali\'ye gönderdim')['kind']);
    }

    /** Taner: gönderen biz, alıcı başkası → ödeme aynen; kısa/tek kelime ad (ör. "Ferhat") başka cari olabilir, dokunulmaz. */
    public function test_other_party_and_partial_name_untouched(): void
    {
        $self = ['Taner Biz Köse'];
        $p = $this->entry(['amount' => 5, 'party_name' => 'Hazal Doğan', 'debt_repayment' => true]);
        $this->assertSame($p, ExpenseExtractor::guardSelfParty($p, $self, ''));
        $q = $this->entry(['amount' => 5, 'party_name' => 'Taner']);
        $this->assertSame('Taner', ExpenseExtractor::guardSelfParty($q, $self, '')['party_name']);
    }

    private function sendPhoto(): string
    {
        return $this->send('', ['NumMedia' => 1, 'MediaUrl0' => 'https://api.twilio.com/media/ME2', 'MediaContentType0' => 'image/jpeg']);
    }

    /** Gider kapalı (Ferhat): ilk fiş → "Ödedin mi?"; veresiye → alış; sonraki fiş sormadan veresiye. */
    public function test_receipt_in_no_expense_firm_asks_then_follows_habit(): void
    {
        config(['modules.expenses' => false]);
        $fuel = fn ($amt) => $this->entry(['kind' => ExpenseExtractor::KIND_PURCHASE, 'amount' => $amt, 'party_name' => 'Fiş Petrol',
            'seller_name' => 'Fiş Petrol', 'description' => '55,45 lt Kurşunsuz 95 — 24 FC 004', 'paid' => null]);
        $this->fakeAi($fuel(4760.38), $fuel(1000));

        $reply = $this->sendPhoto();
        $this->assertStringContainsString('*Ödedin mi?*', $reply);
        $this->assertStringContainsString('24 FC 004', $reply);
        $this->assertStringContainsString('Önce', $this->send('evet'), 'cevapsız kaydedilmez');

        $this->assertStringContainsString('(ona borçlandın)', $this->send('veresiye'));
        $this->send('evet');
        $this->assertSame(-4760.38, $this->balance('Fiş Petrol'));

        $reply = $this->sendPhoto();
        $this->assertStringNotContainsString('Ödedin mi?', $reply);
        $this->assertStringContainsString('Önceki alışlar gibi *veresiye*', $reply);
        $this->send('evet');
        $this->assertSame(-5760.38, $this->balance('Fiş Petrol'));
        $this->assertSame(0, PartyLedgerEntry::whereHas('party', fn ($q) => $q->where('name', 'Fiş Petrol'))->where('type', 'odeme')->count());
    }

    public function test_habit_paid_when_last_purchase_was_paid_same_day(): void
    {
        config(['modules.expenses' => false]);
        $party = Party::create(['name' => 'Peşin Market']);
        PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => now()->subDays(3), 'type' => 'alis', 'amount' => 300, 'description' => 'Su']);
        PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => now()->subDays(3), 'type' => 'odeme', 'amount' => 300, 'description' => 'Alış ödemesi — Su']);
        $this->fakeAi($this->entry(['kind' => ExpenseExtractor::KIND_PURCHASE, 'amount' => 500, 'party_id' => $party->id, 'paid' => null]));

        $this->assertStringContainsString('Önceki alışlar gibi *peşin*', $this->sendPhoto());
        $this->send('evet');
        $this->assertSame(0.0, $this->balance('Peşin Market'));
    }

    /** Gider açık: fişe "ödemedim" → gider değil, satıcının carisine veresiye alış. "ödendi" → gider (değişmedi). */
    public function test_expense_firm_unpaid_receipt_goes_to_seller_cari(): void
    {
        $receipt = $this->entry(['kind' => ExpenseExtractor::KIND_EXPENSE, 'amount' => 850, 'seller_name' => 'Fiş Market', 'description' => 'Temizlik', 'paid' => null]);
        $this->fakeAi($receipt, $receipt);
        $before = Expense::count();

        $this->sendPhoto();
        $this->assertStringContainsString('🛒 *Alış* ← Fiş Market (yeni cari): 850,00 ₺ — Temizlik (ona borçlandın)', $this->send('ödemedim'));
        $this->send('evet');
        $this->assertSame($before, Expense::count());
        $this->assertSame(-850.0, $this->balance('Fiş Market'));

        $this->sendPhoto();
        $this->send('evet');
        $this->assertSame($before + 1, Expense::count(), 'ödendi/söylenmedi → gider aynen');
    }

    public function test_other_documents_still_get_clear_answer(): void
    {
        $this->assertStringContainsString('PDF', $this->send('', ['NumMedia' => 1, 'MediaUrl0' => 'https://x/y', 'MediaContentType0' => 'application/vnd.ms-excel']));
    }

    /** Firma 7, 21.10: 3 çek fotoğrafı birden → ikisine "tekrar gönder" dendi. Meşgulken gelen fotoğraf sıraya alınır, sonra işlenir. */
    public function test_photo_while_busy_is_queued_and_processed_after(): void
    {
        $this->fakeAi($this->entry(['kind' => ExpenseExtractor::KIND_EXPENSE, 'amount' => 750, 'description' => 'Sıradaki fotoğraf fişi', 'paid' => true]));
        $lockName = \App\Tenancy\Tenancy::key('wa-msg:' . \App\Support\Phone::normalize(self::PHONE));
        config(['services.whatsapp.from' => '+14786665916']);
        \Illuminate\Support\Facades\Cache::lock($lockName, 1)->get(); // önceki mesaj 1 sn daha sürüyor
        \App\Http\Controllers\WhatsappWebhookController::$lockWait = 0;
        try {
            $reply = $this->sendPdf();
        } finally {
            \App\Http\Controllers\WhatsappWebhookController::$lockWait = 12;
        }

        $this->assertStringContainsString('Bunu da aldım', $reply);
        $this->assertStringNotContainsString('tekrar gönder', $reply);
        // İstek kapandıktan sonra sıra geldi: fiş işlendi, kart ayrı mesajla gitti.
        $this->assertSame('Sıradaki fotoğraf fişi', WhatsappPendingExpense::where('phone', self::PHONE)->latest('id')->first()?->extracted['description']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'Messages.json') && str_contains((string) ($r['Body'] ?? ''), '750'));
        $this->assertSame(1, \App\Models\WhatsappMessage::where('phone', \App\Support\Phone::normalize(self::PHONE))
            ->where('direction', 'in')->where('created_at', '>=', now()->subMinute())->count(), 'gelen mesaj bir kez kaydedilir');
    }

    /**
     * Firma 7, 22.10 11:16: tek fotoğraf, okuma uzun sürdü, AYNI mesaj (aynı MessageSid) ikinci kez geldi → "yeni fotoğraf" sanılıp
     * sıraya alındı ("Fotoğrafı aldım…" + "Sırada 1 fotoğraf var"). İşlenmekte olan mesajın tekrarı sessizce atlanır.
     */
    public function test_retry_of_message_in_progress_is_not_queued(): void
    {
        $lockName = \App\Tenancy\Tenancy::key('wa-msg:' . \App\Support\Phone::normalize(self::PHONE));
        \Illuminate\Support\Facades\Cache::lock($lockName, 1)->get();                         // ilk istek hâlâ işleniyor
        \Illuminate\Support\Facades\Cache::add(\App\Tenancy\Tenancy::key('wa-sid:SMRETRY1'), true, now()->addDay());
        \App\Http\Controllers\WhatsappWebhookController::$lockWait = 0;
        try {
            $reply = $this->send('', ['NumMedia' => 1, 'MediaUrl0' => 'https://api.twilio.com/media/ME1',
                'MediaContentType0' => 'application/pdf', 'MessageSid' => 'SMRETRY1']);
        } finally {
            \App\Http\Controllers\WhatsappWebhookController::$lockWait = 12;
        }

        $this->assertStringNotContainsString('Bunu da aldım', $reply);
        $this->assertSame([], (array) \Illuminate\Support\Facades\Cache::get(\App\Tenancy\Tenancy::key('wa-photo-queue:'
            . \App\Support\Phone::normalize(self::PHONE)), []));
    }

    /** 21.10 (2. deneme): sıradaki fotoğraf açık kartın cevabı beklenirken işlenip ona karışmıştı — kart kapanınca işlenir. */
    public function test_queued_photo_waits_for_open_draft(): void
    {
        config(['services.whatsapp.from' => '+14786665916']);
        $this->fakeAi(
            $this->entry(['kind' => ExpenseExtractor::KIND_EXPENSE, 'amount' => 300, 'description' => 'Birinci fiş', 'paid' => true]),
            $this->entry(['kind' => ExpenseExtractor::KIND_EXPENSE, 'amount' => 450, 'description' => 'İkinci fiş', 'paid' => true]),
        );
        $this->sendPdf(); // birinci fiş: kart açık, "evet" bekliyor
        $this->assertSame('Birinci fiş', WhatsappPendingExpense::where('phone', self::PHONE)->where('status', 'awaiting_confirmation')->sole()->extracted['description']);

        $lockName = \App\Tenancy\Tenancy::key('wa-msg:' . \App\Support\Phone::normalize(self::PHONE));
        \Illuminate\Support\Facades\Cache::lock($lockName, 1)->get();
        \App\Http\Controllers\WhatsappWebhookController::$lockWait = 0;
        try {
            $this->assertStringContainsString('Bunu da aldım', $this->sendPdf());
        } finally {
            \App\Http\Controllers\WhatsappWebhookController::$lockWait = 12;
        }
        // Açık kart varken sıradaki işlenmedi, açık kart değişmedi.
        $open = WhatsappPendingExpense::where('phone', self::PHONE)->where('status', 'awaiting_confirmation')->get();
        $this->assertSame(['Birinci fiş'], $open->map(fn ($p) => $p->extracted['description'])->all());

        $this->send('evet'); // birinci kaydedildi → sıradaki işlenir
        $this->assertSame('İkinci fiş', WhatsappPendingExpense::where('phone', self::PHONE)->where('status', 'awaiting_confirmation')->sole()->extracted['description']);
        Http::assertSent(fn ($r) => str_contains((string) ($r['Body'] ?? ''), 'Sıradaki fotoğraf'));
        $this->assertSame(1, Expense::where('description', 'Birinci fiş')->count());
    }

    /** 21.10: Opus çek sayfasında ~5'te 1 çekleri boş verdi → kullanıcıya sormadan önce fotoğraf bir kez daha okunur. */
    public function test_unread_cheques_are_reread_once_automatically(): void
    {
        config(['services.whatsapp.from' => '+14786665916']);
        $party = Party::create(['name' => 'İkinci Okuma Seramik']);
        $base = ['amount' => 400000, 'party_id' => $party->id, 'description' => 'Seramik 4 adet çek', 'payment_type' => 'check',
            'work_status' => 'not_yet', 'work_status_known' => true];
        $mock = $this->fakeAi($this->entry($base), $this->entry($base + ['checks' => [
            ['number' => '2023471', 'due_date_text' => '28.02.2027', 'amount' => 200000], ['number' => '2023472', 'due_date_text' => '31.03.2027', 'amount' => 200000]]]));

        $this->assertStringContainsString('bir daha bakıyorum', $this->sendPdf());

        $open = WhatsappPendingExpense::where('phone', self::PHONE)->where('status', 'awaiting_confirmation')->sole();
        $this->assertSame(['2023471', '2023472'], array_column($open->extracted['checks'], 'number'));
        $mock->shouldHaveReceived('extract')->withArgs(fn ($text) => str_contains((string) $text, 'HER çeki'))->once();
        Http::assertSent(fn ($r) => str_contains((string) ($r['Body'] ?? ''), '2023471'));
        $this->assertSame(1, \App\Models\WhatsappMessage::where('phone', \App\Support\Phone::normalize(self::PHONE))
            ->where('direction', 'in')->where('created_at', '>=', now()->subMinute())->count(), 'gelen mesaj bir kez');
    }

    /** İkinci okumada da çek yoksa soru sorulur (döngü yok). */
    public function test_unread_twice_asks_user(): void
    {
        config(['services.whatsapp.from' => '+14786665916']);
        $party = Party::create(['name' => 'İkinci Okuma Boş']);
        $base = ['amount' => 400000, 'party_id' => $party->id, 'description' => 'Seramik 4 adet çek', 'payment_type' => 'check',
            'work_status' => 'not_yet', 'work_status_known' => true];
        $this->fakeAi($this->entry($base), $this->entry($base));

        $this->sendPdf();

        Http::assertSent(fn ($r) => str_contains((string) ($r['Body'] ?? ''), 'Fotoğraftaki çekleri okuyamadım'));
        $this->assertSame(0, \App\Models\PartyLedgerEntry::where('party_id', $party->id)->count());
    }

    /** 21.10 10:54: fotoğraf okuma 15 sn'yi aşınca Twilio cevabı bekleyemedi, soru kartı hiç gitmedi → geç cevap API ile gider. */
    public function test_late_reply_is_sent_via_api(): void
    {
        config(['services.whatsapp.from' => '+14786665916']);
        $this->fakeAi($this->entry(['kind' => ExpenseExtractor::KIND_EXPENSE, 'amount' => 640, 'description' => 'Geç fiş', 'paid' => true]));

        $body = $this->withServerVariables(['REQUEST_TIME_FLOAT' => microtime(true) - 20])
            ->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => '', 'NumMedia' => 1, 'MediaUrl0' => 'https://api.twilio.com/media/ME1',
                'MediaContentType0' => 'application/pdf'])->assertOk()->getContent();

        $this->assertStringNotContainsString('<Message>', $body, 'Twilio\'ya boş cevap');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'Messages.json') && str_contains((string) ($r['Body'] ?? ''), 'Geç fiş'));
    }
}
