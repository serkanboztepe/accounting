<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Models\Sale;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\TradeDraft;
use App\Support\Labels;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/** Proje / Şube — tek mekanizma (Akkuş: 3 şube). Kasa şubesiz kaydedilmez; öneri; 0 = genel; ödemede sorulmaz. AI mock. */
class WhatsappBranchTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905558880004';

    private array $branches = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('trade', ['projects' => true]), 'app.profile' => 'trade', 'app.project_label' => null,
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 888 00 04'],
            'services.anthropic.chat_assistant' => false]);
        Http::fake();
        Project::query()->update(['status' => 'cancelled']); // yerel denemedeki projeler menüye karışmasın (geri alınır)
        foreach (['İzzetpaşa', 'Araştırma', 'Ergenekon'] as $n) {
            $this->branches[$n] = Project::create(['name' => $n . ' Test', 'status' => 'active']);
        }
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
    }

    private function kasa(array $o = []): array
    {
        return array_merge(['kind' => ExpenseExtractor::KIND_TRADE_SALE, 'items' => [], 'amount' => 27440, 'cash_amount' => 5900,
            'card_amount' => 21540, 'paid' => true, 'date' => now()->format('Y-m-d'), 'description' => 'Ergenekon kasa',
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

    public function test_label_follows_profile_and_setting(): void
    {
        $this->assertSame('Şube', Labels::project());
        config(['app.profile' => 'muteahhit']);
        $this->assertSame('Proje', Labels::project());
        config(['app.project_label' => 'Ahır']);
        $this->assertSame('Ahırlar', Labels::projects());
    }

    public function test_kasa_with_branch_name_is_saved_to_branch(): void
    {
        $this->ai($this->kasa(['project_id' => $this->branches['Ergenekon']->id]));

        $this->assertStringContainsString('🏪 Şube: Ergenekon Test', $this->send('Ergenekon kasa / Nakit 5900 / Pos 21540'));
        $this->send('evet');
        $this->assertSame($this->branches['Ergenekon']->id, Sale::latest('id')->value('project_id'));
    }

    public function test_kasa_without_branch_is_blocked_until_number(): void
    {
        $this->ai($this->kasa());
        $before = Sale::count();

        $reply = $this->send('Kasa nakit 5900 pos 21540');
        $this->assertStringContainsString('Hangi şube? Numarayı yaz', $reply);
        $this->assertStringNotContainsString('0) Genel', $reply, 'kasada genel yok');
        $this->send('evet');
        $this->assertSame($before, Sale::count(), 'şubesiz kasa kaydedilmez');

        $this->send('2');
        $this->assertSame($before + 1, Sale::count());
        $this->assertSame($this->branches['Araştırma']->id, Sale::latest('id')->value('project_id'));
    }

    public function test_number_habit_suggests_branch(): void
    {
        $this->ai($this->kasa(['project_id' => $this->branches['Ergenekon']->id]), $this->kasa());
        $this->send('Ergenekon kasa 1');
        $this->send('evet');

        $reply = $this->send('kasa nakit 5000 pos 1000');
        $this->assertStringContainsString('Şube: *Ergenekon Test* (son)', $reply);
        $this->send('evet');
        $this->assertSame($this->branches['Ergenekon']->id, Sale::latest('id')->value('project_id'));
    }

    public function test_purchase_suggests_party_branch_and_zero_means_general(): void
    {
        $party = Party::create(['name' => 'Un Toptancı Şube']);
        PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => now()->subDay(), 'type' => 'alis', 'amount' => 100,
            'description' => 'Un', 'project_id' => $this->branches['İzzetpaşa']->id]);
        $this->ai(['kind' => ExpenseExtractor::KIND_PURCHASE, 'items' => [], 'amount' => 35750, 'paid' => false, 'date' => now()->format('Y-m-d'),
            'description' => '25 çuval un', 'party_id' => $party->id, 'party_name' => null, 'project_id' => null, 'project_name' => null,
            'confidence' => 'high', 'question' => null, 'is_new_entry' => true]);

        $reply = $this->send('Un toptancıdan 25 çuval un 35750');
        $this->assertStringContainsString('Şube: *İzzetpaşa Test* (son)', $reply);
        $this->assertStringContainsString('0) Genel / ortak', $reply);
        $this->send('0');
        $this->assertNull(PartyLedgerEntry::where('party_id', $party->id)->latest('id')->value('project_id'));
    }

    public function test_payment_does_not_ask_branch(): void
    {
        $party = Party::create(['name' => 'Ödeme Testi Cari']);
        PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => now()->subDay(), 'type' => 'alis', 'amount' => 5000, 'description' => 'Un']);
        $this->ai(['kind' => ExpenseExtractor::KIND_PAYMENT, 'items' => [], 'amount' => 5000, 'paid' => true, 'date' => now()->format('Y-m-d'),
            'description' => 'Ödeme', 'party_id' => $party->id, 'party_name' => null, 'project_id' => null, 'project_name' => null,
            'confidence' => 'high', 'question' => null, 'is_new_entry' => true]);

        $this->assertStringNotContainsString('Şube', $this->send('Ödeme testi cariye 5000 ödedim'));
    }

    public function test_branch_revenue_question(): void
    {
        \App\Support\TradeDesk::saveSale(['mode' => 'cash', 'manual_amount' => '27440', 'project_id' => $this->branches['Ergenekon']->id]);
        \App\Support\TradeDesk::saveSale(['mode' => 'cash', 'manual_amount' => '21845', 'project_id' => $this->branches['İzzetpaşa']->id]);
        $today = now()->toDateString();

        $text = TradeDraft::answer(['kind' => ExpenseExtractor::KIND_MONTH_RESULT, 'date_from' => $today, 'date_to' => $today,
            'project_id' => $this->branches['Ergenekon']->id]);
        $this->assertStringContainsString('Ergenekon Test', $text);
        $this->assertStringContainsString('Ciro: 27.440,00', $text);
    }

    public function test_single_branch_is_automatic(): void
    {
        Project::whereKey([$this->branches['Araştırma']->id, $this->branches['Ergenekon']->id])->update(['status' => 'cancelled']);
        $this->ai($this->kasa());

        $reply = $this->send('kasa nakit 5900 pos 21540');
        $this->assertStringNotContainsString('Hangi şube', $reply);
        $this->send('evet');
        $this->assertSame($this->branches['İzzetpaşa']->id, Sale::latest('id')->value('project_id'));
    }

    /** Mimar (Ferhat): alış / satışta proje sorulmaz — eskisi gibi. */
    public function test_architect_sale_has_no_project_question(): void
    {
        config(['modules' => ModuleProfiles::resolve('mimar', ['projects' => true]), 'app.profile' => 'mimar']);
        $this->ai(['kind' => ExpenseExtractor::KIND_SALE, 'items' => [], 'amount' => 20000, 'paid' => false, 'date' => now()->format('Y-m-d'),
            'description' => 'EKB belgesi', 'party_id' => null, 'party_name' => 'Mimar Müşteri', 'project_id' => null, 'project_name' => null,
            'confidence' => 'high', 'question' => null, 'is_new_entry' => true]);

        $reply = $this->send('Mimar müşteri 20000 EKB belgesi');
        $this->assertStringNotContainsString('Hangi proje', $reply);
        $this->assertStringNotContainsString('0) Genel', $reply);
    }

    /** Açık alış taslağı "Hangi şube?" sorarken gelen kasa mesajı şube cevabı sanılmaz (deneme seti 16.10). */
    public function test_till_message_is_not_a_correction_of_other_draft(): void
    {
        config(['modules.smart_assistant' => true]);
        $party = Party::create(['name' => 'Un Toptancı Kasa Testi']);
        $purchase = WhatsappPendingExpense::create(['phone' => self::PHONE, 'status' => 'awaiting_confirmation', 'summary' => 'alış',
            'extracted' => ['kind' => ExpenseExtractor::KIND_PURCHASE, 'party_id' => $party->id, 'amount' => 7000, 'paid' => false,
                'items' => [], 'description' => 'un', 'date' => now()->format('Y-m-d'), 'project_needed' => true]]);
        $chat = Mockery::mock(\App\Services\Whatsapp\ChatAssistant::class);
        $chat->shouldReceive('respond')->once()
            ->with(self::PHONE, Mockery::any(), null, true) // taslaksız + geçmişsiz
            ->andReturn(['type' => 'entry', 'text' => '', 'data' => $this->kasa(['project_id' => $this->branches['Ergenekon']->id])]);
        $this->app->instance(\App\Services\Whatsapp\ChatAssistant::class, $chat);

        $reply = $this->send("Ergenekon kasa\nNakit 5900\nPos 21540");

        $this->assertStringContainsString('Ergenekon Test', $reply);
        $this->assertSame('awaiting_confirmation', $purchase->fresh()->status, 'alış taslağı yarım kayıt olarak açık kalır');
        $this->assertSame(ExpenseExtractor::KIND_TRADE_SALE, WhatsappPendingExpense::where('phone', self::PHONE)->latest('id')->first()->extracted['kind']);
    }
}
