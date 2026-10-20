<?php

namespace Tests\Feature;

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
 * Sohbet asistanı (firma bazında "Akıllı WhatsApp asistanı" modülü): varsayılan kapalı; açıkken
 * taslak/onay yine kontrolcüde, AI onayı mesajda onay kelimesi yoksa kayda dönüşmez. AI mock'lanır.
 */
class WhatsappChatAssistantTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905557771234';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.twilio.verify_signature' => false,
            'services.twilio.allowed_phones' => ['0555 777 12 34'],
            'services.anthropic.chat_assistant' => false,
            'modules.expenses' => true,
            'modules.cari_supplier' => true,
            'modules.projects' => false,
            'modules.smart_assistant' => true,
        ]);
        Http::fake();
        WhatsappMessage::create(['phone' => '905557771234', 'direction' => 'in', 'body' => 'önceki']);
    }

    private function sale(float $amount, string $party, ?string $next = null): array
    {
        return [
            'kind' => ExpenseExtractor::KIND_SALE, 'items' => [], 'totals_side' => null, 'date_from' => null, 'date_to' => null,
            'payment_type' => null, 'amount' => $amount, 'date' => now()->format('Y-m-d'), 'due_date' => null,
            'description' => 'İş', 'project_id' => null, 'project_name' => null, 'party_id' => null, 'party_name' => $party,
            'category_id' => null, 'category_name' => null, 'paid' => null, 'confidence' => 'high', 'question' => null,
            'is_new_entry' => true, 'debt_side' => null, 'reply' => null, 'payment_purpose' => null, 'settles_expense_id' => null,
            'next_items' => $next,
        ];
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

    public function test_module_is_off_by_default_in_every_profile(): void
    {
        foreach ([null, 'mimar', 'muteahhit', 'trade', 'alacak_verecek'] as $profile) {
            $this->assertFalse(ModuleProfiles::resolve($profile)['smart_assistant'], (string) $profile);
        }
        $this->assertTrue(ModuleProfiles::resolve('mimar', ['smart_assistant' => true])['smart_assistant']);
    }

    public function test_module_off_uses_old_extractor_and_never_calls_chat(): void
    {
        config(['modules.smart_assistant' => false]);
        $chat = Mockery::mock(ChatAssistant::class);
        $chat->shouldNotReceive('respond');
        $this->app->instance(ChatAssistant::class, $chat);
        $extractor = Mockery::mock(ExpenseExtractor::class);
        $extractor->shouldReceive('extract')->once()->andReturn($this->sale(30000, 'Eski Yol Ali'));
        $this->app->instance(ExpenseExtractor::class, $extractor);

        $reply = $this->send("Ali'ye 30 bin iş yaptım");

        $this->assertStringContainsString('Onaylamak için *evet*', $reply);
    }

    public function test_chat_entry_shows_summary_and_plain_evet_saves(): void
    {
        $this->chat(['type' => 'entry', 'data' => $this->sale(30000, 'Sohbet Ali'), 'text' => '']);

        $reply = $this->send("Ali'ye 30 bin iş yaptım");
        $this->assertStringContainsString('Kaydedeyim mi?', $reply);
        $this->assertSame(0, PartyLedgerEntry::whereHas('party', fn ($q) => $q->where('name', 'Sohbet Ali'))->count());

        $this->assertStringContainsString('Kaydedildi', $this->send('Evet'));
        $this->assertSame(1, PartyLedgerEntry::whereHas('party', fn ($q) => $q->where('name', 'Sohbet Ali'))->count());
    }

    public function test_ai_confirm_without_assent_word_does_not_save(): void
    {
        // Gerçekte ChatAssistant "Sılajcı yusuf" için confirm yerine ask_confirm döner (hasAssent).
        $this->chat(
            ['type' => 'entry', 'data' => $this->sale(67000, 'Onaysız Yusuf'), 'text' => ''],
            ['type' => 'ask_confirm'],
        );
        $this->send('Yusuf 67000');

        $reply = $this->send('Sılajcı yusuf');

        $this->assertStringContainsString('Kaydedeyim mi?', $reply);
        $this->assertSame(0, PartyLedgerEntry::whereHas('party', fn ($q) => $q->where('name', 'Onaysız Yusuf'))->count());
    }

    /** Erkan, Doktor (13 Ekim): tutar sorulunca taslak açılır; "Tamam" → neyin eksik olduğu söylenir, kayıt kaybolmaz. */
    public function test_missing_amount_question_keeps_draft_and_tamam_says_what_is_missing(): void
    {
        $draft = ['amount' => 0, 'description' => '10 kg peynir, 2 kavurma, mumbar',
            'question' => 'Toplam ne kadar tuttu?'] + $this->sale(0, 'Doktor Test');
        $this->chat(
            ['type' => 'entry', 'data' => $draft, 'text' => ''],
            ['type' => 'entry', 'data' => ['amount' => 8500, 'question' => null, 'is_new_entry' => false] + $draft, 'text' => ''],
        );

        $this->assertStringContainsString('Toplam ne kadar tuttu?', $this->send('Doktora 10 kilo peynir 2 kavurma mumbar verdim veresiye'));
        $this->assertStringContainsString('Satış — Doktor Test, 10 kg peynir, 2 kavurma, mumbar: tutar olmadan kaydedemiyorum', $this->send('Tamam'));
        $this->assertSame(0, PartyLedgerEntry::whereHas('party', fn ($q) => $q->where('name', 'Doktor Test'))->count());

        $this->assertStringContainsString('Kaydedeyim mi?', $this->send('8500 lira'));
        $this->send('evet');
        $this->assertSame(1, PartyLedgerEntry::whereHas('party', fn ($q) => $q->where('name', 'Doktor Test'))->count());
    }

    /** Oktay 20.10: kart "doğru mu?" (olağandışı tutar) diye sordu, iki kez "Dogru" yazdı — kart yeniden soruldu, kayıt olmadı. */
    public function test_dogru_answers_card_warning_and_saves(): void
    {
        $party = \App\Models\Party::create(['name' => 'Doğru Testi Ali']);
        foreach ([1000, 1200, 900] as $i => $amt) {
            PartyLedgerEntry::create(['party_id' => $party->id, 'type' => PartyLedgerEntry::TYPE_SALE, 'amount' => $amt,
                'entry_date' => now()->subDays($i + 10)->toDateString(), 'description' => 'Eski satış']);
        }
        $this->chat(
            ['type' => 'entry', 'data' => ['party_id' => $party->id, 'party_name' => null] + $this->sale(100000, 'Doğru Testi Ali'), 'text' => ''],
            ['type' => 'ask_confirm'],
        );

        $this->assertStringContainsString('doğru mu?', $this->send('Ali 100000 satış'));
        $this->send('Dogru');

        $this->assertSame(4, PartyLedgerEntry::where('party_id', $party->id)->count());
    }

    /** Uyarı yokken "doğru" tek başına onay sayılmaz — eski davranış ("Kaydedeyim mi?"). */
    public function test_dogru_without_card_warning_still_asks(): void
    {
        $this->chat(
            ['type' => 'entry', 'data' => $this->sale(5000, 'Uyarısız Veli'), 'text' => ''],
            ['type' => 'ask_confirm'],
        );
        $this->send('Veli 5000');

        $this->assertStringContainsString('Kaydedeyim mi?', $this->send('doğru'));
        $this->assertSame(0, $this->ledgerCount('Uyarısız Veli'));
    }

    /** Kullanıcı kuralı 20.10: yalnız ad → cari varsa bakiye, yoksa "ne yapmamı istersin?". */
    public function test_name_only_shows_balance_or_asks_when_no_party(): void
    {
        $party = \App\Models\Party::create(['name' => 'Ad Testi Özkan']);
        $query = fn (string $name, ?int $id) => ['type' => 'entry', 'data' => ['kind' => ExpenseExtractor::KIND_BALANCE_QUERY, 'amount' => 0,
            'party_id' => $id, 'party_name' => $id ? null : $name, 'is_new_entry' => true] + $this->sale(0, $name), 'text' => ''];
        $this->chat($query('Ad Testi Özkan', $party->id), $query('Ad Testi Yok', null), $query('AD TESTİ ÖZKAN', null));

        $this->assertStringContainsString('📊 Ad Testi Özkan', $this->send('Ad testi özkan cari'));
        $this->assertStringContainsString('"Ad Testi Yok" adında bir cari bulamadım. Ne yapmamı istersin?', $this->send('Ad testi yok cari'));
        // AI kimliği vermeyip yalnız adı yazdı (Yakup taş durum) → adı birebir tutan cari bulunur
        $this->assertStringContainsString('📊 Ad Testi Özkan', $this->send('ad testi özkan durum'));
    }

    public function test_affirms_check_words(): void
    {
        foreach (['Dogru', 'doğru', 'Evet doğru', 'DOĞRU.', 'aynen'] as $yes) {
            $this->assertTrue(ChatAssistant::affirmsCheck($yes), $yes);
        }
        foreach (['doğru değil', 'yanlış', 'hayır doğru değil', 'doğru ama tutar 10 bin olacak aslında', '10000'] as $no) {
            $this->assertFalse(ChatAssistant::affirmsCheck($no), $no);
        }
    }

    public function test_assent_words_and_typos(): void
    {
        foreach (['Evet', 'Evert', 'evt', 'Tmam', 'tmm', 'ok', 'Evet gider yaz', 'Ödendi evet', 'olur'] as $yes) {
            $this->assertTrue(ChatAssistant::hasAssent($yes), $yes);
        }
        foreach (['Sılajcı yusuf', 'Kg 600', 'hayır', 'iptal', 'ödendi'] as $no) {
            $this->assertFalse(ChatAssistant::hasAssent($no), $no);
        }
    }

    private function ledgerCount(string $party): int
    {
        return PartyLedgerEntry::whereHas('party', fn ($q) => $q->where('name', $party))->count();
    }

    public function test_undo_last_shows_record_and_deletes_after_evet(): void
    {
        $this->chat(
            ['type' => 'entry', 'data' => $this->sale(10000, 'Geri Al Yakup'), 'text' => ''],
            ['type' => 'undo'],
        );
        $this->send('Yakup 10000 EKB');
        $this->send('Evet');
        $this->assertSame(1, $this->ledgerCount('Geri Al Yakup'));

        $reply = $this->send('son kaydı sil');
        $this->assertStringContainsString('Şu kaydı sileceğim', $reply);
        $this->assertStringContainsString('Geri Al Yakup', $reply);
        $this->assertSame(1, $this->ledgerCount('Geri Al Yakup'), 'onaysız silinmemeli');

        $this->assertStringContainsString('Silindi', $this->send('Evet'));
        $this->assertSame(0, $this->ledgerCount('Geri Al Yakup'));
    }

    public function test_undo_of_debt_payment_reopens_debt(): void
    {
        $bill = \App\Models\Expense::create(['amount' => 3500, 'description' => 'Geri Al Elektrik', 'expense_date' => now(), 'payment_status' => 'unpaid']);
        $pay = ['kind' => ExpenseExtractor::KIND_EXPENSE, 'amount' => 2000, 'settles_expense_id' => $bill->id, 'paid' => true, 'description' => 'Elektrik'] + $this->sale(2000, 'x');
        $pay['party_name'] = null;
        $this->chat(['type' => 'entry', 'data' => $pay, 'text' => ''], ['type' => 'undo']);

        $this->send('elektriğin 2000 ini ödedim');
        $this->send('evet');
        $this->assertSame(['2000.00', 'paid'], [$bill->fresh()->amount, $bill->fresh()->payment_status]);
        $this->assertTrue(\App\Models\Expense::where('description', 'like', 'Geri Al Elektrik (kalan)')->exists());

        $this->assertStringContainsString('Şu ödemeyi geri alacağım', $this->send('son kaydı sil'));
        $this->assertStringContainsString('Ödeme geri alındı', $this->send('evet'));
        $this->assertSame(['3500.00', 'unpaid'], [$bill->fresh()->amount, $bill->fresh()->payment_status]);
        $this->assertFalse(\App\Models\Expense::where('description', 'like', 'Geri Al Elektrik (kalan)')->exists());
    }

    public function test_correction_replaces_last_record_in_one_confirm(): void
    {
        $this->chat(
            ['type' => 'entry', 'data' => $this->sale(8000, 'Düzelt Erol'), 'text' => ''],
            ['type' => 'undo', 'replacement' => $this->sale(10000, 'Düzelt Erol')],
        );
        $this->send("Erol'a 8000 EKB yaptım");
        $this->send('Evet');

        $reply = $this->send('8000 değil 10000 olacaktı');
        $this->assertStringContainsString('Yerine yazacağım', $reply);
        $this->assertStringContainsString('10.000,00', $reply);

        $this->assertStringContainsString('Düzeltildi', $this->send('Evet'));
        $amounts = PartyLedgerEntry::whereHas('party', fn ($q) => $q->where('name', 'Düzelt Erol'))->pluck('amount')->map(fn ($a) => (float) $a)->all();
        $this->assertSame([10000.0], $amounts, 'eski 8000 silinmeli, yalnız 10000 kalmalı');
    }

    public function test_undo_cancelled_keeps_record(): void
    {
        $this->chat(
            ['type' => 'entry', 'data' => $this->sale(10000, 'Kalsın Yakup'), 'text' => ''],
            ['type' => 'undo'],
        );
        $this->send('Yakup 10000 EKB');
        $this->send('Evet');
        $this->send('son kaydı sil');

        $this->send('iptal');

        $this->assertSame(1, $this->ledgerCount('Kalsın Yakup'));
    }

    public function test_undo_without_recent_record_explains(): void
    {
        $this->chat(['type' => 'undo']);

        $this->assertStringContainsString('geri alabileceğim bir kayıt bulamadım', $this->send('son kaydı sil'));
    }

    public function test_large_amount_is_shown_in_words(): void
    {
        $this->chat(['type' => 'entry', 'data' => $this->sale(17017000, 'Tosun Satan'), 'text' => '']);

        $this->assertStringContainsString('on yedi milyon on yedi bin lira', $this->send('96 tosun 17.017000 tl'));
    }

    public function test_money_words(): void
    {
        $this->assertSame('on yedi milyon on yedi bin', \App\Support\Money::words(17017000));
        $this->assertSame('bin beş yüz', \App\Support\Money::words(1500));
        $this->assertSame('yüz bir bin', \App\Support\Money::words(101000));
    }

    public function test_next_item_is_proposed_after_confirm(): void
    {
        $this->chat(
            ['type' => 'entry', 'data' => $this->sale(5400, 'Kalem Bir', '10.000 TL ustalık'), 'text' => ''],
            ['type' => 'entry', 'data' => $this->sale(10000, 'Kalem Bir'), 'text' => ''],
        );
        $this->send('5400 parça 10.000 ustalık');

        $reply = $this->send('Evet');

        $this->assertStringContainsString('Kaydedildi', $reply);
        $this->assertStringContainsString('Sıradaki', $reply);
        $this->assertStringContainsString('10.000,00', $reply);
    }

    /** Erkan, Recep takası (13 Ekim): ikinci kalem özette görünür, düzeltmede kaybolmaz, onaydan sonra sorulur. */
    public function test_next_item_that_cannot_be_proposed_is_not_silently_lost(): void
    {
        // Oktay 19.10: "Ekle" → düzenli gider kuruldu, 18 Ekim kalemi boş cevapla kayboldu ("…durdur* yaz.\n\n" ve hiçbir şey).
        $this->chat(
            ['type' => 'entry', 'data' => $this->sale(5000, 'Sıra Testi Ayşe', '18 Ekim çoban ekmeği 34,60 gider'), 'text' => ''],
            ['type' => 'confirm', 'paid' => null],
            ['type' => 'review_ok', 'verdict' => 'correct', 'text' => ''],
        );

        $this->send('Ayşe 5000 iş yaptım ve 18 Ekim ekmeği de ekle');
        $out = $this->send('evet');

        $this->assertStringContainsString('Kaydedildi', $out);
        $this->assertStringContainsString('diğer kalemi', $out);
        $this->assertStringContainsString('18 Ekim çoban ekmeği', $out);
    }

    public function test_swap_second_item_shown_kept_on_correction_and_proposed(): void
    {
        $purchase = ['kind' => ExpenseExtractor::KIND_PURCHASE, 'description' => 'Yemleme aleti', 'paid' => false]
            + $this->sale(12000, 'Takas Recep', '5 kg peynir verdim × 600 = 3.000');
        $this->chat(
            ['type' => 'entry', 'data' => $purchase, 'text' => ''],
            ['type' => 'entry', 'data' => ['next_items' => null, 'is_new_entry' => false, 'description' => 'Yemleme makinesi'] + $purchase, 'text' => ''],
            ['type' => 'entry', 'data' => ['description' => '5 kg peynir'] + $this->sale(3000, 'Takas Recep'), 'text' => ''],
        );

        $this->assertStringContainsString('➡️ Sırada: 5 kg peynir', $this->send('Recepten yemleme aleti aldım 12 bin, 5 kilo peynir verdim 600den'));
        $this->assertStringContainsString('➡️ Sırada: 5 kg peynir', $this->send('adı yemleme makinesi'));

        $reply = $this->send('evet');
        $this->assertStringContainsString('Sıradaki', $reply);
        $this->assertStringContainsString('3.000,00', $reply);
        $this->send('evet');

        $party = \App\Models\Party::where('name', 'Takas Recep')->sole();
        $this->assertSame(-9000.0, \App\Support\PartyStatement::build($party)['balance']); // 12.000 borç − 3.000 peynir
    }

    /** Kod emniyeti: AI takasın ikinci kalemini unutsa da "… peynir verdim" sıraya eklenir; para sözleri takas sayılmaz. */
    public function test_swap_guard_adds_missed_second_item_but_not_money_words(): void
    {
        $purchase = ['kind' => ExpenseExtractor::KIND_PURCHASE, 'paid' => false, 'next_items' => null];
        $recep = "Demirci recep'e bir tane otomatik kuzu yemleme kurunu aldım 12.000 lira 5 kilo da peynir verdim parasını almadım hesaptan düşülecek";
        $d = ExpenseExtractor::guardSwap($purchase, $recep);
        $this->assertStringContainsString('5 kilo da peynir verdim', (string) $d['next_items']);
        $this->assertSame('… 5 kilo da peynir verdim', '… ' . ExpenseExtractor::nextItemsLabel(substr($d['next_items'], strpos($d['next_items'], '5 kilo'))));

        foreach (["Ahmet'ten çimento aldım parasını verdim", "Ali'den yem aldım 5 bin nakit verdim", "Mehmetten un aldım"] as $t) {
            $this->assertNull(ExpenseExtractor::guardSwap($purchase, $t)['next_items'], $t);
        }
        $sale = ['kind' => ExpenseExtractor::KIND_SALE, 'paid' => true, 'next_items' => null];
        $this->assertNull(ExpenseExtractor::guardSwap($sale, "Ali'ye bal verdim parasını aldım")['next_items']);
        $this->assertNotNull(ExpenseExtractor::guardSwap($sale, "Veli'ye 10 kilo bal verdim, ondan 2 çuval yem aldım")['next_items']);

        $this->chat(['type' => 'entry', 'data' => $purchase + $this->sale(12000, 'Emniyet Recep'), 'text' => '']);
        $reply = $this->send($recep);
        $this->assertStringContainsString('➡️ Sırada:', $reply);
        $this->assertStringContainsString('peynir verdim (bunu onaylayınca sorarım)', $reply);
    }
}
