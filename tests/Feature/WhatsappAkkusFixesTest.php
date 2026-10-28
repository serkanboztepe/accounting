<?php

namespace Tests\Feature;

use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ChatAssistant;
use App\Services\Whatsapp\ExpenseExtractor;
use Mockery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Akkuş (15 Ekim) vakalarından çıkan düzeltmeler: K1 yanlış iptal, … AI HTTP sahte. */
class WhatsappAkkusFixesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.api_key' => 'test-key', 'services.anthropic.chat_model' => 'claude-sonnet-5',
            'modules.expenses' => true, 'modules.cari_supplier' => true, 'modules.projects' => false]);
    }

    private function tool(string $name, array $input): array
    {
        return ['content' => [['type' => 'tool_use', 'id' => 'tu_' . $name, 'name' => $name, 'input' => $input]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1]];
    }

    /** K1: açık taslak varken kasa mesajı — AI iptal dese de mesajda iptal sözü yok → iptal yok, mesaj yeni kayıt. */
    public function test_cancel_without_cancel_word_is_not_cancel(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->tool('cancel_pending', []))
            ->push($this->tool('propose_entry', ['kind' => 'sale', 'amount' => 27440, 'description' => 'Ergenekon kasa',
                'paid' => true, 'confidence' => 'high', 'is_new_entry' => true]))]);

        $r = app(ChatAssistant::class)->respond('whatsapp:+905550001111', "Ergenekon kasa\nNakit 5900\nPos 21540",
            ['kind' => 'payment', 'amount' => 20000, 'party_name' => 'Abdullah Günhan']);

        $this->assertSame('entry', $r['type']);
        $this->assertSame(27440.0, $r['data']['amount']);
        Http::assertSentCount(2);
        // Geri gönderilen araç çağrısının boş girdisi {} olmalı ([] → gerçek API 400 verdi).
        $second = Http::recorded()[1][0];
        $this->assertStringContainsString('"input":{}', $second->body());
    }

    /** Oktay 28.10: taslak YOKKEN AI onay seçti, mesaj yeni gider — 👍 ile yutulmaz, AI'a bir tur daha verilir. */
    public function test_confirm_without_draft_and_without_assent_gets_another_turn(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->tool('confirm_pending', []))
            ->push($this->tool('propose_entry', ['kind' => 'expense', 'amount' => 30000, 'description' => 'Hayvan gübresi 15 römork',
                'project_name' => 'Tarla 123/50', 'confidence' => 'high', 'is_new_entry' => true]))]);

        $r = app(ChatAssistant::class)->respond('whatsapp:+905550001113', '123/50 tarla ya 15 römork gubre doktum romork basi 2000 tl', null);

        $this->assertSame('entry', $r['type']);
        $this->assertSame(30000.0, $r['data']['amount']);
        Http::assertSentCount(2);
        $this->assertStringContainsString('onay bekleyen taslak YOK', Http::recorded()[1][0]->body());
    }

    public function test_confirm_without_draft_twice_falls_back_to_ask_confirm(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->tool('confirm_pending', []))]);

        $this->assertSame('ask_confirm', app(ChatAssistant::class)->respond('whatsapp:+905550001114', 'gübre döktüm tarlaya', null)['type']);
        Http::assertSentCount(2);
    }

    public function test_confirm_with_assent_or_with_draft_is_unchanged(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->tool('confirm_pending', []))]);

        $this->assertSame('confirm', app(ChatAssistant::class)->respond('whatsapp:+905550001115', 'tamam', null)['type'], 'kısa onay: eskisi gibi');
        $this->assertSame('ask_confirm', app(ChatAssistant::class)->respond('whatsapp:+905550001116', 'şunu bir daha bak',
            ['kind' => 'expense', 'amount' => 5])['type'], 'taslak varken: eskisi gibi');
        Http::assertSentCount(2);
    }

    public function test_cancel_with_cancel_word_still_cancels(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->tool('cancel_pending', []))]);

        foreach (['İptal', 'vazgeçtim', 'gerek yok onu', 'boşver'] as $t) {
            $this->assertSame('cancel', app(ChatAssistant::class)->respond('whatsapp:+905550001112', $t, ['kind' => 'expense', 'amount' => 5])['type'], $t);
        }
    }

    private const PHONE = 'whatsapp:+905558880003';

    private function webhook(string $body): string
    {
        config(['services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 888 00 03'],
            'modules.smart_assistant' => false, 'services.anthropic.chat_assistant' => false]);

        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    private function question(string $kind): array
    {
        return ['kind' => $kind, 'items' => [], 'amount' => 0.0, 'date' => now()->format('Y-m-d'), 'description' => '',
            'party_id' => null, 'party_name' => null, 'project_id' => null, 'project_name' => null, 'category_id' => null,
            'category_name' => null, 'paid' => null, 'confidence' => 'high', 'question' => null, 'is_new_entry' => true,
            'totals_side' => 'both', 'date_from' => null, 'date_to' => null];
    }

    /** K7: onay bekleyen kasa varken soru → cevap + "onay bekleyen … kaydedeyim mi?"; taslak açık kalır. */
    public function test_answer_mentions_open_draft(): void
    {
        Http::fake();
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        $draft = WhatsappPendingExpense::create(['phone' => self::PHONE, 'status' => 'awaiting_confirmation', 'summary' => 's',
            'extracted' => ['kind' => 'sale', 'amount' => 51517, 'description' => 'Araştırma kasa', 'party_name' => 'Kasa Test', 'paid' => true]]);
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturn($this->question(ExpenseExtractor::KIND_TOTALS_QUERY));
        $this->app->instance(ExpenseExtractor::class, $mock);

        $reply = $this->webhook('Toplam alacağım ne kadar');

        $this->assertStringContainsString('Toplam alacağın', $reply);
        $this->assertStringContainsString('⏳ Onay bekleyen: *Satış — Kasa Test 51.517,00 ₺, Araştırma kasa*', $reply);
        $this->assertSame('awaiting_confirmation', $draft->fresh()->status);
    }

    public function test_answer_without_open_draft_has_no_note(): void
    {
        Http::fake();
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturn($this->question(ExpenseExtractor::KIND_TOTALS_QUERY));
        $this->app->instance(ExpenseExtractor::class, $mock);

        $this->assertStringNotContainsString('Onay bekleyen', $this->webhook('Toplam alacağım ne kadar'));
    }

    /** K8: AI "tam anlayamadım" sorusu bıraktıysa hatırlatma o soruyu sorar; tek "evet" kaydetmez. */
    public function test_followup_of_doubtful_draft_asks_question_and_evet_does_not_save(): void
    {
        Http::fake();
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        $draft = WhatsappPendingExpense::create(['phone' => self::PHONE, 'status' => 'awaiting_confirmation', 'summary' => 's',
            'extracted' => ['kind' => 'expense', 'amount' => 25113, 'description' => 'Ergenekon gider', 'paid' => true,
                'question' => '5000, 19483 ve 1630 ayrı mı yoksa toplam mı?']]);
        \App\Services\Whatsapp\DraftFollowUp::markSent($draft);

        $text = \App\Services\Whatsapp\DraftFollowUp::messageText($draft->fresh());
        $this->assertStringContainsString('ayrı mı yoksa toplam mı?', $text);
        $this->assertStringNotContainsString('Onay vermediğin', $text);

        $before = \App\Models\Expense::count();
        $this->assertStringContainsString('ayrı mı yoksa toplam mı?', $this->webhook('Evet'));
        $this->assertSame($before, \App\Models\Expense::count());
        $this->assertSame('awaiting_confirmation', $draft->fresh()->status);
    }

    private function expense(array $o): array
    {
        return array_merge($this->question(ExpenseExtractor::KIND_EXPENSE), ['amount' => 105000, 'description' => 'Çek ödemesi', 'paid' => true], $o);
    }

    /** K2: carisiz "çek ödendi" → kime sorulur; "gider" → gider olarak kaydolur. */
    public function test_check_paid_without_party_asks_party_and_gider_keeps_expense(): void
    {
        Http::fake();
        config(['modules.checks' => false]);
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturn($this->expense([]));
        $this->app->instance(ExpenseExtractor::class, $mock);

        $this->assertStringContainsString('Bu çek kime yazılmıştı?', $this->webhook('105.000 çek ödendi'));
        $this->assertStringContainsString('📝 *Gider*:', $this->webhook('gider'));
        $before = \App\Models\Expense::count();
        $this->webhook('evet');
        $this->assertSame($before + 1, \App\Models\Expense::count());
    }

    /** 17.10: kişisiz "çek ödendi" bekleyen verilmiş çekle eşleşir → çek "ödendi" işaretlenir; gider/ödeme yazılmaz (modül açık da olsa). */
    public function test_check_paid_matches_issued_check_and_marks_it_without_expense(): void
    {
        Http::fake();
        config(['modules.checks' => true]);
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        $osman = \App\Models\Party::create(['name' => 'Çek Eşleşme Osman']);
        $check = \App\Models\Check::create(['party_id' => $osman->id, 'amount' => 335017, 'due_date' => now()->subDays(2)->toDateString(),
            'status' => 'issued', 'check_number' => '8037567']);
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturn($this->expense(['amount' => 335017]));
        $this->app->instance(ExpenseExtractor::class, $mock);
        $expenses = \App\Models\Expense::count();
        $ledger = \App\Models\PartyLedgerEntry::count();

        $ask = $this->webhook('335.017 çek ödendi');
        $this->assertStringContainsString('Çek Eşleşme Osman', $ask);
        $this->assertStringContainsString('ödendi* olarak işaretlensin mi', $ask);
        $this->assertStringContainsString('işaretlendi', $this->webhook('evet'));

        $this->assertSame('paid', $check->fresh()->status);
        $this->assertSame($expenses, \App\Models\Expense::count(), 'gider yazılmaz');
        $this->assertSame($ledger, \App\Models\PartyLedgerEntry::count(), 'yeni ödeme yazılmaz');
    }

    public function test_check_paid_other_check_falls_back_to_party_question(): void
    {
        Http::fake();
        config(['modules.checks' => true]);
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        $osman = \App\Models\Party::create(['name' => 'Çek Eşleşme Osman']);
        \App\Models\Check::create(['party_id' => $osman->id, 'amount' => 335017, 'due_date' => now()->toDateString(), 'status' => 'issued']);
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturn($this->expense(['amount' => 335017]));
        $this->app->instance(ExpenseExtractor::class, $mock);

        $this->webhook('335.017 çek ödendi');
        $this->assertStringContainsString('Bu çek kime yazılmıştı?', $this->webhook('başka'));
    }

    public function test_check_paid_no_match_asks_party_even_with_module_on(): void
    {
        Http::fake();
        config(['modules.checks' => true]);
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturn($this->expense(['amount' => 98765.43]));
        $this->app->instance(ExpenseExtractor::class, $mock);

        $this->assertStringContainsString('Bu çek kime yazılmıştı?', $this->webhook('98.765,43 çek ödendi'), 'modül açık diye gider yazılmaz');
    }

    public function test_contract_linked_check_marks_contract_payment_paid(): void
    {
        $osman = \App\Models\Party::create(['name' => 'Çek Sözleşme Osman']);
        $project = \App\Models\Project::firstOrCreate(['name' => 'Çek Sözleşme Projesi'], ['status' => 'active']);
        $contract = \App\Models\Contract::create(['project_id' => $project->id, 'party_id' => $osman->id, 'title' => 'Asansör sözleşmesi',
            'contract_type' => 'supply', 'direction' => \App\Models\Contract::DIRECTION_PURCHASE, 'status' => 'active', 'total_amount' => 335017]);
        $payment = \App\Models\ContractPayment::create(['contract_id' => $contract->id, 'amount' => 335017, 'payment_date' => now()->subDays(40)->toDateString(),
            'due_date' => now()->subDay()->toDateString(), 'payment_type' => 'check', 'status' => 'unpaid']);
        $check = $payment->checks()->first() ?? \App\Models\Check::create(['party_id' => $osman->id, 'contract_payment_id' => $payment->id,
            'amount' => 335017, 'due_date' => now()->subDay()->toDateString(), 'status' => 'issued']);
        $this->assertSame('issued', $check->fresh()->status);

        Http::fake();
        config(['modules.checks' => true]);
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturn($this->expense(['amount' => 335017]));
        $this->app->instance(ExpenseExtractor::class, $mock);
        $this->webhook('335.017 çek ödendi');
        $this->webhook('evet');

        $this->assertSame('paid', $payment->fresh()->status, 'sözleşme ödemesi de ödendi — çekle senkron kalır');
        $this->assertSame('paid', $check->fresh()->status);
    }

    public function test_check_paid_with_name_becomes_payment_to_party(): void
    {
        Http::fake();
        config(['modules.checks' => false]);
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        $party = \App\Models\Party::create(['name' => 'Çek Tedarikçi']);
        \App\Models\PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => now()->subDays(20), 'type' => 'alis', 'amount' => 105000, 'description' => 'Un']);
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturn($this->expense([]),
            array_merge($this->expense([]), ['kind' => ExpenseExtractor::KIND_PAYMENT, 'party_id' => $party->id, 'is_new_entry' => false]));
        $this->app->instance(ExpenseExtractor::class, $mock);

        $this->webhook('105.000 çek ödendi');
        $this->assertStringContainsString('Çek Tedarikçi: 105.000,00 ₺ ödedin', $this->webhook('Çek Tedarikçi'));
        $this->webhook('evet');
        $this->assertSame(0.0, \App\Support\PartyStatement::build($party)['balance']);
    }

    public function test_non_check_expense_untouched(): void
    {
        Http::fake();
        config(['modules.checks' => false]);
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturn($this->expense(['amount' => 54000, 'description' => 'Kira ödemesi']));
        $this->app->instance(ExpenseExtractor::class, $mock);

        $this->assertStringContainsString('📝 *Gider*:', $this->webhook('54.000 kira'));
    }

    /** K4: "nakit … kart … X gider" → peşin satış + ayrı gider, tek özet, tek evet. AI gider dese de. */
    public function test_cash_card_and_expense_in_one_message_become_two_records(): void
    {
        Http::fake();
        config(['modules' => \App\Support\ModuleProfiles::resolve('trade'), 'app.profile' => 'trade']);
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturn($this->expense(['amount' => 25113, 'description' => 'Ergenekon gider', 'question' => 'Ayrı mı toplam mı?']));
        $this->app->instance(ExpenseExtractor::class, $mock);
        $sales = \App\Models\Sale::count();
        $expenses = \App\Models\Expense::count();

        $reply = $this->webhook('Akkuş ergenekon 5000 tl nakit 19483 tl kart 1630 tl gider');
        $this->assertStringContainsString('Peşin satış', $reply);
        $this->assertStringContainsString('24.483,00', $reply);
        $this->assertStringContainsString('+ Gider:* 1.630,00', $reply);

        $done = $this->webhook('evet');
        $this->assertStringContainsString('Gider kaydedildi: 1.630,00', $done);
        $this->assertSame($sales + 1, \App\Models\Sale::count());
        $this->assertSame($expenses + 1, \App\Models\Expense::count());
        $this->assertEquals(1630, (float) \App\Models\Expense::latest('id')->value('amount'));
    }

    private function chatUndo(array $find, ?array $replacement = null): void
    {
        $mock = Mockery::mock(ChatAssistant::class);
        $mock->shouldReceive('respond')->andReturn(['type' => 'undo', 'replacement' => $replacement, 'find' => $find]);
        $this->app->instance(ChatAssistant::class, $mock);
    }

    /** K3: "kategorisiz olan 25.113 yanlış" → eski gider tutar+tarihle bulunur, gösterilir, evet → silinir. */
    public function test_older_record_found_by_amount_and_date_and_deleted(): void
    {
        Http::fake();
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        $wrong = \App\Models\Expense::create(['expense_date' => now()->subDay()->toDateString(), 'amount' => 25113, 'payment_status' => 'paid', 'description' => 'Akkuş Ergenekon gider']);
        \App\Models\Expense::create(['expense_date' => now()->toDateString(), 'amount' => 500, 'payment_status' => 'paid', 'description' => 'Son kayıt']);
        config(['modules.smart_assistant' => true]);
        $this->chatUndo(['amount' => 25113, 'date' => now()->subDay()->toDateString()]);

        $reply = $this->webhookChat('Gider de kategorisiz olan yanlış yazılmış 25.113');
        $this->assertStringContainsString('Şu kaydı sileceğim', $reply);
        $this->assertStringContainsString('25.113,00', $reply);
        $this->assertStringContainsString('Sileyim mi?', $reply);

        $this->webhookChat('evet');
        $this->assertNull(\App\Models\Expense::find($wrong->id));
        $this->assertSame(1, \App\Models\Expense::where('description', 'Son kayıt')->count());
    }

    public function test_older_record_not_found_says_so(): void
    {
        Http::fake();
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        config(['modules.smart_assistant' => true]);
        $this->chatUndo(['amount' => 98765.43]);

        $this->assertStringContainsString('bulamadım', $this->webhookChat('98.765,43 olanı sil'));
    }

    private function webhookChat(string $body): string
    {
        config(['services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 888 00 03'], 'modules.smart_assistant' => true]);

        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }
}
