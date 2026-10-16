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

    public function test_unknown_work_status_asks_and_yes_does_not_save(): void
    {
        $osman = Party::create(['name' => 'Anlama Testi Osman']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 1005000, 'party_id' => $osman->id,
            'work_status' => 'done', 'work_status_known' => false, 'question' => 'Asansör takıldı mı, yoksa peşin mi ödüyorsunuz?']));
        $before = PartyLedgerEntry::count();

        $reply = $this->send('Osman asansöre 1.005.000 ödendi');
        $this->assertStringContainsString('Asansör takıldı mı', $reply);
        $this->assertStringNotContainsString('Kaydedeyim mi', $reply, 'tahminle onaya hazırlanmaz');

        $this->assertStringContainsString('Asansör takıldı mı', $this->send('evet'), '"evet" soruyu geçemez');
        $this->assertSame($before, PartyLedgerEntry::count());
    }

    public function test_known_not_yet_payment_becomes_advance_with_meaning_line(): void
    {
        $osman = Party::create(['name' => 'Anlama Testi Osman']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 1005000, 'party_id' => $osman->id,
            'work_status' => 'not_yet', 'work_status_known' => true,
            'meaning' => "Osman'a asansör parasını ödedin, asansör henüz takılmadı."]));

        $reply = $this->send('daha takılmadı');
        $this->assertStringContainsString('🗣 Osman\'a asansör parasını ödedin, asansör henüz takılmadı.', $reply);
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

    public function test_missing_understanding_fields_keep_old_behaviour(): void
    {
        // Alan yoksa (eski taslak) kapı devreye girmez — eski "Ne için verdin?" akışı sürer.
        $ali = Party::create(['name' => 'Anlama Testi Ali']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 5000, 'party_id' => $ali->id]));

        $this->assertStringContainsString('Ne için verdin', $this->send('Aliye 5000 ödedim'));
    }
}
