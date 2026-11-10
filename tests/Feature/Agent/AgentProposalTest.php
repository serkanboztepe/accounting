<?php

namespace Tests\Feature\Agent;

use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Models\WhatsappPendingExpense;
use App\Services\Agent\Ops\AgentProposal;
use App\Support\ModuleProfiles;
use App\Support\PartyStatement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Ajan önerisi → kart → çift anahtarlı onay → kayıt (plan v5 Aşama C). Ana örnek Kaya İnşaat 08.11: Gürbüz Toprak'a 742.000 eski borç,
 * 120.000 ödeme, kalan 622.000 — eski yol 39 mesajda 3 yanlış kayıt yazmıştı.
 */
class AgentProposalTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905558880042';

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('muteahhit', ['smart_assistant' => true]), 'app.profile' => 'muteahhit']);
        $this->travelTo(now('Europe/Istanbul')->setDate(2026, 11, 9)->setTime(12, 0)->setTimezone(config('app.timezone')));
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
    }

    private function rec(array $entry): array
    {
        return ['type' => 'record', 'entry' => $entry];
    }

    private function propose(array ...$ops): array
    {
        return AgentProposal::propose(self::PHONE, $ops, 'test mesajı');
    }

    /** Öner + kart gönderildi say + onayla. */
    private function save(string $raw, array ...$ops): array
    {
        $p = $this->propose(...$ops);
        $this->assertTrue($p['ok'], json_encode($p, JSON_UNESCAPED_UNICODE));
        AgentProposal::markSent($p['proposal_id']);

        return AgentProposal::confirm(self::PHONE, $p['proposal_id'], $raw) + ['card' => $p['card']];
    }

    private function balance(Party $party): float
    {
        return round((float) PartyStatement::build($party)['balance'], 2);
    }

    private function gurbuz(): Party
    {
        $party = Party::create(['name' => 'Gürbüz Toprak']);
        PartyLedgerEntry::create(['party_id' => $party->id, 'type' => 'alis', 'amount' => 742000, 'entry_date' => '2026-11-01', 'description' => 'Borç kaydı — eski borç']);

        return $party;
    }

    public function test_kaya_case_old_debt_and_payment_in_one_card_nothing_written_until_confirmed(): void
    {
        $before = [Party::count(), PartyLedgerEntry::count(), Expense::count()];

        $p = $this->propose(
            $this->rec(['kind' => 'debt_note', 'party_name' => 'Gürbüz Toprak', 'new_party' => true, 'amount' => 742000, 'debt_side' => 'payable', 'description' => 'eski borç']),
            $this->rec(['kind' => 'payment', 'party_name' => 'Gürbüz Toprak', 'amount' => 120000, 'purpose' => 'debt', 'payment_type' => 'cash']),
        );

        $this->assertTrue($p['ok'], json_encode($p, JSON_UNESCAPED_UNICODE));
        $this->assertSame($before, [Party::count(), PartyLedgerEntry::count(), Expense::count()], 'öneri hiçbir şey yazmaz');
        $this->assertStringContainsString('1. Eski borç (sen borçlusun) — Gürbüz Toprak (yeni kişi) *742.000,00 ₺*', $p['card']);
        $this->assertStringContainsString('2. Ödeme — Gürbüz Toprak', $p['card']);
        $this->assertStringContainsString('*120.000,00 ₺*', $p['card']);
        $this->assertStringContainsString('📒 Gürbüz Toprak: hesap kapalı → *borcun 622.000,00 ₺*', $p['card']);
        $this->assertStringNotContainsString('1.244.000', $p['card']);
        $this->assertSame('borcun 622.000,00 ₺', $p['effects']['bakiyeler'][0]['sonra']);
        $this->assertCount(2, $p['effects']['yazılacak_satırlar']);

        $proposal = WhatsappPendingExpense::find($p['proposal_id']);
        $this->assertSame(WhatsappPendingExpense::AGENT_OPEN, $proposal->status);
        $this->assertSame(0, WhatsappPendingExpense::legacyOpen(self::PHONE)->count(), 'eski yol görecek taslak kalmaz');

        // Kart gönderilmeden onay yok.
        $r = AgentProposal::confirm(self::PHONE, $p['proposal_id'], '742000');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('gösterilmedi', $r['reason']);
        AgentProposal::markSent($p['proposal_id']);

        // 500.000 ve üstü: "evet" yetmez, tutar yazılır.
        $this->assertStringContainsString('Tutar büyük', $p['card']);
        $r = AgentProposal::confirm(self::PHONE, $p['proposal_id'], 'evet');
        $this->assertFalse($r['ok']);
        $this->assertSame($before[1], PartyLedgerEntry::count());

        $r = AgentProposal::confirm(self::PHONE, $p['proposal_id'], '742000');
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertStringContainsString('✅ Kaydedildi.', $r['text']);
        $this->assertStringContainsString('Gürbüz Toprak: borcun 622.000,00 ₺', $r['text']);

        $party = Party::where('name', 'Gürbüz Toprak')->sole();
        $rows = PartyLedgerEntry::where('party_id', $party->id)->orderBy('id')->get();
        $this->assertSame(['alis', 'odeme'], $rows->pluck('type')->all());
        $this->assertEquals([742000.0, 120000.0], $rows->pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->assertStringStartsWith('Borç kaydı', $rows[0]->description);
        $this->assertSame('cash', $rows[1]->payment_type);
        $this->assertEqualsWithDelta(-622000, $this->balance($party), 0.01);

        $proposal->refresh();
        $this->assertSame(WhatsappPendingExpense::AGENT_CONFIRMED, $proposal->status);
        $this->assertCount(2, $proposal->extracted['created_records']);
        // İkinci "onay" yeni kayıt açmaz.
        $this->assertFalse(AgentProposal::confirm(self::PHONE, $p['proposal_id'], '742000')['ok']);
        $this->assertSame(2, PartyLedgerEntry::where('party_id', $party->id)->count());
    }

    public function test_payment_fields_match_what_the_old_path_writes(): void
    {
        $party = $this->gurbuz();
        $project = Project::create(['name' => 'Öneri Testi Şantiye', 'status' => 'active']);

        $r = $this->save('evet', $this->rec(['kind' => 'payment', 'party_id' => $party->id, 'amount' => 120000, 'payment_type' => 'eft',
            'date' => '2026-11-08', 'description' => 'Kasım ödemesi', 'project_id' => $project->id]));
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertStringContainsString('08.11.2026', $r['card']);
        $this->assertStringContainsString('borcun 742.000,00 → *622.000,00 ₺*', $r['card']);

        $row = PartyLedgerEntry::where('party_id', $party->id)->where('type', 'odeme')->sole();
        $this->assertSame(['2026-11-08', 'eft', 'Kasım ödemesi', $project->id, 'WhatsApp üzerinden girildi.', PartyLedgerEntry::DIRECTION_DEBIT],
            [$row->entry_date->toDateString(), $row->payment_type, $row->description, $row->project_id, $row->notes, $row->direction]);
        // Çocuk taslak eski biçimde "confirmed" kalır: eski "son kaydı sil" ve "bu kayıt nereden çıktı" onu bulur.
        $child = WhatsappPendingExpense::where('phone', self::PHONE)->where('status', 'confirmed')->latest('id')->first();
        $this->assertSame([['ledger', $row->id]], $child->extracted['created_records']);
        $this->assertSame('payment', $child->extracted['kind']);
    }

    public function test_ambiguity_that_changes_the_balance_is_returned_as_an_issue_not_guessed(): void
    {
        $party = $this->gurbuz();
        $fresh = Party::create(['name' => 'Borçsuz Usta']);
        $rows = PartyLedgerEntry::count();

        // Borcu olmayan kişiye ödeme: amaç verilmeden yazılmaz.
        $p = $this->propose($this->rec(['kind' => 'payment', 'party_id' => $fresh->id, 'amount' => 25000]));
        $this->assertFalse($p['ok']);
        $this->assertStringContainsString('purpose', $p['issues'][0]);
        $p = $this->propose($this->rec(['kind' => 'payment', 'party_id' => $fresh->id, 'amount' => 25000, 'purpose' => 'advance']));
        $this->assertTrue($p['ok'], json_encode($p, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('Ödeme (avans) — Borçsuz Usta', $p['card']);
        $this->assertStringContainsString('hesap kapalı → *sana borcu 25.000,00 ₺*', $p['card']);

        // "Borç ödemesi" kayıtlı borcu aşıyorsa eksik borç sessizce yazılmaz.
        $p = $this->propose($this->rec(['kind' => 'payment', 'party_id' => $party->id, 'amount' => 800000, 'purpose' => 'debt']));
        $this->assertFalse($p['ok']);
        $this->assertStringContainsString('58.000,00', $p['issues'][0]);

        // Alışta ödendi mi belli değil; borç kaydında yön yok; kişi yok.
        $this->assertStringContainsString('paid', $this->propose($this->rec(['kind' => 'purchase', 'party_id' => $party->id, 'amount' => 5000, 'no_project' => true]))['issues'][0]);
        $this->assertStringContainsString('debt_side', $this->propose($this->rec(['kind' => 'debt_note', 'party_id' => $party->id, 'amount' => 5000]))['issues'][0]);
        $this->assertStringContainsString('kişi verilmedi', $this->propose($this->rec(['kind' => 'payment', 'amount' => 5000]))['issues'][0]);
        $this->assertStringContainsString('Tutar', $this->propose($this->rec(['kind' => 'payment', 'party_id' => $party->id]))['issues'][0]);
        $this->assertStringContainsString('Gider kişiye bağlanmaz', $this->propose($this->rec(['kind' => 'expense', 'party_id' => $party->id, 'amount' => 100]))['issues'][0]);

        // Benzer adlı kişi varken yeni kişi sessizce açılmaz.
        $p = $this->propose($this->rec(['kind' => 'payment', 'party_name' => 'Gürbüz', 'amount' => 1000, 'purpose' => 'advance']));
        $this->assertFalse($p['ok']);
        $this->assertStringContainsString('Gürbüz Toprak (id ' . $party->id . ')', $p['issues'][0]);
        $this->assertTrue($this->propose($this->rec(['kind' => 'payment', 'party_name' => 'Gürbüz', 'new_party' => true, 'amount' => 1000, 'purpose' => 'advance']))['ok']);

        $this->assertSame($rows, PartyLedgerEntry::count(), 'hiçbiri yazmadı');
        $this->assertNull(Party::where('name', 'Gürbüz')->first(), 'yeni kişi öneride açılmaz');
    }

    /** Yıldız 02.11: "Emrah Acar'a 400.000 verdim" → eski yol "ne için?" sorusunu 4 kez sordu; doğrusu müşteri adına ödeme. */
    public function test_payment_on_behalf_of_a_customer_goes_to_their_account(): void
    {
        $customer = Party::create(['name' => 'Adına Ödeme Müşterisi']);
        PartyLedgerEntry::create(['party_id' => $customer->id, 'type' => 'satis', 'amount' => 100000, 'entry_date' => '2026-11-01']);

        $r = $this->save('evet', $this->rec(['kind' => 'payment', 'party_id' => $customer->id, 'amount' => 8500, 'purpose' => 'on_behalf', 'description' => 'Tapu harcı']));
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertStringContainsString('Onun adına ödedin — Adına Ödeme Müşterisi *8.500,00 ₺*', $r['card']);
        $this->assertStringContainsString('sana borcu 100.000,00 → *108.500,00 ₺*', $r['card']);
        $row = PartyLedgerEntry::where('party_id', $customer->id)->where('type', 'odeme')->sole();
        $this->assertSame('Adına ödendi — Tapu harcı', $row->description);
        $this->assertSame(0, Expense::where('description', 'like', '%Tapu harcı%')->count(), 'gider yazılmaz');
    }

    public function test_cost_record_needs_a_place_and_never_guesses_it(): void
    {
        $party = $this->gurbuz();
        $project = Project::create(['name' => 'Öneri Testi Villa', 'status' => 'active']);

        $p = $this->propose($this->rec(['kind' => 'purchase', 'party_id' => $party->id, 'amount' => 30000, 'paid' => false, 'description' => 'Demir']));
        $this->assertFalse($p['ok']);
        $this->assertStringContainsString('Öneri Testi Villa (id ' . $project->id . ')', $p['issues'][0]);
        $this->assertStringContainsString('TAHMİN ETME', $p['issues'][0]);

        $r = $this->save('tamam', $this->rec(['kind' => 'purchase', 'party_id' => $party->id, 'amount' => 30000, 'paid' => false, 'description' => 'Demir', 'project_id' => $project->id]));
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertStringContainsString('Alış (veresiye) — Gürbüz Toprak *30.000,00 ₺* · Demir · Öneri Testi Villa', $r['card']);
        $this->assertEqualsWithDelta(-772000, $this->balance($party), 0.01);

        $r = $this->save('evet', $this->rec(['kind' => 'expense', 'amount' => 4500, 'description' => 'Mazot', 'no_project' => true]));
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $expense = Expense::latest('id')->first();
        $this->assertSame(['Mazot', null, 'paid'], [$expense->description, $expense->project_id, $expense->payment_status]);
    }

    public function test_duplicate_payment_is_caught_before_the_card(): void
    {
        $party = $this->gurbuz();
        $this->assertTrue($this->save('evet', $this->rec(['kind' => 'payment', 'party_id' => $party->id, 'amount' => 120000]))['ok']);

        $p = $this->propose($this->rec(['kind' => 'payment', 'party_id' => $party->id, 'amount' => 120000]));
        $this->assertFalse($p['ok']);
        $this->assertStringContainsString('zaten var', $p['issues'][0]);
        $this->assertStringContainsString('allow_duplicate', $p['issues'][0]);

        $this->assertTrue($this->save('evet', $this->rec(['kind' => 'payment', 'party_id' => $party->id, 'amount' => 120000, 'allow_duplicate' => true]))['ok']);
        $this->assertEqualsWithDelta(-502000, $this->balance($party), 0.01);
    }

    public function test_confirmation_needs_the_customers_own_yes_and_no_new_number(): void
    {
        $party = $this->gurbuz();
        $p = $this->propose($this->rec(['kind' => 'payment', 'party_id' => $party->id, 'amount' => 120000]));
        AgentProposal::markSent($p['proposal_id']);

        foreach (['742 -120 olacak' => 'onay yok', 'neden böyle' => 'onay yok', 'evet ama 620 kalacak' => 'kartta olmayan bir rakam',
            'evet 130 bin yaz' => 'kartta olmayan bir rakam', 'hayır yanlış' => 'onay yok', 'evet değil iptal' => 'vazgeçme'] as $raw => $why) {
            $r = AgentProposal::confirm(self::PHONE, $p['proposal_id'], $raw);
            $this->assertFalse($r['ok'], $raw);
            $this->assertStringContainsString($why, $r['reason'], $raw);
        }
        $this->assertSame(1, PartyLedgerEntry::where('party_id', $party->id)->count(), 'hiçbiri yazmadı');
        $this->assertSame(WhatsappPendingExpense::AGENT_OPEN, WhatsappPendingExpense::find($p['proposal_id'])->status, 'öneri duruyor');

        // Karttaki rakamı tekrar etmek serbest ("evet 622 kaldı").
        $this->assertTrue(AgentProposal::confirm(self::PHONE, $p['proposal_id'], 'Evet 622 kaldı doğru')['ok']);
        $this->assertEqualsWithDelta(-622000, $this->balance($party), 0.01);
    }

    public function test_stale_card_is_not_committed(): void
    {
        $party = $this->gurbuz();
        $p = $this->propose($this->rec(['kind' => 'payment', 'party_id' => $party->id, 'amount' => 120000]));
        AgentProposal::markSent($p['proposal_id']);

        // Kart beklerken panelden başka bir kayıt girildi → karttaki "622.000 kalır" artık doğru değil.
        PartyLedgerEntry::create(['party_id' => $party->id, 'type' => 'odeme', 'amount' => 50000, 'entry_date' => '2026-11-09']);

        $r = AgentProposal::confirm(self::PHONE, $p['proposal_id'], 'evet');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('bayat', $r['reason']);
        $this->assertSame(WhatsappPendingExpense::AGENT_SUPERSEDED, WhatsappPendingExpense::find($p['proposal_id'])->status);
        $this->assertEqualsWithDelta(-692000, $this->balance($party), 0.01);
    }

    public function test_all_or_nothing_when_a_later_operation_fails_at_commit(): void
    {
        $party = $this->gurbuz();
        $p = $this->propose(
            $this->rec(['kind' => 'payment', 'party_id' => $party->id, 'amount' => 100000]),
            $this->rec(['kind' => 'expense', 'amount' => 900, 'description' => 'Öneri testi patlayan gider', 'no_project' => true]),
        );
        $this->assertTrue($p['ok'], json_encode($p, JSON_UNESCAPED_UNICODE));
        AgentProposal::markSent($p['proposal_id']);
        Expense::creating(function (Expense $e) {
            if ($e->description === 'Öneri testi patlayan gider') {
                throw new \RuntimeException('test: gider yazılamadı');
            }
        });

        $r = AgentProposal::confirm(self::PHONE, $p['proposal_id'], 'evet');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('hiçbir şey yazılmadı', $r['reason']);
        $this->assertSame(1, PartyLedgerEntry::where('party_id', $party->id)->count(), 'ilk işlem de geri alındı');
        $this->assertSame(WhatsappPendingExpense::AGENT_OPEN, WhatsappPendingExpense::find($p['proposal_id'])->status);
    }

    public function test_delete_shows_every_row_and_three_or_more_need_the_word_sil(): void
    {
        $party = $this->gurbuz();
        $ids = [];
        foreach ([120000, 120000, 1244000] as $amount) {
            $ids[] = PartyLedgerEntry::create(['party_id' => $party->id, 'type' => 'odeme', 'amount' => $amount, 'entry_date' => '2026-11-08'])->id;
        }

        // Tek kayıt: "evet" yeter.
        $r = $this->save('evet', ['type' => 'delete', 'refs' => ['ledger:' . $ids[2]]]);
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertStringContainsString('🗑️ Sil: 08.11 Ödeme 1.244.000,00 ₺ — Gürbüz Toprak', $r['card']);
        $this->assertStringContainsString('🗑️ Silindi.', $r['text']);
        $this->assertNull(PartyLedgerEntry::find($ids[2]));

        // Üç kayıt: her satır kartta, "evet" yetmez, "sil" gerekir.
        $extra = PartyLedgerEntry::create(['party_id' => $party->id, 'type' => 'odeme', 'amount' => 5000, 'entry_date' => '2026-11-09'])->id;
        $p = $this->propose(['type' => 'delete', 'refs' => ['ledger:' . $ids[0], 'ledger:' . $ids[1], 'ledger:' . $extra]]);
        $this->assertSame(3, substr_count($p['card'], '🗑️ Sil:'));
        $this->assertStringContainsString('3 kayıt silinecek. Onay için *sil* yaz', $p['card']);
        $this->assertStringContainsString('→ *742.000,00 ₺*', $p['card']);
        AgentProposal::markSent($p['proposal_id']);
        $this->assertFalse(AgentProposal::confirm(self::PHONE, $p['proposal_id'], 'evet')['ok']);
        $this->assertSame(4, PartyLedgerEntry::where('party_id', $party->id)->count());
        $this->assertTrue(AgentProposal::confirm(self::PHONE, $p['proposal_id'], 'sil')['ok']);
        $this->assertEqualsWithDelta(-742000, $this->balance($party), 0.01);

        // Olmayan / bozuk ref ve kart başı sınır.
        $this->assertStringContainsString('bulunamadı', $this->propose(['type' => 'delete', 'refs' => ['ledger:' . $ids[0]]])['issues'][0]);
        $this->assertStringContainsString('geçerli bir ref değil', $this->propose(['type' => 'delete', 'refs' => ['users:1']])['issues'][0]);
        config(['agent.delete_per_card' => 1]);
        $a = PartyLedgerEntry::create(['party_id' => $party->id, 'type' => 'odeme', 'amount' => 10, 'entry_date' => '2026-11-09'])->id;
        $b = PartyLedgerEntry::create(['party_id' => $party->id, 'type' => 'odeme', 'amount' => 20, 'entry_date' => '2026-11-09'])->id;
        $this->assertStringContainsString('en çok 1 kayıt', $this->propose(['type' => 'delete', 'refs' => ["ledger:$a", "ledger:$b"]])['issues'][0]);
        $this->assertNotNull(PartyLedgerEntry::find($a), 'sınır aşıldıysa deneme de geri alınır');
    }

    public function test_edit_replaces_the_record_in_one_step(): void
    {
        $party = $this->gurbuz();
        $wrong = PartyLedgerEntry::create(['party_id' => $party->id, 'type' => 'odeme', 'amount' => 210000, 'entry_date' => '2026-11-08', 'description' => 'Ödeme']);

        $r = $this->save('evet', ['type' => 'edit', 'ref' => 'ledger:' . $wrong->id,
            'entry' => ['kind' => 'payment', 'party_id' => $party->id, 'amount' => 120000, 'date' => '2026-11-08']]);
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertStringContainsString('✏️ Düzelt: 08.11 Ödeme 210.000,00 ₺ — Gürbüz Toprak (Ödeme) → Ödeme — Gürbüz Toprak *120.000,00 ₺*', $r['card']);
        $this->assertStringContainsString('borcun 532.000,00 → *622.000,00 ₺*', $r['card']);
        $this->assertNull(PartyLedgerEntry::find($wrong->id));
        $this->assertEqualsWithDelta(-622000, $this->balance($party), 0.01);
    }

    public function test_target_balance_compares_with_the_books_and_asks_why_before_writing(): void
    {
        $party = $this->gurbuz();
        PartyLedgerEntry::create(['party_id' => $party->id, 'type' => 'odeme', 'amount' => 120000, 'entry_date' => '2026-11-08']);
        $target = fn (array $o) => ['type' => 'target_balance', 'party_id' => $party->id] + $o;

        // Aynı: yazılacak bir şey yok ("622.000 kaldı" yeni borç DEĞİL).
        $p = $this->propose($target(['side' => 'we_owe', 'amount' => 622000]));
        $this->assertFalse($p['ok']);
        $this->assertStringContainsString('zaten böyle: borcun 622.000,00 ₺', $p['noop']);
        $this->assertSame(0, WhatsappPendingExpense::agentOpen(self::PHONE)->count());

        // Fark var, sebep bilinmiyor: önce sor.
        $p = $this->propose($target(['side' => 'we_owe', 'amount' => 600000]));
        $this->assertFalse($p['ok']);
        $this->assertStringContainsString('fark 22.000,00 ₺', $p['issues'][0]);
        $this->assertStringContainsString('emin değilim, dokunma', $p['issues'][0]);

        // Sebep: yazılmamış ödeme → fark kadar ödeme.
        $p = $this->propose($target(['side' => 'we_owe', 'amount' => 600000, 'reason' => 'payment']));
        $this->assertTrue($p['ok'], json_encode($p, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('Ödeme — Gürbüz Toprak *22.000,00 ₺*', $p['card']);
        $this->assertStringContainsString('borcun 622.000,00 → *600.000,00 ₺*', $p['card']);

        // Sebep: eski borç farklıydı → tek "Borç kaydı" satırı düzeltilir (yeni "düzeltme" satırı açılmaz).
        $r = $this->save('760.000', $target(['side' => 'we_owe', 'amount' => 640000, 'reason' => 'old_debt']));
        $this->assertTrue($r['ok'], ($r['reason'] ?? '') . $r['card']);
        $this->assertStringContainsString('✏️ Düzelt: 01.11 Borç kaydı 742.000,00 ₺ — Gürbüz Toprak →', $r['card']);
        $this->assertStringContainsString('*760.000,00 ₺*', $r['card']);
        $this->assertEqualsWithDelta(-640000, $this->balance($party), 0.01);
        $this->assertSame(1, PartyLedgerEntry::where('party_id', $party->id)->where('description', 'like', 'Borç kaydı%')->count());

        // Hiç kaydı olmayan kişi: söylenen rakam eski borcun kendisi.
        $new = Party::create(['name' => 'Kayıtsız Tedarikçi']);
        $r = $this->save('evet', ['type' => 'target_balance', 'party_id' => $new->id, 'side' => 'we_owe', 'amount' => 15000]);
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertStringContainsString('Eski borç (sen borçlusun) — Kayıtsız Tedarikçi *15.000,00 ₺*', $r['card']);
        $this->assertEqualsWithDelta(-15000, $this->balance($new), 0.01);
    }

    public function test_cancel_needs_the_customers_own_word_and_new_proposal_replaces_the_old(): void
    {
        $party = $this->gurbuz();
        $first = $this->propose($this->rec(['kind' => 'payment', 'party_id' => $party->id, 'amount' => 1000]));
        $second = $this->propose($this->rec(['kind' => 'payment', 'party_id' => $party->id, 'amount' => 2000]));
        $this->assertSame(WhatsappPendingExpense::AGENT_SUPERSEDED, WhatsappPendingExpense::find($first['proposal_id'])->status);
        $this->assertSame($second['proposal_id'], AgentProposal::open(self::PHONE)->id);

        $this->assertFalse(AgentProposal::cancel(self::PHONE, $second['proposal_id'], 'bir dakika')['ok']);
        $this->assertTrue(AgentProposal::cancel(self::PHONE, $second['proposal_id'], 'iptal')['ok']);
        $this->assertNull(AgentProposal::open(self::PHONE));
        $this->assertSame(1, PartyLedgerEntry::where('party_id', $party->id)->count());

        // Süresi dolan öneri onaylanamaz.
        $old = $this->propose($this->rec(['kind' => 'payment', 'party_id' => $party->id, 'amount' => 3000]));
        AgentProposal::markSent($old['proposal_id']);
        $this->travel(61)->minutes();
        $this->assertFalse(AgentProposal::confirm(self::PHONE, $old['proposal_id'], 'evet')['ok']);
    }

    public function test_bad_input_is_an_issue_not_an_exception(): void
    {
        $this->assertFalse($this->propose()['ok']);
        $this->assertStringContainsString('type', $this->propose(['type' => 'sql', 'x' => 1])['issues'][0]);
        $this->assertStringContainsString('kind', $this->propose($this->rec(['kind' => 'agreement', 'amount' => 5]))['issues'][0]);
        $this->assertStringContainsString('açık değil', $this->propose($this->rec(['kind' => 'trade_sale', 'amount' => 5]))['issues'][0], 'stoksuz işletmede ürünlü satış');
        $this->assertStringContainsString('date', $this->propose($this->rec(['kind' => 'expense', 'amount' => 5, 'date' => '9 Kasım']))['issues'][0]);
        $this->assertStringContainsString('kayıtlı değil', $this->propose($this->rec(['kind' => 'payment', 'amount' => 5, 'party_id' => 999999999]))['issues'][0]);
        $this->assertStringContainsString('2. işlem', $this->propose($this->rec(['kind' => 'expense', 'amount' => 5, 'no_project' => true]), $this->rec(['kind' => 'expense']))['issues'][0]);
        config(['agent.ops_per_proposal' => 1]);
        $this->assertStringContainsString('en çok 1', $this->propose($this->rec(['kind' => 'expense', 'amount' => 5]), $this->rec(['kind' => 'expense', 'amount' => 6]))['issues'][0]);
    }
}
