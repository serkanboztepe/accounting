<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ChatAssistant;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\Understanding;
use App\Support\ModuleProfiles;
use App\Support\MorningAlerts;
use App\Support\PartyStatement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * 23.10 yeniden oynatmadaki tekrar sorular.
 *  - Tahsilat (Enes): AI sorusunun türünü kendisi söyler (`question_about`); yalnız "purpose" + kaydı değiştirmeyen durumda soru kalkar.
 *    Tür yok / başka / çelişkili → soru korunur. Kişi, tutar, yön, sözleşme soruları korunur.
 *  - Borç notu (Serdar): AÇIK İŞ — "eski borç" etiketi bugün de sorulur; tarih anlaşmayı dışlamaz (karşı örnek).
 * AI taklit edilir (ChatAssistant); süzgeç/ikinci göz HTTP'si sahte.
 */
class WhatsappSameRecordQuestionTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905557770031';

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('mimar', ['smart_assistant' => true, 'cari_supplier' => true, 'assistant_pilot' => true]), 'app.profile' => 'mimar',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 777 00 31']]);
        Http::fake();
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
    }

    private function chat(array ...$data): void
    {
        $mock = Mockery::mock(ChatAssistant::class);
        $mock->shouldReceive('respond')->andReturn(...array_map(fn ($d) => ['type' => 'entry', 'text' => '', 'data' => $d], $data));
        $this->app->instance(ChatAssistant::class, $mock);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    private function entry(array $o): array
    {
        return array_merge(['items' => [], 'payment_type' => null, 'date' => now()->format('Y-m-d'), 'due_date' => null, 'description' => '',
            'project_id' => null, 'project_name' => null, 'party_id' => null, 'party_name' => null, 'category_id' => null, 'category_name' => null,
            'paid' => null, 'confidence' => 'high', 'question' => null, 'is_new_entry' => true, 'payment_purpose' => null, 'debt_side' => null,
            'work_status' => null, 'work_status_known' => null, 'party_certain' => true], $o);
    }

    private function debtor(float $owes): Party
    {
        $p = Party::create(['name' => 'ZXQ ENES UÇAR']);
        if ($owes > 0) {
            PartyLedgerEntry::create(['party_id' => $p->id, 'type' => PartyLedgerEntry::TYPE_SALE, 'amount' => $owes, 'entry_date' => now()->subDays(9)->toDateString(),
                'description' => 'Proje']);
        }

        return $p;
    }

    private function collection(Party $p, float $amount, array $o = []): array
    {
        return $this->entry($o + ['kind' => ExpenseExtractor::KIND_COLLECTION, 'amount' => $amount, 'party_id' => $p->id, 'description' => 'EFT tahsilatı',
            'work_status_known' => false, 'question' => 'Bu ödeme hangi iş için — önceki bir iş mi, yoksa peşin mi?', 'question_about' => 'purpose']);
    }

    // ------------------------------------------------------------------ 1) tahsilat

    /** Enes: borcu 120.000, 25.000 EFT geldi, AI "amaç" sorusu → sorulmaz; "evet" ile düz tahsilat. */
    public function test_purpose_question_within_receivable_is_dropped(): void
    {
        $enes = $this->debtor(120000);
        $this->chat($this->collection($enes, 25000));

        $card = $this->send('Enes uçardan 25000 tl eft geldi');
        $this->assertStringNotContainsString('hangi iş için', $card);
        $this->assertStringContainsString('Kaydedeyim mi?', $card);
        $this->send('evet');

        $row = PartyLedgerEntry::where('party_id', $enes->id)->where('type', PartyLedgerEntry::TYPE_COLLECTION)->sole();
        $this->assertEqualsWithDelta(25000, (float) $row->amount, 0.01);
        $this->assertNull($row->due_date);
        $this->assertStringNotContainsStringIgnoringCase('avans', (string) $row->description);
        $this->assertEqualsWithDelta(95000, PartyStatement::build($enes)['balance'], 0.01);
    }

    /** @return array<string, array{0: array, 1: string}> AI'ın bildirdiği tür / durum → korunması gereken soru */
    public static function keptQuestions(): array
    {
        return [
            'tutar (kullanıcı karşı örneği)' => [['question' => '25.000 TL mi, 50.000 TL mi geldi?', 'question_about' => 'amount'], '50.000 TL mi'],
            'tür yok' => [['question_about' => null], 'hangi iş için'],
            'tür other' => [['question_about' => 'other'], 'hangi iş için'],
            'kişi' => [['question' => 'ZXQ ENES UÇAR mı, başka bir Enes mi?', 'question_about' => 'party', 'party_certain' => false], 'başka bir Enes'],
            'kişi belirsiz ama tür amaç (çelişki)' => [['party_certain' => false], 'hangi iş için'],
            'yön' => [['question' => 'Parayı Enes mi gönderdi, sen mi gönderdin?', 'question_about' => 'direction'], 'sen mi gönderdin'],
            'tarih' => [['question' => 'Bu para bugün mü geldi, dün mü?', 'question_about' => 'date'], 'bugün mü'],
            'proje' => [['question' => 'Hangi proje için?', 'question_about' => 'project'], 'Hangi proje'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('keptQuestions')]
    public function test_other_question_types_are_kept(array $o, string $expect): void
    {
        $enes = $this->debtor(120000);
        $this->chat($this->collection($enes, 25000, $o));

        $this->assertStringContainsString($expect, $this->send('Enes uçardan eft geldi'));
    }

    public function test_purpose_question_without_receivable_is_kept(): void
    {
        $this->chat($this->collection($this->debtor(0), 20000));

        $this->assertStringContainsString('hangi iş için', $this->send('Aliden 20 bin geldi'));
    }

    public function test_purpose_question_above_receivable_is_kept(): void
    {
        $this->chat($this->collection($this->debtor(120000), 150000));

        $this->assertStringContainsString('hangi iş için', $this->send('Enes uçardan 150000 geldi'));
    }

    public function test_purpose_question_with_active_contract_is_kept(): void
    {
        $enes = $this->debtor(120000);
        Contract::withoutGlobalScopes()->create(['party_id' => $enes->id, 'title' => 'Proje sözleşmesi', 'status' => 'active', 'total_amount' => 300000,
            'contract_date' => now()->subMonth()->toDateString()]);
        $this->chat($this->collection($enes, 25000));

        $this->assertStringContainsString('hangi iş için', $this->send('Enes uçardan 25000 geldi'));
    }

    public function test_stated_purpose_is_recorded_as_advance(): void
    {
        $veli = Party::create(['name' => 'Zxq Veli']);
        $this->chat($this->collection($veli, 50000, ['work_status_known' => true, 'question' => null, 'question_about' => null,
            'payment_purpose' => 'advance', 'description' => 'Proje kaporası']));
        $this->send('Veli Bey 50 bin kapora verdi, projesini sonra çizeceğim');
        $this->send('evet');

        $this->assertStringStartsWith('Avans', (string) PartyLedgerEntry::where('party_id', $veli->id)->value('description'));
    }

    public function test_payment_purpose_question_is_untouched(): void
    {
        $enes = $this->debtor(120000);
        $d = ['kind' => ExpenseExtractor::KIND_PAYMENT] + $this->collection($enes, 25000);

        $this->assertFalse(Understanding::purposeQuestionIsMoot($d), 'ödeme ("Enes\'e gönderdim") kapsam dışı');
    }

    public function test_question_about_is_normalized(): void
    {
        $this->assertSame('purpose', Understanding::normalize(['question' => 'x?', 'question_about' => 'purpose'])['question_about']);
        $this->assertNull(Understanding::normalize(['question' => 'x?', 'question_about' => 'neden'])['question_about'], 'bilinmeyen değer → tür yok');
        $this->assertNull(Understanding::normalize(['question' => null, 'question_about' => 'purpose'])['question_about'], 'soru yoksa tür yok');
        $this->assertContains('purpose', Understanding::schema()['question_about']['enum']);
        $this->assertStringContainsString('`question_about`', Understanding::rules());
    }

    /** Eşdeğerlik: alacak içindeki tahsilatta "avans" ile düz tahsilat aynı bakiye/vade/proje, açık peşin para yok; fark yalnız etiket. */
    public function test_within_receivable_advance_and_plain_collection_give_same_balance(): void
    {
        $results = [];
        foreach (['plain' => null, 'advance' => 'advance'] as $label => $purpose) {
            $p = Party::create(['name' => 'ZXQ Eşdeğer Tahsilat ' . $label]);
            PartyLedgerEntry::create(['party_id' => $p->id, 'type' => PartyLedgerEntry::TYPE_SALE, 'amount' => 120000, 'entry_date' => now()->subDays(9)->toDateString()]);
            $this->chat($this->collection($p, 25000, ['work_status_known' => true, 'question' => null, 'question_about' => null, 'payment_purpose' => $purpose]));
            $this->send('25 bin geldi ' . $label);
            $this->send('evet');
            $row = PartyLedgerEntry::where('party_id', $p->id)->where('type', PartyLedgerEntry::TYPE_COLLECTION)->sole();
            $results[$label] = [PartyStatement::build($p)['balance'], \App\Services\Whatsapp\AdvanceOffset::open($p, ExpenseExtractor::KIND_SALE),
                $row->due_date, $row->project_id, (string) $row->description];
        }

        $this->assertSame(array_slice($results['plain'], 0, 4), array_slice($results['advance'], 0, 4));
        $this->assertEqualsWithDelta(95000, $results['plain'][0], 0.01);
        $this->assertStringStartsWith('Avans', $results['advance'][4]);
        $this->travel(14)->days();
        $this->assertSame([], array_values(array_filter(MorningAlerts::openAdvances(now()), fn ($l) => str_contains($l, 'ZXQ Eşdeğer'))));
    }

    // ------------------------------------------------------------------ 2) borç notu

    private function debtNote(string $status, ?bool $known, array $o = []): array
    {
        return $this->entry($o + ['kind' => ExpenseExtractor::KIND_DEBT_NOTE, 'debt_side' => 'payable', 'amount' => 2100000, 'party_name' => 'Zxq Serdar',
            'description' => 'Borç alındı', 'due_date' => now()->addMonth()->toDateString(), 'work_status' => $status, 'work_status_known' => $known]);
    }

    /** Açık konu: Serdar ("para aldım, 1 aylığına") — model "eski borç" derse menü bugün sorulur. */
    public function test_old_debt_with_repayment_date_is_still_asked(): void
    {
        $this->chat($this->debtNote(Understanding::OLD_DEBT, true));

        $this->assertStringContainsString('neden?', $this->send('Serdar boztepede 2milyon 100bin para aldım 1ayligina geri odeyecem'));
    }

    /** Karşı örnek (kullanıcı 23.10): tarih anlaşmayı dışlamaz — "işe başlamadı, ay sonunda ödeyeceğim" + eski borç etiketi sorulur. */
    public function test_not_started_with_repayment_date_labelled_old_debt_is_asked(): void
    {
        $this->chat($this->debtNote(Understanding::OLD_DEBT, true, ['party_name' => 'Zxq Boyacı', 'description' => 'Boya işi']));

        $reply = $this->send('Zxq boyacıya 2milyon 100bin borcum var, işe başlamadı, ay sonunda ödeyeceğim');
        $this->assertStringContainsString('neden?', $reply);
        $this->assertStringNotContainsString('Kaydedeyim mi', $reply);
    }

    /** Borç para etiketi bugün de sorusuz geçer (değişmedi). */
    public function test_no_work_debt_note_is_not_asked(): void
    {
        $this->chat($this->debtNote(Understanding::NO_WORK, true));

        $this->assertStringContainsString('Kaydedeyim mi?', $this->send('Serdar boztepede 2milyon 100bin para aldım 1ayligina geri odeyecem'));
    }

    /** Eşdeğerlik ölçümü: "iş bitti" ve "borç para" aynı satırı yazar (tür, tutar, açıklama, vade, proje, ödeme türü). */
    public function test_done_and_no_work_write_the_same_row(): void
    {
        $rows = [];
        foreach ([Understanding::DONE, Understanding::NO_WORK] as $i => $status) {
            $name = 'Zxq Eşdeğer ' . $i;
            $this->chat($this->debtNote($status, true, ['party_name' => $name]));
            $this->send("{$name} 2milyon 100bin borç {$i}");
            $this->send('Tamam');
            $r = PartyLedgerEntry::where('party_id', Party::where('name', $name)->value('id'))->sole();
            $rows[$status] = [$r->type, (float) $r->amount, $r->description, $r->due_date?->toDateString(), $r->project_id, $r->payment_type];
        }

        $this->assertSame($rows[Understanding::DONE], $rows[Understanding::NO_WORK]);
        $this->assertStringStartsWith('Borç kaydı', (string) $rows[Understanding::DONE][2]);
    }

    public function test_old_debt_without_repayment_date_is_still_asked(): void
    {
        $this->chat($this->debtNote(Understanding::OLD_DEBT, true, ['due_date' => null, 'party_name' => 'Zxq Usta', 'description' => '']));

        $this->assertStringContainsString('neden?', $this->send('ustaya 115.000 borcum var'));
    }

    public function test_partial_work_is_still_asked(): void
    {
        $this->chat($this->debtNote(Understanding::PARTIAL, true, ['party_name' => 'Zxq Kısmi', 'description' => 'Sıva']));

        $this->assertStringContainsString('❓', $this->send('Kısmi ustaya 2milyon 100bin borcum var işin yarısını yaptı'));
    }

    public function test_agreement_writes_no_debt(): void
    {
        $this->chat($this->debtNote(Understanding::AGREEMENT, true, ['party_name' => 'Zxq Tesisatçı', 'description' => 'Tesisat']));
        $this->send('Zxq tesisatçı ile tesisatı 2milyon 100bine anlaştık, işe başlamadı');
        $this->send('evet');

        $p = Party::where('name', 'Zxq Tesisatçı')->first();
        $this->assertSame(0, $p ? PartyLedgerEntry::where('party_id', $p->id)->count() : 0, 'anlaşma borç kaydı değildir');
    }

    public function test_debt_with_unclear_side_still_asks(): void
    {
        $this->chat($this->debtNote(Understanding::OLD_DEBT, true, ['debt_side' => 'unclear', 'party_name' => 'Zxq Belirsiz']));

        $this->assertStringContainsString('o bana', $this->send('Belirsiz kişi 2milyon 100bin borç'));
    }
}
