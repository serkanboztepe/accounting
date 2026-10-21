<?php

namespace Tests\Feature;

use App\Models\AssistantMemory;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\ModuleProfiles;
use App\Support\PartyStatement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/** Önce anla, sonra kaydet (16.10): AI "bilmiyorum" derse kayıt yok soru var; anladığı kayda çevrilir; anlam cümlesi; çek vadesi. AI mock. */
class WhatsappUnderstandingTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905558880022';

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('alacak_verecek'), 'app.profile' => 'alacak_verecek',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 888 00 22'],
            'services.anthropic.chat_assistant' => false]);
        Http::fake();
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
    }

    private function d(array $o): array
    {
        return array_merge(['items' => [], 'amount' => 0, 'paid' => null, 'date' => now()->format('Y-m-d'), 'description' => '',
            'party_id' => null, 'party_name' => null, 'project_id' => null, 'project_name' => null, 'confidence' => 'high',
            'question' => null, 'is_new_entry' => true], $o);
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

    /** Süzgeç cümlesi önceki paradan düşmeyi söyler — "parası ödendi" deyip "yeni ödeme mi?" sorusuna yol açmaz (firma 7, 20.10). */
    public function test_record_sentence_tells_offset_from_earlier_money(): void
    {
        $base = ['party_name' => 'Asansörcü', 'amount' => 500000, 'paid' => true, 'advance_used' => 500000];
        $purchase = \App\Services\Whatsapp\Understanding::recordSentence(['kind' => ExpenseExtractor::KIND_PURCHASE] + $base);
        $partial = \App\Services\Whatsapp\Understanding::recordSentence(['kind' => ExpenseExtractor::KIND_PURCHASE, 'advance_used' => 300000, 'paid' => false] + $base);
        $sale = \App\Services\Whatsapp\Understanding::recordSentence(['kind' => ExpenseExtractor::KIND_SALE] + $base);

        $this->assertStringContainsString("500.000,00 ₺'si önceden ona verilen paradan düşüldü, yeni para çıkmadı", $purchase);
        $this->assertStringNotContainsString('parası ödendi', $purchase);
        $this->assertStringContainsString('Kalan 200.000,00 ₺ borç kaldı.', $partial);
        $this->assertStringContainsString('önceden ondan alınan paradan düşüldü, yeni para alınmadı', $sale);
        $this->assertStringContainsString('parası ödendi', \App\Services\Whatsapp\Understanding::recordSentence(['kind' => ExpenseExtractor::KIND_PURCHASE, 'advance_used' => null] + $base));
    }

    public function test_unknown_work_status_asks_and_yes_does_not_save(): void
    {
        $osman = Party::create(['name' => 'Anlama Testi Osman']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 1005000, 'party_id' => $osman->id,
            'work_status' => 'done', 'work_status_known' => false, 'question' => 'Asansör takıldı mı, yoksa peşin mi ödüyorsunuz?']));
        $before = PartyLedgerEntry::count();

        $reply = $this->send('Osman asansöre 1.005.000 ödendi');
        // İş durumu sorusu numaralı (kullanıcı 20.10): "*1* İş/mal geldi · *2* Peşin (iş/mal sonra) · *3* Eski borcu ödedim".
        $this->assertStringContainsString("Anlama Testi Osman'a 1.005.000,00 ₺ — ne için?", $reply);
        $this->assertStringContainsString('*2* Peşin (iş/mal sonra)', $reply);
        $this->assertStringNotContainsString('Kaydedeyim mi', $reply, 'tahminle onaya hazırlanmaz');

        $this->assertStringContainsString('— ne için?', $this->send('evet'), '"evet" soruyu geçemez');
        $this->assertSame($before, PartyLedgerEntry::count());
    }

    public function test_vague_answer_to_work_question_asks_again(): void
    {
        $osman = Party::create(['name' => 'Anlama Testi Osman']);
        $q = 'Asansör takıldı mı, yoksa peşin mi ödüyorsunuz?';
        $ask = $this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 1005000, 'party_id' => $osman->id,
            'work_status' => 'done', 'work_status_known' => false, 'question' => $q]);
        // AI belirsiz cevabı "biliyorum"a çevirir (tahmin) — ikinci göz "cevap değil" der.
        $guess = ['is_new_entry' => false, 'work_status' => 'not_yet', 'work_status_known' => true, 'question' => null] + $ask;
        $this->ai($ask, $guess);
        config(['services.anthropic.api_key' => 'sk-test']);
        Http::swap(new \Illuminate\Http\Client\Factory); // setUp'taki genel sahte cevap ikinci gözü ezmesin
        Http::fake(['api.twilio.com/*' => Http::response([], 200), 'api.anthropic.com/*' => Http::response(['content' => [['type' => 'tool_use', 'name' => 'judge', 'input' => ['answers' => false]]], 'usage' => []])]);

        $this->send('Osman asansöre 1.005.000 ödendi');
        $reply = $this->send('Asansör bedeli birmilyon beş bin');
        $this->assertStringContainsString('— ne için?', $reply);
        $this->assertStringNotContainsString('Kaydedeyim mi', $reply);
    }

    public function test_clear_answer_to_work_question_proceeds(): void
    {
        $osman = Party::create(['name' => 'Anlama Testi Osman']);
        $ask = $this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 1005000, 'party_id' => $osman->id,
            'work_status' => 'done', 'work_status_known' => false, 'question' => 'Asansör takıldı mı, yoksa peşin mi ödüyorsunuz?']);
        $this->ai($ask, ['is_new_entry' => false, 'work_status' => 'not_yet', 'work_status_known' => true, 'question' => null] + $ask);
        config(['services.anthropic.api_key' => 'sk-test']);
        Http::swap(new \Illuminate\Http\Client\Factory); // setUp'taki genel sahte cevap ikinci gözü ezmesin
        Http::fake(['api.twilio.com/*' => Http::response([], 200), 'api.anthropic.com/*' => Http::response(['content' => [['type' => 'tool_use', 'name' => 'judge', 'input' => ['answers' => true]]], 'usage' => []])]);

        $this->send('Osman asansöre 1.005.000 ödendi');
        $this->assertStringContainsString('Onaylamak için', $this->send('daha takılmadı'), 'net cevap → onaya hazır');
    }

    public function test_cross_check_stops_record_that_contradicts_user(): void
    {
        // Hazal (13.10): "borcum ödendi" yazdı, kayıt "borçlusunuz (yeni borç)" oldu. Çapraz kontrol durdurur, sorar; "evet" geçemez.
        $hazal = Party::create(['name' => 'Anlama Testi Hazal']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_DEBT_NOTE, 'debt_side' => 'payable', 'amount' => 100000, 'party_id' => $hazal->id,
            'work_status' => 'no_work', 'work_status_known' => true, 'meaning' => 'Hazal\'ın hatır çekinden olan borcunu ödedin, borç kapandı.']));
        config(['services.anthropic.api_key' => 'sk-test']);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.twilio.com/*' => Http::response([], 200), 'api.anthropic.com/*' => Http::response(['content' => [['type' => 'tool_use',
            'name' => 'check', 'input' => ['same' => false, 'question' => 'Hazal\'a borcunuz kapandı mı, yoksa yeni mi borçlandınız?']]], 'usage' => []])]);
        $before = PartyLedgerEntry::count();

        $reply = $this->send('Hazal doğan verilen hatır çekinden borcum ödendi 100.000');
        $this->assertStringContainsString('borcunuz kapandı mı', $reply);
        $this->assertStringNotContainsString('Kaydedeyim mi', $reply);
        // "evet" kaydı geçiremez; ama aynı soru da tekrarlanmaz (19.10 Yeni Yapı: üç tur döndü) — kart gösterilir, kullanıcı gördüğünü onaylar.
        $card = $this->send('evet');
        $this->assertStringNotContainsString('borcunuz kapandı mı', $card, 'süzgeç sorusu tekrar sorulmaz');
        $this->assertStringContainsString('100.000', $card, 'kayıt kartı gösterilir');
        $this->assertSame($before, PartyLedgerEntry::count(), 'ilk "evet" kaydetmez');
    }

    public function test_cross_check_asks_only_once_per_draft(): void
    {
        // 19.10 Yeni Yapı: cevap sonrası kayıt biraz değişince yeniden süzülüp aynı tür soru tekrar geldi.
        $hazal = Party::create(['name' => 'Anlama Testi Hazal İki']);
        $first = $this->d(['kind' => ExpenseExtractor::KIND_DEBT_NOTE, 'debt_side' => 'payable', 'amount' => 100000, 'party_id' => $hazal->id,
            'work_status' => 'no_work', 'work_status_known' => true, 'meaning' => 'Hazal\'a borcunu ödedin.']);
        $this->ai($first, ['amount' => 90000, 'is_new_entry' => false] + $first);
        config(['services.anthropic.api_key' => 'sk-test']);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.twilio.com/*' => Http::response([], 200), 'api.anthropic.com/*' => Http::response(['content' => [['type' => 'tool_use',
            'name' => 'check', 'input' => ['same' => false, 'question' => 'Hazal\'a borcunuz kapandı mı, yoksa yeni mi borçlandınız?']]], 'usage' => []])]);

        $this->assertStringContainsString('borcunuz kapandı mı', $this->send('Hazal borcum ödendi 100.000'));
        $again = $this->send('aslında 90 bin borçlandım');
        $this->assertStringNotContainsString('borcunuz kapandı mı', $again, 'süzgeç taslak başına bir kez');
        $this->assertStringContainsString('90.000', $again);
    }

    public function test_cross_check_agreeing_record_passes(): void
    {
        $osman = Party::create(['name' => 'Anlama Testi Osman']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 80000, 'party_id' => $osman->id,
            'work_status' => 'done', 'work_status_known' => true, 'meaning' => 'Osman sıvayı bitirdi, 80.000 ₺ ödedin.']));
        config(['services.anthropic.api_key' => 'sk-test']);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.twilio.com/*' => Http::response([], 200), 'api.anthropic.com/*' => Http::response(['content' => [['type' => 'tool_use',
            'name' => 'check', 'input' => ['same' => true]]], 'usage' => []])]);

        $reply = $this->send('Osman sıvayı bitirdi 80 bin verdim');
        $this->assertStringContainsString('🛒 *Alış*', $reply, 'tutarlı kayıt onaya hazır');
        $this->assertStringNotContainsString('❓', $reply);
    }

    public function test_known_not_yet_payment_becomes_advance_with_meaning_line(): void
    {
        $osman = Party::create(['name' => 'Anlama Testi Osman']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 1005000, 'party_id' => $osman->id,
            'work_status' => 'not_yet', 'work_status_known' => true,
            'meaning' => "Osman'a asansör parasını ödedin, asansör henüz takılmadı."]));

        $reply = $this->send('daha takılmadı');
        // Anlam cümlesi kartta gösterilmez (kullanıcı 20.10); kart yön + önceden ödeme olduğunu söyler, anlam taslakta kalır.
        $this->assertStringContainsString('· önceden ödeme (iş/mal sonra)', $reply);
        $this->assertStringNotContainsString('🗣', $reply);
        $this->send('evet');

        $this->assertEqualsWithDelta(1005000, PartyStatement::build($osman)['balance'], 0.01, 'avans: o bize iş borçlu');
    }

    public function test_known_done_payment_becomes_paid_purchase(): void
    {
        $osman = Party::create(['name' => 'Anlama Testi Osman']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 80000, 'party_id' => $osman->id,
            'work_status' => 'done', 'work_status_known' => true, 'description' => 'Sıva']));

        $this->assertStringNotContainsString('Ne için verdin', $this->send('Osman sıvayı bitirdi 80 bin verdim'));
        $this->send('evet');

        $this->assertEqualsWithDelta(0, PartyStatement::build($osman)['balance'], 0.01, 'alış + ödeme');
        $this->assertSame(2, PartyLedgerEntry::where('party_id', $osman->id)->count());
    }

    public function test_agreement_is_not_a_debt(): void
    {
        $usta = Party::create(['name' => 'Anlama Testi Usta']);
        AssistantMemory::query()->update(['status' => 'deleted']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_DEBT_NOTE, 'debt_side' => 'payable', 'amount' => 0, 'party_id' => $usta->id,
            'work_status' => 'agreement_only', 'work_status_known' => true, 'meaning' => 'Usta ile sıva işi için 115.000 ₺ anlaştınız, iş henüz başlamadı.']));

        $this->assertStringContainsString('Hatırlayayım mı', $this->send('daha başlamadı 115 bine anlaştık'));
        $this->send('evet');

        $this->assertSame(0, PartyLedgerEntry::where('party_id', $usta->id)->count(), 'anlaşma borç yazılmaz');
        $this->assertSame(1, AssistantMemory::where('status', 'active')->where('kind', 'agreement')->count());
    }

    public function test_uncertain_party_match_asks(): void
    {
        $sinan = Party::create(['name' => 'Anlama Testi Sinan Sele']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 80000, 'party_id' => $sinan->id, 'party_said' => 'Sinan usta',
            'work_status' => 'done', 'work_status_known' => true, 'party_certain' => false]));

        $reply = $this->send('Sinan usta sıvayı bitirdi 80 bin verdim');
        $this->assertStringContainsString('Sinan Sele', $reply);
        $this->assertStringNotContainsString('Kaydedeyim mi', $reply);
    }

    public function test_titled_first_name_asks_once_then_confirmed(): void
    {
        $sinan = Party::create(['name' => 'Anlamatesti Sele']);
        $guess = $this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 80000, 'party_id' => $sinan->id, 'party_said' => 'Anlamatesti usta',
            'work_status' => 'done', 'work_status_known' => true, 'party_certain' => true]);
        $this->ai($guess, ['is_new_entry' => false] + $guess); // cevap aynı taslağın düzeltmesi

        $first = $this->send('Anlamatesti usta sıvayı bitirdi 80 bin verdim');
        $this->assertStringContainsString('Anlamatesti Sele', $first, 'AI "eminim" dese de unvan + yalnız ad → sor');
        $this->assertStringNotContainsString('Kaydedeyim mi', $first);

        $this->assertStringNotContainsString('başka biri mi', $this->send('evet o'), 'cevaplandı — tekrar sorulmaz');
    }

    public function test_first_name_without_title_does_not_ask(): void
    {
        $recep = Party::create(['name' => 'Anlamatesti Demirci']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 3000, 'party_id' => $recep->id, 'party_said' => 'Anlamatesti',
            'work_status' => 'done', 'work_status_known' => true, 'party_certain' => true]));

        $this->assertStringNotContainsString('başka biri mi', $this->send('Anlamatestiye 3000 verdim'));
    }

    public function test_debt_note_with_meaningless_status_asks(): void
    {
        $usta = Party::create(['name' => 'Anlama Testi Usta']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_DEBT_NOTE, 'debt_side' => 'payable', 'amount' => 115000, 'party_id' => $usta->id,
            'work_status' => 'old_debt', 'work_status_known' => true]));

        $reply = $this->send('ustaya 115.000 borcum var');
        $this->assertStringContainsString('*1* İş bitti, parasını vermedim · *2* Anlaştık, iş başlamadı · *3* Borç para aldım', $reply);
        $this->assertStringNotContainsString('Kaydedeyim mi', $reply);
    }

    public function test_impossible_cheque_due_date_is_warned(): void
    {
        $osman = Party::create(['name' => 'Anlama Testi Osman']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 670000, 'party_id' => $osman->id,
            'work_status' => 'not_yet', 'work_status_known' => true,
            'checks' => [['number' => '8037566', 'due_date_text' => '31-02-2027', 'amount' => 335000],
                ['number' => '8037567', 'due_date_text' => '31-03-2027', 'amount' => 335000]]]));

        $reply = $this->send('çekler');
        $this->assertStringContainsString('8037566: vade 31-02-2027', $reply);
        $this->assertStringNotContainsString('8037567: vade', $reply, 'geçerli tarih uyarılmaz');
        $this->assertStringContainsString('2 çek', WhatsappPendingExpense::where('phone', self::PHONE)->latest('id')->first()->extracted['description']);
    }

    public function test_cheque_payment_stores_issued_cheques_and_reminds(): void
    {
        $osman = Party::create(['name' => 'Anlama Testi Osman']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 670000, 'party_id' => $osman->id,
            'work_status' => 'not_yet', 'work_status_known' => true, 'description' => 'Asansör',
            'checks' => [['number' => '8037566', 'due_date_text' => '31-02-2027', 'amount' => 335000],
                ['number' => '8037567', 'due_date_text' => '31-03-2027', 'amount' => 335000]]]));
        $this->send('çekleri verdim');

        $this->assertStringContainsString('2 çek kaydedildi', $this->send('evet'));
        $checks = \App\Models\Check::where('party_id', $osman->id)->orderBy('check_number')->get();
        $this->assertSame(['issued', 'issued'], $checks->pluck('status')->all());
        $this->assertSame('2027-02-28', $checks[0]->due_date->toDateString(), '31 Şubat → ayın son günü');
        $this->assertStringContainsString('31-02-2027 takvimde yok', $checks[0]->notes);
        $this->assertSame('2027-03-31', $checks[1]->due_date->toDateString());
        $payment = PartyLedgerEntry::find($checks[0]->party_ledger_entry_id);
        $this->assertSame(PartyLedgerEntry::TYPE_PAYMENT, $payment->type);
        $this->assertSame('check', $payment->payment_type);
        $this->assertEqualsWithDelta(670000, PartyStatement::build($osman)['balance'], 0.01, 'avans; çek ödeme anında düşer');

        $pending = \App\Support\CheckReminders::pending()->pluck('id');
        $this->assertTrue($pending->contains($checks[0]->id), 'verdiğimiz, ödemeye bağlı çek hatırlatılır');

        // Müşteriden alınan çek (tahsilata bağlı) hatırlatılmaz.
        $collection = PartyLedgerEntry::create(['party_id' => $osman->id, 'type' => PartyLedgerEntry::TYPE_COLLECTION, 'amount' => 1000,
            'entry_date' => now()->toDateString()]);
        $received = \App\Models\Check::create(['party_id' => $osman->id, 'party_ledger_entry_id' => $collection->id, 'amount' => 1000,
            'due_date' => '2027-01-10', 'status' => 'issued']);
        $this->assertFalse(\App\Support\CheckReminders::pending()->pluck('id')->contains($received->id));
    }

    /** Firma 7, 20.10 23:54: 11 çekin tek tek listesi açıklamayı 255'ten uzun yaptı, "evet" kaydı düşürdü (cevap yok). */
    public function test_many_cheques_keep_description_short_and_save(): void
    {
        $yusuf = Party::create(['name' => 'Anlama Testi Yusuf']);
        $checks = [];
        foreach (['28.02.2027', '31.03.2027', '30.04.2027', '31.05.2027', '30.06.2027', '31.07.2027', '31.08.2027', '30.09.2027',
            '31.10.2027', '30.11.2027'] as $due) {
            $checks[] = ['number' => null, 'due_date_text' => $due, 'amount' => 100000];
        }
        $checks[] = ['number' => null, 'due_date_text' => '31.12.2027', 'amount' => 187000];
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 1187000, 'party_id' => $yusuf->id,
            'work_status' => 'not_yet', 'work_status_known' => true, 'description' => 'Çek ödemesi', 'checks' => $checks]));

        $reply = $this->send('Yusufa 11 çek verdim');
        $this->assertStringContainsString('11 çek: 10 × 100.000,00 ₺ (28.02.2027 → 30.11.2027); vade 31.12.2027 · 187.000,00 ₺', $reply);

        $this->assertStringContainsString('11 çek kaydedildi', $this->send('evet'));
        $this->assertSame(11, \App\Models\Check::where('party_id', $yusuf->id)->count());
    }

    /** Kullanıcı 21.10: "en son çekin 187 olmasını anlatmakta zorlandım" — bölme hesabı kodda, seçenek numarayla. */
    public function test_cheque_plan_offers_round_split_and_saves_choice(): void
    {
        $yusuf = Party::create(['name' => 'Anlama Testi Yusuf']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 1187000, 'party_id' => $yusuf->id,
            'work_status' => 'not_yet', 'work_status_known' => true, 'description' => 'Çek ödemesi',
            'check_plan' => ['count' => 11, 'first_month' => '2027-02', 'due_day' => null, 'each_amount' => null]]));

        $q = $this->send('Yusufa 1.187.000 çek verdim seneye şubattan 11 çek aya böl');
        $this->assertStringContainsString('11 çeke nasıl böleyim?', $q);
        $this->assertStringContainsString('*1* 10 × 100.000,00 + son çek 187.000,00', $q);
        $this->assertStringContainsString('Eşit: 10 × 107.909,09 + son çek 107.909,10', $q);
        $this->assertStringContainsString('Vadeler: 28.02.2027 → 31.12.2027 (ay sonu)', $q);
        $this->assertStringNotContainsString('Kaydedeyim mi', $q);

        $card = $this->send('1');
        $this->assertStringContainsString('11 çek: 10 × 100.000,00 ₺ (28.02.2027 → 30.11.2027); vade 31.12.2027 · 187.000,00 ₺', $card);

        $this->assertStringContainsString('11 çek kaydedildi', $this->send('evet'));
        $checks = \App\Models\Check::where('party_id', $yusuf->id)->orderBy('due_date')->get();
        $this->assertEqualsWithDelta(1187000, $checks->sum('amount'), 0.01);
        $this->assertEqualsWithDelta(187000, (float) $checks->last()->amount, 0.01);
        $this->assertSame('2027-02-28', $checks->first()->due_date->toDateString());
    }

    /** "100'er yaz, kalanı sona" → kod hesaplar (AI 10 × 100.000 + 87.000 yazmıştı); soru yok. */
    public function test_cheque_plan_with_each_amount_puts_rest_on_last(): void
    {
        $yusuf = Party::create(['name' => 'Anlama Testi Yusuf']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 1187000, 'party_id' => $yusuf->id,
            'work_status' => 'not_yet', 'work_status_known' => true, 'description' => 'Çek ödemesi',
            'check_plan' => ['count' => 11, 'first_month' => '2027-02', 'each_amount' => 100000]]));

        $card = $this->send('Yusufa 1.187.000 11 çek, 100 er yaz kalanı sona');
        $this->assertStringContainsString('vade 31.12.2027 · 187.000,00 ₺', $card);
        $this->assertStringNotContainsString('❓', $card);
    }

    /** Çekler tek tek yazıldı ama toplam tutmuyor → kaydetmez, numaralı düzeltme. */
    public function test_cheque_total_mismatch_asks_before_saving(): void
    {
        $yusuf = Party::create(['name' => 'Anlama Testi Yusuf']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 1187000, 'party_id' => $yusuf->id,
            'work_status' => 'not_yet', 'work_status_known' => true, 'description' => 'Çek ödemesi',
            'checks' => [['number' => null, 'due_date_text' => '28.02.2027', 'amount' => 1000000],
                ['number' => null, 'due_date_text' => '31.03.2027', 'amount' => 87000]]]));

        $q = $this->send('Yusufa çek verdim');
        $this->assertStringContainsString('Çeklerin toplamı 1.087.000,00 ₺, ödeme 1.187.000,00 ₺ — 100.000,00 ₺ eksik.', $q);
        $this->assertStringContainsString('*1* Son çek 187.000,00 ₺ olsun · *2* Ödeme 1.087.000,00 ₺ olsun', $q);
        $this->assertStringContainsString('❓', $this->send('evet'), 'evet kaydetmez, soru tekrar');
        $this->assertSame(0, \App\Models\Check::where('party_id', $yusuf->id)->count());

        $this->assertStringContainsString('vade 31.03.2027 · 187.000,00 ₺', $this->send('1'));
    }

    /** Kullanıcı 21.10: "3 çek şubattan başla 2 ay aralıkla" / "ayın ortalarına". */
    public function test_cheque_plan_interval_and_mid_month(): void
    {
        $yusuf = Party::create(['name' => 'Anlama Testi Yusuf']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 900000, 'party_id' => $yusuf->id,
            'work_status' => 'not_yet', 'work_status_known' => true, 'description' => 'Çek ödemesi',
            'check_plan' => ['count' => 3, 'first_month' => '2027-02', 'due_day' => 15, 'interval_months' => 2]]));

        $card = $this->send('Yusufa 900 bin 3 çek şubattan başla 2 ay arayla ayın ortasına');
        $this->assertStringContainsString('3 çek: vade 15.02.2027 · 300.000,00 ₺; vade 15.04.2027 · 300.000,00 ₺; vade 15.06.2027 · 300.000,00 ₺', $card);
        $this->assertStringNotContainsString('❓', $card, 'eşit ve yuvarlak → sorulmaz');

        $this->send('evet');
        $this->assertSame(['2027-02-15', '2027-04-15', '2027-06-15'], \App\Models\Check::where('party_id', $yusuf->id)->orderBy('due_date')
            ->get()->map(fn ($c) => $c->due_date->toDateString())->all());
    }

    /** "biri şubat diğeri mayıs, ayın ortası" → ay listesi; yılsız/geçmiş ay sonraki yıla. */
    public function test_cheque_plan_irregular_months_and_past_year(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-21 12:00'));
        $plan = \App\Services\Whatsapp\CheckSplit::normalize(['months' => ['2026-02', '2026-05'], 'due_day' => 15]);
        $this->assertSame(2, $plan['count']);
        $d = \App\Services\Whatsapp\CheckSplit::prepare(['amount' => 400000, 'check_plan' => $plan]);
        $this->assertSame(['15.02.2027', '15.05.2027'], array_column($d['checks'], 'due_date_text'));
        $this->assertSame([200000.0, 200000.0], array_column($d['checks'], 'amount'));

        $every = \App\Services\Whatsapp\CheckSplit::prepare(['amount' => 400000, 'check_plan' => ['count' => 4, 'first_month' => '2026-02', 'interval_months' => 3]]);
        $this->assertSame(['28.02.2027', '31.05.2027', '31.08.2027', '30.11.2027'], array_column($every['checks'], 'due_date_text'));
    }

    /** Kullanıcı 21.10: geçmiş çek engellenmez (yeni başlayan eski çekini girer) ama yıl yazılmadıysa sorulur. */
    public function test_past_due_cheque_asks_unless_year_written(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-21 12:00'));
        $yusuf = Party::create(['name' => 'Anlama Testi Yusuf']);
        $draft = fn () => $this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 300000, 'party_id' => $yusuf->id,
            'work_status' => 'not_yet', 'work_status_known' => true, 'description' => 'Çek ödemesi',
            'checks' => [['number' => null, 'due_date_text' => '15.02.2026', 'amount' => 300000]]]);

        $this->ai($draft());
        $q = $this->send('Yusufa 300 bin çek verdim şubat ortası');
        $this->assertStringContainsString('15.02.2026 vadesi geçmiş görünüyor.', $q);
        $this->assertStringContainsString('*1* Eski çek, öyle kalsın · *2* 15.02.2027 olsun', $q);
        $this->assertStringContainsString('vade 15.02.2027', $this->send('2'));
        $this->send('evet');
        $this->assertSame('2027-02-15', \App\Models\Check::where('party_id', $yusuf->id)->first()->due_date->toDateString());

        // Yılı kendisi yazdı → eski çek, sorulmaz.
        $this->send('iptal');
        $this->ai($draft());
        $this->assertStringNotContainsString('vadesi geçmiş', $this->send('Yusufa 300 bin çek vermiştim vadesi 15.02.2026'));
    }

    /** Sonnet 21.10: vadeyi "şubat ortası" diye yazı olarak verdi → vadesiz çek kaydı veritabanında düştü. */
    public function test_written_due_dates_are_parsed_or_asked(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-21 12:00'));
        $f = fn ($t) => \App\Services\Whatsapp\CheckSplit::dueText($t);
        $this->assertSame('15.02.2027', $f('şubat ortası'));
        $this->assertSame('28.02.2027', $f('Şubat sonu'));
        $this->assertSame('28.02.2027', $f('şubat'));
        $this->assertSame('10.03.2027', $f('10 mart'));
        $this->assertSame('01.11.2026', $f('kasım başı'));
        $this->assertSame('15.02.2026', $f('15 şubat 2026'), 'yıl yazıldıysa aynen');
        $this->assertNull($f('31-02-2027'));

        $yusuf = Party::create(['name' => 'Anlama Testi Yusuf']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 300000, 'party_id' => $yusuf->id,
            'work_status' => 'not_yet', 'work_status_known' => true, 'description' => 'Çek ödemesi',
            'checks' => [['number' => null, 'due_date_text' => 'şubat ortası', 'amount' => 300000]]]));
        $this->assertStringContainsString('vade 15.02.2027', $this->send('Yusufa 300 bin çek verdim vadesi şubat ortası'));

        $this->send('iptal');
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 300000, 'party_id' => $yusuf->id,
            'work_status' => 'not_yet', 'work_status_known' => true, 'description' => 'Çek ödemesi',
            'checks' => [['number' => null, 'due_date_text' => 'sonra', 'amount' => 300000]]]));
        $this->assertStringContainsString('Çekin vadesini gün.ay.yıl olarak yazar mısın?', $this->send('Yusufa 300 bin çek verdim'));
        $this->assertStringContainsString('❓', $this->send('evet'));
        $this->assertSame(0, \App\Models\Check::where('party_id', $yusuf->id)->count());
    }

    /** Firma 7, 21.10: çek sayfası okunamadı → çeksiz ödeme yazıldı; aynı sayfa tekrar → önceki sayfanın numaraları yazıldı. */
    public function test_unread_or_duplicate_cheques_are_not_saved(): void
    {
        $seramik = Party::create(['name' => 'Anlama Testi Seramik']);
        $base = ['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 400000, 'party_id' => $seramik->id, 'from_document' => true,
            'work_status' => 'not_yet', 'work_status_known' => true, 'description' => "Seramik'e 4 adet çek"];

        // Okunamayan çek (fotoğraf yolu; burada belge bayrağıyla doğrudan).
        $unread = \App\Services\Whatsapp\CheckSplit::prepare($base + ['checks' => null]);
        $this->assertSame('unread', $unread['check_choice']);
        $this->assertStringContainsString('Fotoğraftaki çekleri okuyamadım', \App\Services\Whatsapp\CheckSplit::question($unread));
        $this->assertNull(\App\Services\Whatsapp\CheckSplit::prepare(['from_document' => false] + $base + ['checks' => null])['check_choice'], 'yazıyla anlatılan ödeme');

        $old = PartyLedgerEntry::create(['party_id' => $seramik->id, 'type' => PartyLedgerEntry::TYPE_PAYMENT, 'amount' => 100000,
            'entry_date' => now()->toDateString(), 'description' => 'Avans — çek']);
        \App\Models\Check::create(['party_id' => $seramik->id, 'party_ledger_entry_id' => $old->id, 'check_number' => '2023471',
            'issue_date' => now(), 'due_date' => '2027-02-28', 'amount' => 100000, 'status' => 'issued']);
        $this->ai($this->d(['amount' => 100000, 'checks' => [['number' => '2023471', 'due_date_text' => '28.02.2027', 'amount' => 100000]]] + $base));
        $this->assertStringContainsString('2023471 (28.02.2027 · Anlama Testi Seramik) numaralı çek zaten kayıtlı', $this->send('çek fotoğrafı'));
        $this->send('evet');
        $this->assertSame(1, \App\Models\Check::where('check_number', '2023471')->count());
    }

    public function test_cheque_split_options(): void
    {
        $this->assertCount(1, \App\Services\Whatsapp\CheckSplit::options(900000, 3), 'eşit ve yuvarlak → sorulmaz');
        $this->assertCount(1, \App\Services\Whatsapp\CheckSplit::options(5000, 2));
        $this->assertSame([[100000.0, 187000.0], [107000.0, 117000.0], [107909.09, 107909.1]],
            \App\Services\Whatsapp\CheckSplit::options(1187000, 11));
    }

    public function test_sector_and_business_note_reach_assistant_context(): void
    {
        config(['app.sector' => 'construction', 'app.business_note' => 'Çoğunlukla vadeli çekle öder.']);
        $context = \App\Services\Whatsapp\ExpenseContext::build(self::PHONE);
        $this->assertStringContainsString('İŞLETME: İnşaat / müteahhit — Çoğunlukla vadeli çekle öder.', $context);
        $this->assertStringContainsString('"Bağlantı" = fiyatı sabitlenmiş', $context);

        config(['app.sector' => null, 'app.business_note' => null]);
        $this->assertStringNotContainsString('İŞLETME:', \App\Services\Whatsapp\ExpenseContext::build(self::PHONE), 'seçilmezse eskisi gibi');
    }

    public function test_hub_settings_keep_only_known_sector(): void
    {
        $firm = new \App\Models\HubFirm(['name' => 'x']);
        $firm->settings = ['sector' => 'bakery', 'business_note' => '  3 şube  '];
        $this->assertSame('bakery', $firm->settingsWithDefaults()['sector']);
        $this->assertSame('3 şube', $firm->settingsWithDefaults()['business_note']);
        $firm->settings = ['sector' => 'uydurma'];
        $this->assertNull($firm->settingsWithDefaults()['sector']);
    }

    public function test_missing_understanding_fields_keep_old_behaviour(): void
    {
        // Alan yoksa (eski taslak) kapı devreye girmez — eski "Ne için verdin?" akışı sürer.
        $ali = Party::create(['name' => 'Anlama Testi Ali']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 5000, 'party_id' => $ali->id]));

        $this->assertStringContainsString('Ne için verdin', $this->send('Aliye 5000 ödedim'));
    }

    /** Review 3 (18.10): "usta işi geçen hafta bitirmişti, bugün parasını verdim" — işin borcu kayıtlıyken yeni alış açılmaz. */
    public function test_done_payment_to_party_we_owe_pays_the_debt(): void
    {
        $usta = Party::create(['name' => 'Borçlu Usta']);
        PartyLedgerEntry::create(['party_id' => $usta->id, 'type' => PartyLedgerEntry::TYPE_PURCHASE, 'amount' => 50000,
            'entry_date' => now()->subWeek()->toDateString(), 'description' => 'Sıva işçiliği']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 50000, 'party_id' => $usta->id,
            'work_status' => 'done', 'work_status_known' => true, 'description' => 'Sıva']));
        $purchases = PartyLedgerEntry::where('type', PartyLedgerEntry::TYPE_PURCHASE)->count();

        $card = $this->send('Usta işi geçen hafta bitirmişti bugün parasını verdim 50 bin');
        $this->assertStringContainsString('borcunu ödedin', $card);
        $this->send('evet');

        $this->assertSame($purchases, PartyLedgerEntry::where('type', PartyLedgerEntry::TYPE_PURCHASE)->count(), 'yeni alış yok');
        $this->assertEqualsWithDelta(0, PartyStatement::build($usta)['balance'], 0.01, 'borç kapandı');
    }

    public function test_done_payment_bigger_than_debt_asks_then_new_job(): void
    {
        $usta = Party::create(['name' => 'Borçlu Usta']);
        PartyLedgerEntry::create(['party_id' => $usta->id, 'type' => PartyLedgerEntry::TYPE_PURCHASE, 'amount' => 50000,
            'entry_date' => now()->subWeek()->toDateString(), 'description' => 'Sıva işçiliği']);
        $done = $this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 80000, 'party_id' => $usta->id,
            'work_status' => 'done', 'work_status_known' => true, 'description' => 'Boya']);
        $this->ai($done, ['is_new_entry' => false] + $done);
        config(['services.anthropic.api_key' => 'sk-test']);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.twilio.com/*' => Http::response([], 200), 'api.anthropic.com/*' => Http::response(['content' => [['type' => 'tool_use', 'name' => 'judge', 'input' => ['answers' => true]]], 'usage' => []])]);

        $q = $this->send('Usta boyayı bitirdi 80 bin verdim');
        $this->assertStringContainsString("Borçlu Usta'e zaten 50.000,00 ₺ borcun görünüyor", $q);
        $this->assertStringNotContainsString('Kaydedeyim mi', $q);

        $this->send('yeni iş, boyanın parası');
        $this->send('evet');
        $this->assertEqualsWithDelta(-50000, PartyStatement::build($usta)['balance'], 0.01, 'eski borç durur, yeni iş peşin');
    }
}
