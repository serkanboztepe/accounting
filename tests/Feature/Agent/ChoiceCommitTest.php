<?php

namespace Tests\Feature\Agent;

use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;
use App\Services\Agent\AgentToolbox;
use App\Services\Agent\Ops\AgentProposal;
use App\Services\Whatsapp\DraftFollowUp;
use App\Services\Whatsapp\DraftWarnings;
use App\Support\ModuleProfiles;
use App\Support\Phone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 20.11 "müşteri onay verdiğini sanıyor ama kayıt yazılmıyor": numaralı seçim = onay (ChoiceCommit), süresi dolan kartın aynısı
 * yeniden önerilince onay aynı turda, yarım kayıt hatırlatması ajan kartını da görür, gider çift-kayıt uyarısı yalnız aynı gün.
 * Model yok: ajanın araç çağrıları doğrudan AgentToolbox'a verilir.
 */
class ChoiceCommitTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905558880077';

    private Project $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('muteahhit', ['smart_assistant' => true, 'agent_live' => true]), 'app.profile' => 'muteahhit']);
        $this->travelTo(now('Europe/Istanbul')->setDate(2026, 11, 19)->setTime(11, 0)->setTimezone(config('app.timezone')));
        Cache::flush();
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        $this->site = Project::create(['name' => 'Yeşilyurt Test', 'status' => 'active']);
    }

    private function out(string $body): void
    {
        WhatsappMessage::create(['phone' => Phone::normalize(self::PHONE), 'direction' => 'out', 'body' => $body]);
    }

    private function in(string $body): void
    {
        WhatsappMessage::create(['phone' => Phone::normalize(self::PHONE), 'direction' => 'in', 'body' => $body]);
    }

    private function expense(float $amount, array $extra = []): array
    {
        return ['type' => 'record', 'entry' => ['kind' => 'expense', 'amount' => $amount, 'description' => 'Kepçe ödemesi', 'paid' => true,
            'project_id' => $this->site->id] + $extra];
    }

    /** Ali 11.11: "9.000 kepçe hangi projeye? 3" → kart yok, kayıt yazılır. */
    public function test_choosing_a_numbered_option_saves_without_a_second_card(): void
    {
        $this->in('Kepçe ödemesi 9000');
        $this->out("❓ 9.000 ₺ kepçe ödemesi hangi projeye yazılsın?\n*1* Yeşilyurt Test\n*2* Genel");
        $this->in('1');
        $before = Expense::where('amount', 9000)->count();

        $box = new AgentToolbox(self::PHONE, '1');
        $r = $box->run('propose', ['ops' => [$this->expense(9000)]]);

        $this->assertTrue($r['kaydedildi'] ?? false, json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame($before + 1, Expense::where('amount', 9000)->count());
        $this->assertNull($box->outcome['card'], 'kart gönderilmez');
        $this->assertStringStartsWith('✅ Kaydedildi: Gider *9.000,00 ₺*', $box->outcome['confirmed']);
        $this->assertStringContainsString('Yanlışsa *geri al* yaz.', $box->outcome['confirmed']);
        $this->assertStringNotContainsString('Kaydedeyim mi', $box->outcome['confirmed']);
        $this->assertTrue($box->finished(), 'tur biter');
    }

    /** Tok 19.11: "Engin'in ekskavatörü … 7.500 mi yazayım? 1" → kişili kayıt da doğrudan yazılır. */
    public function test_choice_with_an_existing_person_named_in_the_question(): void
    {
        $engin = Party::create(['name' => 'Engin Doğan Test']);
        $this->out("❓ Engin Doğan Test'in ekskavatörü dün yarım gün çalıştı. 7.500 ₺ mi yazayım?\n*1* Evet, 7.500 ₺\n*2* Hayır, tutarı yazacağım");
        $this->in('1');

        $box = new AgentToolbox(self::PHONE, '1');
        $r = $box->run('propose', ['ops' => [['type' => 'record', 'entry' => ['kind' => 'purchase', 'party_id' => $engin->id, 'amount' => 7500,
            'description' => 'Ekskavatör yarım gün', 'paid' => false, 'project_id' => $this->site->id]]]]);

        $this->assertTrue($r['kaydedildi'] ?? false, json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, PartyLedgerEntry::where('party_id', $engin->id)->count());
        $this->assertStringContainsString('📒 Engin Doğan Test', $box->outcome['confirmed'], 'güncel bakiye yazılır');
    }

    /** Kart gösterilmesi gereken durumlar: tutar soruda yok · cevap seçenek değil · yeni kişi · önceki mesaj soru değil. */
    public function test_card_is_still_shown_when_the_customer_did_not_see_the_record(): void
    {
        $cases = [
            'tutar soruda yok' => ["❓ Hangi projeye yazılsın?\n*1* Yeşilyurt Test\n*2* Genel", '1', [$this->expense(9000)]],
            'cevap seçenek değil' => ["❓ 9.000 ₺ kepçe hangi projeye?\n*1* Yeşilyurt Test\n*2* Genel", 'yeşilyurt olsun ama yarın', [$this->expense(9000)]],
            'seçenek dışı numara' => ["❓ 9.000 ₺ kepçe hangi projeye?\n*1* Yeşilyurt Test\n*2* Genel", '5', [$this->expense(9000)]],
            'önceki mesaj soru değil' => ['Tamam 👍 Başka bir şey olursa yaz.', '1', [$this->expense(9000)]],
            'yeni kişi' => ["❓ Kemal Yenikişi'ye 9.000 ₺ hangi projeye?\n*1* Yeşilyurt Test\n*2* Genel", '1',
                [['type' => 'record', 'entry' => ['kind' => 'purchase', 'party_name' => 'Kemal Yenikişi', 'amount' => 9000, 'paid' => false, 'project_id' => $this->site->id]]]],
        ];
        foreach ($cases as $label => [$question, $answer, $ops]) {
            WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
            $this->out($question);
            $this->in($answer);
            $box = new AgentToolbox(self::PHONE, $answer);
            $r = $box->run('propose', ['ops' => $ops]);

            $this->assertTrue($r['kabul'] ?? false, $label . ': ' . json_encode($r, JSON_UNESCAPED_UNICODE));
            $this->assertArrayNotHasKey('kaydedildi', $r, $label);
            $this->assertNotNull($box->outcome['card'], $label . ': kart gider');
            $this->assertNull($box->outcome['confirmed'], $label);
        }
    }

    /** Ali 11.11 17:18: kart 66. dakikada "Evet" ile cevaplandı → aynı kart yeniden önerilince tekrar gönderilmez, onay bu turda geçer. */
    public function test_same_card_proposed_again_after_expiry_is_confirmed_in_the_same_turn(): void
    {
        $this->in('Kepçe ödemesi 9000');
        $first = new AgentToolbox(self::PHONE, 'Kepçe ödemesi 9000');
        $p = $first->run('propose', ['ops' => [$this->expense(9000)]]);
        AgentProposal::markSent((int) $p['proposal_id']);
        $this->travel(66)->minutes();
        $this->assertNull(AgentProposal::open(self::PHONE), '60 dk penceresi kapandı');

        $this->in('Evet');
        $box = new AgentToolbox(self::PHONE, 'Evet');
        $again = $box->run('propose', ['ops' => [$this->expense(9000, ['date' => now('Europe/Istanbul')->toDateString()])]]);
        $this->assertTrue($again['zaten_gösterildi'] ?? false, json_encode($again, JSON_UNESCAPED_UNICODE));
        $this->assertSame($p['proposal_id'], $again['proposal_id'], 'yeni kart açılmaz');
        $this->assertNull($box->outcome['card'], 'kart tekrar gönderilmez');

        $c = $box->run('confirm', ['proposal_id' => $again['proposal_id']]);
        $this->assertTrue($c['kaydedildi'] ?? false, json_encode($c, JSON_UNESCAPED_UNICODE));
    }

    /** Ajan kartı müşteriye gittiyse 30 dk sessizlikte yarım kayıt diye hatırlatılır; gitmediyse hatırlatılmaz. */
    public function test_follow_up_reminds_an_unconfirmed_agent_card(): void
    {
        $this->in('Kepçe ödemesi 9000');
        $box = new AgentToolbox(self::PHONE, 'Kepçe ödemesi 9000');
        $p = $box->run('propose', ['ops' => [$this->expense(9000)]]);
        $this->travel(31)->minutes();
        $this->assertNotContains($p['proposal_id'], array_map(fn ($d) => $d->id, DraftFollowUp::dueForMessage()), 'gönderilmemiş kart hatırlatılmaz');

        AgentProposal::markSent((int) $p['proposal_id']);
        $this->travel(31)->minutes();
        $due = collect(DraftFollowUp::dueForMessage())->firstWhere('id', $p['proposal_id']);
        $this->assertNotNull($due, 'gösterilmiş ajan kartı hatırlatılır');
        $text = DraftFollowUp::messageText($due);
        $this->assertStringContainsString('Gider *9.000,00 ₺*', $text);
        $this->assertStringContainsString('*evet*', $text);
        $this->assertStringNotContainsString('Kaydedeyim mi', $text);

        DraftFollowUp::markSent($due);
        $this->assertNotNull(AgentProposal::open(self::PHONE), 'hatırlatma kartı yeniden açar: sonraki "evet" bulur');
    }

    /** Gider çift kayıt uyarısı yalnız aynı gün (Akkuş her akşam 1.300, Muhasebe manito ↔ toprak nakliyesi). */
    public function test_expense_duplicate_warning_only_on_the_same_day(): void
    {
        $today = now('Europe/Istanbul')->toDateString();
        Expense::create(['expense_date' => now('Europe/Istanbul')->subDay()->toDateString(), 'amount' => 1317, 'payment_status' => 'paid', 'description' => 'Şube gideri']);
        $this->assertSame([], array_values(array_filter(DraftWarnings::for(['kind' => 'expense', 'amount' => 1317, 'date' => $today]),
            fn ($w) => str_contains($w, 'iki kez'))), 'dünkü aynı tutar uyarı vermez');

        Expense::create(['expense_date' => $today, 'amount' => 1317, 'payment_status' => 'paid', 'description' => 'Şube gideri']);
        $this->assertNotEmpty(array_filter(DraftWarnings::for(['kind' => 'expense', 'amount' => 1317, 'date' => $today]),
            fn ($w) => str_contains($w, 'iki kez')), 'aynı gün aynı tutar uyarır');
    }
}
