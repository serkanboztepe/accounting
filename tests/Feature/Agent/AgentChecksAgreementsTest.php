<?php

namespace Tests\Feature\Agent;

use App\Models\Agreement;
use App\Models\Check;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\WhatsappPendingExpense;
use App\Services\Agent\AgentToolbox;
use App\Services\Agent\AgentTurn;
use App\Services\Agent\Ops\AgentProposal;
use App\Services\Agent\Tools\Report;
use App\Support\ModuleProfiles;
use App\Support\PartyStatement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Çek, anlaşma, teslim, iade, ekstre — ajan yolunda (10.11 "hepsi yeni ajanda olsun"). Kayıt kuralları eski sınıflardan
 * (IssuedChecks, CheckSplit, AgreementDraft, AgreementDeliveryDraft, ReturnDraft); burada ajan önerisiyle uçtan uca doğrulanır.
 */
class AgentChecksAgreementsTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905558880045';

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('muteahhit', ['smart_assistant' => true, 'agreements' => true]), 'app.profile' => 'muteahhit',
            'app.agreement_direction' => 'purchase']);
        $this->travelTo(now('Europe/Istanbul')->setDate(2026, 11, 10)->setTime(12, 0)->setTimezone(config('app.timezone')));
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
    }

    private function propose(array ...$ops): array
    {
        return AgentProposal::propose(self::PHONE, $ops, 'test');
    }

    private function save(string $raw, array ...$ops): array
    {
        $p = $this->propose(...$ops);
        $this->assertTrue($p['ok'], json_encode($p, JSON_UNESCAPED_UNICODE));
        AgentProposal::markSent($p['proposal_id']);

        return AgentProposal::confirm(self::PHONE, $p['proposal_id'], $raw) + ['card' => $p['card']];
    }

    private function rec(array $e): array
    {
        return ['type' => 'record', 'entry' => $e];
    }

    private function supplier(float $debt = 200000): Party
    {
        $party = Party::create(['name' => 'Çek Testi Demirci']);
        PartyLedgerEntry::create(['party_id' => $party->id, 'type' => 'alis', 'amount' => $debt, 'entry_date' => '2026-11-01', 'description' => 'Demir']);

        return $party;
    }

    /** "Demirci Ali'ye 50 bin çek verdim, vadesi 15 Aralık" — borç bugün düşer, çek vadesiyle kaydedilir; soru yok. */
    public function test_payment_by_check_lowers_the_debt_now_and_stores_the_check(): void
    {
        $party = $this->supplier();
        $r = $this->save('evet', $this->rec(['kind' => 'payment', 'party_id' => $party->id, 'amount' => 50000,
            'checks' => [['number' => null, 'due_date_text' => '15.12.2026', 'amount' => 50000]]]));

        $this->assertTrue($r['ok'], ($r['reason'] ?? '') . $r['card']);
        $this->assertStringContainsString('borcun 200.000,00 → *150.000,00 ₺*', $r['card']);
        $this->assertStringContainsString('15.12.2026', $r['card']);
        $this->assertStringContainsString('çek', mb_strtolower($r['card']));
        $check = Check::where('party_id', $party->id)->sole();
        $this->assertSame(['issued', '2026-12-15', 50000.0], [$check->status, $check->due_date->toDateString(), (float) $check->amount]);
        $this->assertSame('check', PartyLedgerEntry::where('party_id', $party->id)->where('type', 'odeme')->value('payment_type'));
        $this->assertEqualsWithDelta(-150000, (float) PartyStatement::build($party)['balance'], 0.01);
    }

    public function test_check_plan_is_split_by_the_system_and_a_mismatch_is_an_issue(): void
    {
        $party = $this->supplier(300000);
        // Kural: 3 çek, aylık, Aralık'tan — bölmeyi kod yapar.
        $r = $this->save('evet', $this->rec(['kind' => 'payment', 'party_id' => $party->id, 'amount' => 90000,
            'check_plan' => ['count' => 3, 'first_month' => '2026-12', 'due_day' => 15, 'each_amount' => 30000]]));
        $this->assertTrue($r['ok'], ($r['reason'] ?? '') . $r['card']);
        $this->assertEquals([30000.0, 30000.0, 30000.0], Check::where('party_id', $party->id)->orderBy('due_date')->pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->assertSame(['2026-12-15', '2027-01-15', '2027-02-15'], Check::where('party_id', $party->id)->orderBy('due_date')->get()->map(fn ($c) => $c->due_date->toDateString())->all());

        // Çeklerin toplamı ödemeyle tutmuyor → kart yok, bulgu.
        $p = $this->propose($this->rec(['kind' => 'payment', 'party_id' => $party->id, 'amount' => 100000, 'allow_duplicate' => true,
            'checks' => [['due_date_text' => '15.03.2027', 'amount' => 40000], ['due_date_text' => '15.04.2027', 'amount' => 40000]]]));
        $this->assertFalse($p['ok']);
        $this->assertStringContainsString('80.000,00', $p['issues'][0]);
    }

    /** "Çek ödendi": yeni ödeme yazılmaz, çek işaretlenir, bakiye değişmez. */
    public function test_check_cleared_marks_the_check_without_a_new_payment(): void
    {
        $party = $this->supplier();
        $this->save('evet', $this->rec(['kind' => 'payment', 'party_id' => $party->id, 'amount' => 50000,
            'checks' => [['due_date_text' => '12.11.2026', 'amount' => 50000]]]));
        $check = Check::where('party_id', $party->id)->sole();
        $rows = PartyLedgerEntry::where('party_id', $party->id)->count();

        $open = Report::run(['kind' => 'open_checks']);
        $this->assertContains($check->id, array_column($open['liste'], 'id'));

        $r = $this->save('evet', ['type' => 'check_cleared', 'check_ids' => [$check->id]]);
        $this->assertTrue($r['ok'], ($r['reason'] ?? '') . $r['card']);
        $this->assertStringContainsString('ödendi', $r['card']);
        $this->assertStringContainsString('Çek Testi Demirci', $r['card']);
        $this->assertSame('paid', $check->fresh()->status);
        $this->assertSame($rows, PartyLedgerEntry::where('party_id', $party->id)->count(), 'yeni para kaydı yok');
        $this->assertEqualsWithDelta(-150000, (float) PartyStatement::build($party)['balance'], 0.01);

        $this->assertStringContainsString('zaten işaretlenmiş', $this->propose(['type' => 'check_cleared', 'check_ids' => [$check->id]])['issues'][0]);
        $this->assertStringContainsString('boş', $this->propose(['type' => 'check_cleared', 'check_ids' => []])['issues'][0]);
    }

    /** "Kuşak Beton'la 500 m³ betonu 3.800'den anlaştık" → anlaşma (borç değil); "40 m³ döktü" → teslim, borç doğar. */
    public function test_agreement_then_delivery(): void
    {
        $party = Party::create(['name' => 'Anlaşma Testi Beton']);

        $this->assertStringContainsStringIgnoringCase('fiyat', $this->propose($this->rec(['kind' => 'agreement', 'party_id' => $party->id, 'amount' => 0,
            'agreement_items' => [['description' => 'Beton', 'quantity' => 500, 'unit' => 'm³']]]))['issues'][0]);

        $r = $this->save('1900000', $this->rec(['kind' => 'agreement', 'party_id' => $party->id, 'amount' => 1900000, 'no_project' => true,
            'agreement_items' => [['description' => 'Beton', 'quantity' => 500, 'unit' => 'm³', 'unit_price' => 3800, 'amount' => 1900000]]]));
        $this->assertTrue($r['ok'], ($r['reason'] ?? '') . $r['card']);
        $this->assertStringContainsString('1.900.000,00', $r['card']);
        $agreement = Agreement::where('party_id', $party->id)->sole();
        $this->assertSame(Agreement::DIRECTION_PURCHASE, $agreement->direction);
        $this->assertEqualsWithDelta(0, (float) PartyStatement::build($party)['balance'], 0.01, 'anlaşma tek başına borç doğurmaz');

        $item = $agreement->items()->first();
        $r = $this->save('evet', $this->rec(['kind' => 'agreement_delivery', 'party_id' => $party->id, 'amount' => 0, 'no_project' => true,
            'delivery_lines' => [['agreement_item_id' => $item->id, 'quantity' => 40, 'description' => 'beton']]]));
        $this->assertTrue($r['ok'], ($r['reason'] ?? '') . $r['card']);
        $this->assertStringContainsString('152.000,00', $r['card']);
        $this->assertEqualsWithDelta(-152000, (float) PartyStatement::build($party)['balance'], 0.01, '40 × 3.800');
    }

    /** Satılan iş/mal geri geldi: asıl satış durur, yanına iade yazılır. */
    public function test_return_adds_a_return_row_next_to_the_original_sale(): void
    {
        $party = Party::create(['name' => 'İade Testi Müşteri']);
        PartyLedgerEntry::create(['party_id' => $party->id, 'type' => 'satis', 'amount' => 9000, 'entry_date' => '2026-11-05', 'description' => 'Yem satışı']);

        $r = $this->save('evet', ['type' => 'return', 'party' => 'İade Testi Müşteri', 'text' => 'yem', 'side' => 'sale', 'amount' => 3000]);
        $this->assertTrue($r['ok'], ($r['reason'] ?? '') . $r['card']);
        $this->assertStringContainsString('3.000,00', $r['card']);
        $this->assertSame(['satis', 'satis_iade'], PartyLedgerEntry::where('party_id', $party->id)->orderBy('id')->pluck('type')->all());
        $this->assertEqualsWithDelta(6000, (float) PartyStatement::build($party)['balance'], 0.01);

        $this->assertNotSame('', $this->propose(['type' => 'return', 'party' => 'Olmayan Kişi Xyz', 'side' => 'sale'])['issues'][0]);
    }

    public function test_statement_goes_out_as_a_pdf_link_with_the_balance_written_by_the_system(): void
    {
        $party = $this->supplier(80281.24);
        $toolbox = new AgentToolbox(self::PHONE, 'demirci ekstre');
        $this->assertTrue($toolbox->run('send_statement', ['party_id' => $party->id])['gönderildi']);
        $this->assertTrue($toolbox->finished());

        [$text, $url] = AgentTurn::replies('Buyur ekstre, borcun 99.999 ₺', $toolbox->outcome)[0];
        $this->assertSame("📄 Çek Testi Demirci — Hesap Dökümü\nÇek Testi Demirci: borcun 80.281,24 ₺", $text);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('.pdf', $url);
        $this->assertArrayHasKey('hata', (new AgentToolbox(self::PHONE, 'x'))->run('send_statement', ['party_id' => 999999999]));
    }
}
