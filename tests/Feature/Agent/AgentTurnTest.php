<?php

namespace Tests\Feature\Agent;

use App\Models\AssistantMemory;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;
use App\Services\Agent\AgentContext;
use App\Services\Agent\AgentLog;
use App\Services\Agent\AgentRunner;
use App\Services\Agent\AgentShadow;
use App\Services\Agent\AgentToolbox;
use App\Services\Agent\AgentTurn;
use App\Services\Agent\Ops\AgentProposal;
use App\Support\ModuleProfiles;
use App\Support\Phone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ajan döngüsü uçtan uca (plan v5 Aşama D) — model MOCK (ücretsiz): istek biçimi, araç döngüsü, kartı kodun göndermesi, onay,
 * hata/sınır davranışı, gölge. Modelin gerçekten doğru karar verip vermediği ücretli testlerde (Kapı 1) ölçülür.
 */
class AgentTurnTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905558880043';

    /** @var list<array<string, mixed>> modele giden istek gövdeleri */
    private array $sent = [];

    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logDir = sys_get_temp_dir() . '/wa-agent-test-' . uniqid();
        config(['modules' => ModuleProfiles::resolve('muteahhit', ['smart_assistant' => true, 'agent_live' => true]), 'app.profile' => 'muteahhit',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 888 00 43'],
            'services.twilio.sid' => 'ACtest', 'services.twilio.token' => 'tok', 'services.whatsapp.from' => '+14786665916',
            'services.anthropic.api_key' => 'agent-test-key', 'services.anthropic.chat_assistant' => false, 'services.whatsapp.agent' => 'on',
            'agent.log_path' => $this->logDir]);
        $this->travelTo(now('Europe/Istanbul')->setDate(2026, 11, 9)->setTime(12, 0)->setTimezone(config('app.timezone')));
        Cache::flush();
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        WhatsappMessage::create(['phone' => Phone::normalize(self::PHONE), 'direction' => 'in', 'body' => 'önceki mesaj']);
        WhatsappMessage::create(['phone' => Phone::normalize(self::PHONE), 'direction' => 'out', 'body' => 'önceki cevap']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->logDir);
        parent::tearDown();
    }

    /** Model cevaplarını sırayla döndür; Twilio gönderimleri başarılı. */
    private function model(array ...$responses): void
    {
        $this->sent = [];
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(function (Request $request) use (&$responses) {
            if (str_contains($request->url(), 'api.anthropic.com')) {
                $this->sent[] = ['headers' => $request->headers(), 'body' => $request->data()];
                $next = array_shift($responses);

                return $next === null ? Http::response(['error' => 'cevap kalmadı'], 500)
                    : (isset($next['__status']) ? Http::response($next['body'] ?? [], $next['__status']) : Http::response($next, 200));
            }

            return Http::response(['sid' => 'SMx'], 201);
        });
    }

    private function toolUse(array ...$calls): array
    {
        $content = [['type' => 'thinking', 'thinking' => '', 'signature' => 'imza-' . count($this->sent)]];
        foreach ($calls as $i => [$name, $input]) {
            $content[] = ['type' => 'tool_use', 'id' => 'toolu_' . $name . $i, 'name' => $name, 'input' => $input];
        }

        return ['model' => 'claude-fable-5-2', 'stop_reason' => 'tool_use', 'content' => $content,
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 200, 'cache_read_input_tokens' => 20000, 'cache_creation_input_tokens' => 0]];
    }

    private function say(string $text): array
    {
        return ['model' => 'claude-fable-5-2', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]],
            'usage' => ['input_tokens' => 500, 'output_tokens' => 50]];
    }

    private function send(string $body): string
    {
        return $this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0, 'MessageSid' => 'SM' . uniqid()])->assertOk()->getContent();
    }

    private function lastOut(): ?string
    {
        return WhatsappMessage::where('phone', Phone::normalize(self::PHONE))->where('direction', 'out')->latest('id')->value('body');
    }

    private function twilioBodies(): array
    {
        return collect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'api.twilio.com')))->map(fn ($pair) => $pair[0]['Body'] ?? null)->filter()->values()->all();
    }

    public function test_full_conversation_kaya_case_card_then_confirmation(): void
    {
        $ops = [
            ['type' => 'record', 'entry' => ['kind' => 'debt_note', 'party_name' => 'Gürbüz Toprak', 'amount' => 742000, 'debt_side' => 'payable', 'description' => 'eski borç']],
            ['type' => 'record', 'entry' => ['kind' => 'payment', 'party_name' => 'Gürbüz Toprak', 'amount' => 120000, 'purpose' => 'debt']],
        ];
        $this->model($this->toolUse(['find_party', ['name' => 'Gürbüz Toprak']]), $this->toolUse(['propose', ['ops' => $ops]]),
            $this->say('Kartı hazırladım, 742.000 borç ve 120.000 ödeme yazacağım.'));

        // 1) Mesaj: Twilio'ya boş cevap; kart arka planda API ile gider.
        $twiml = $this->send("Gürbüz Toprak'a 742 bin borcum vardı 120 bin ödedim 622 kaldı");
        $this->assertStringNotContainsString('<Message>', $twiml);
        $card = $this->lastOut();
        $this->assertStringContainsString('1. Eski borç (sen borçlusun) — Gürbüz Toprak (yeni kişi) *742.000,00 ₺*', $card);
        $this->assertStringContainsString('hesap kapalı → *borcun 622.000,00 ₺*', $card);
        $this->assertStringNotContainsString('Kartı hazırladım', $card, 'ajanın rakamlı cümlesi karta eklenmez');
        $this->assertSame([$card], $this->twilioBodies());
        $this->assertSame(0, PartyLedgerEntry::whereHas('party', fn ($q) => $q->where('name', 'Gürbüz Toprak'))->count(), 'onaysız kayıt yok');
        $proposal = AgentProposal::open(self::PHONE);
        $this->assertTrue($proposal->extracted['card_sent']);
        $this->assertSame('agent', WhatsappMessage::where('phone', Phone::normalize(self::PHONE))->where('direction', 'in')->latest('id')->value('kind'));

        // İstek biçimi (Fable 5.2): düşünme parametresi yok, zorunlu araç yok, effort var, yedek model açık, önbellek kesme noktaları.
        $first = $this->sent[0];
        $this->assertSame('claude-fable-5-2', $first['body']['model']);
        $this->assertArrayNotHasKey('thinking', $first['body']);
        $this->assertArrayNotHasKey('tool_choice', $first['body']);
        $this->assertArrayNotHasKey('temperature', $first['body']);
        $this->assertSame('medium', $first['body']['output_config']['effort'], 'rutin tur');
        $this->assertSame('default', $first['body']['fallbacks']);
        $this->assertSame(['server-side-fallback-2026-07-01'], $first['headers']['anthropic-beta']);
        $this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $first['body']['system'][0]['cache_control']);
        $this->assertSame(AgentContext::core(), $first['body']['system'][0]['text']);
        $this->assertStringContainsString('BU İŞLETME', $first['body']['system'][1]['text']);
        $last = end($first['body']['messages']);
        $this->assertSame('system', $last['role']);
        $this->assertStringContainsString('Açık öneri yok', $last['content']);
        $user = $first['body']['messages'][count($first['body']['messages']) - 2];
        $this->assertSame('user', $user['role']);
        $this->assertStringContainsString('742 bin borcum vardı', $user['content']);
        $this->assertSame(1, substr_count($user['content'], '742 bin borcum vardı'), 'şimdiki mesaj geçmişte ikinci kez yok');
        $this->assertStringContainsString('önceki mesaj', $first['body']['messages'][0]['content']);
        $this->assertContains('propose', array_column($first['body']['tools'], 'name'));

        // Döngü: modelin içeriği (düşünme bloğu dahil) aynen geri verilir; araç sonuçları tek kullanıcı mesajında.
        $second = $this->sent[1]['body']['messages'];
        $assistant = $second[count($second) - 2];
        $this->assertSame('thinking', $assistant['content'][0]['type']);
        $this->assertSame('imza-0', $assistant['content'][0]['signature']);
        $result = end($second);
        $this->assertSame('tool_result', $result['content'][0]['type']);
        $this->assertSame('toolu_find_party0', $result['content'][0]['tool_use_id']);
        $this->assertStringContainsString('Yeni kişi', $result['content'][0]['content']);
        $this->assertSame(array_slice($this->sent[0]['body']['messages'], 0), array_slice($second, 0, count($this->sent[0]['body']['messages'])), 'geçmişe yalnız eklenir');

        // 2) "evet": tutar büyük → sistem reddeder, ajan müşteriye hatırlatır; kayıt yok.
        $this->model($this->toolUse(['confirm', ['proposal_id' => $proposal->id]]), $this->say('Tutar büyük olduğu için onay olarak tutarı yazman gerekiyor.'));
        $this->send('evet');
        $this->assertSame('low', $this->sent[0]['body']['output_config']['effort'], 'kısa onay turu');
        $this->assertStringContainsString('AÇIK ÖNERİ (id ' . $proposal->id, end($this->sent[0]['body']['messages'])['content']);
        $this->assertStringContainsString('müşteri tutarı yazmadan kaydedilmez', end($this->sent[1]['body']['messages'])['content'][0]['content']);
        $this->assertSame('Tutar büyük olduğu için onay olarak tutarı yazman gerekiyor.', $this->lastOut());
        $this->assertNull(Party::where('name', 'Gürbüz Toprak')->first());

        // 3) Tutarı yazdı: kaydedilir; "kaydedildi" metnini kod gönderir, tur tek model çağrısıyla biter.
        $this->model($this->toolUse(['confirm', ['proposal_id' => $proposal->id]]));
        $this->send('742000');
        $this->assertCount(1, $this->sent);
        $this->assertSame("✅ Kaydedildi.\n📒 Gürbüz Toprak: borcun 622.000,00 ₺", $this->lastOut());
        $party = Party::where('name', 'Gürbüz Toprak')->sole();
        $this->assertEquals([742000.0, 120000.0], PartyLedgerEntry::where('party_id', $party->id)->orderBy('id')->pluck('amount')->map(fn ($a) => (float) $a)->all());

        // Günlük: her tur bir satır, maliyetle.
        $log = AgentLog::read('2026-11-09');
        $this->assertCount(3, $log);
        $this->assertSame('live', $log[0]['mode']);
        $this->assertSame(['find_party', 'propose'], array_values(array_filter(array_column($log[0]['steps'], 'tool'))));
        $this->assertGreaterThan(0, $log[0]['cost_usd']);
        $this->assertEqualsWithDelta(array_sum(array_column($log, 'cost_usd')), AgentTurn::costToday(), 0.00001);
    }

    public function test_agent_text_goes_out_when_there_is_no_card_and_never_claims_a_save(): void
    {
        $this->model($this->say('Hasan\'a borcun 45.000 ₺.'));
        $this->send('Hasana ne kadar borcum var');
        $this->assertSame('Hasan\'a borcun 45.000 ₺.', $this->lastOut());

        $outcome = ['card' => 'KART', 'proposal_id' => 1, 'confirmed' => null, 'question' => null, 'cancelled' => false, 'notes' => []];
        $this->assertSame(['KART'], AgentTurn::replies('Şantiyeyi de yazdım.', $outcome), '"yazdım" yalnız kodun sözü');
        $this->assertSame(['KART'], AgentTurn::replies('Kaydettim, borcun 622.000 kaldı.', $outcome), 'rakamlı / "kaydettim" cümlesi atılır');
        $this->assertSame(["Bir bak istersen.\n\nKART"], AgentTurn::replies('Bir bak istersen.', $outcome));
        $this->assertSame(['SORU'], AgentTurn::replies('', ['question' => 'SORU'] + $outcome), 'soru varsa kart gitmez');
        $this->assertSame([AgentTurn::SORRY], AgentTurn::replies('', ['card' => null] + $outcome));
    }

    public function test_ask_sends_a_numbered_question_once_and_refuses_to_repeat_it(): void
    {
        $ask = ['question' => 'Malzeme geldi mi?', 'options' => ['Geldi, parasını peşin verdim', 'Henüz gelmedi, önden ödedim']];
        $this->model($this->toolUse(['ask', $ask]));
        $this->send('Demirci Aliden peşin malzeme aldım 25 bin');
        $this->assertSame("❓ Malzeme geldi mi?\n*1* Geldi, parasını peşin verdim\n*2* Henüz gelmedi, önden ödedim", $this->lastOut());
        $this->assertCount(1, $this->sent, 'soru turu bitirir');

        // Aynı soru ikinci kez: araç reddeder, ajan başka bir şey yapmak zorunda.
        $this->model($this->toolUse(['ask', $ask]), $this->say('Yani malzemeyi teslim aldın, öyle mi?'));
        $this->send('Nasıl');
        $this->assertStringContainsString('Bu soruyu az önce sordun', end($this->sent[1]['body']['messages'])['content'][0]['content']);
        $this->assertTrue(end($this->sent[1]['body']['messages'])['content'][0]['is_error']);
        $this->assertSame('Yani malzemeyi teslim aldın, öyle mi?', $this->lastOut());
    }

    public function test_confirm_cannot_approve_a_card_proposed_in_the_same_turn_and_memory_is_written(): void
    {
        $party = Party::create(['name' => 'Yıldız Asansör']);
        PartyLedgerEntry::create(['party_id' => $party->id, 'type' => 'alis', 'amount' => 90000, 'entry_date' => '2026-11-01']);
        $toolbox = new AgentToolbox(self::PHONE, 'evet asansörcüye 10 bin verdim');

        $p = $toolbox->run('propose', ['ops' => [['type' => 'record', 'entry' => ['kind' => 'payment', 'party_id' => $party->id, 'amount' => 10000]]]]);
        $this->assertTrue($p['kabul']);
        $c = $toolbox->run('confirm', ['proposal_id' => $p['proposal_id']]);
        $this->assertStringContainsString('henüz müşteriye gösterilmedi', $c['hata']);
        $this->assertSame(1, PartyLedgerEntry::where('party_id', $party->id)->count());

        $this->assertTrue($toolbox->run('remember', ['kind' => 'alias', 'subject' => 'Asansörcü', 'fact' => 'Asansörcü = Yıldız Asansör', 'party_id' => $party->id])['yazıldı']);
        $this->assertSame('ajan', AssistantMemory::where('fact', 'Asansörcü = Yıldız Asansör')->value('source'));
        $this->assertArrayHasKey('zaten_var', $toolbox->run('remember', ['kind' => 'alias', 'subject' => 'Asansörcü', 'fact' => 'Asansörcü = Yıldız Asansör']));
        $this->assertArrayHasKey('hata', $toolbox->run('olmayan', []));
        $this->assertSame($party->id, $toolbox->run('find_party', ['name' => 'asansörcü'])['adaylar'][0]['id'], 'hafıza hemen kullanılır');
    }

    public function test_model_failure_falls_back_to_backup_model_then_apologises_and_trips_the_breaker(): void
    {
        // Ana model 500 → yedek model cevap verir.
        $this->model(['__status' => 500], $this->say('Merhaba, nasıl yardımcı olayım?'));
        $this->send('selam');
        $this->assertSame(['claude-fable-5-2', 'claude-opus-5-5'], array_map(fn ($s) => $s['body']['model'], $this->sent));
        $this->assertSame('Merhaba, nasıl yardımcı olayım?', $this->lastOut());

        // İkisi de cevap vermezse: özür + hata sayacı; 3 hatadan sonra o numara eski yola döner.
        for ($i = 1; $i <= 3; $i++) {
            $this->model(['__status' => 500], ['__status' => 500]);
            $this->send('selam ' . $i);
            $this->assertSame(AgentTurn::SORRY, $this->lastOut());
        }
        $this->assertTrue(AgentTurn::breakerOpen(self::PHONE));
        $this->model();
        $twiml = $this->send('cariler');
        $this->assertStringContainsString('<Message>', $twiml, 'eski yol cevapladı');
        $this->assertSame([], $this->sent, 'modele gidilmedi');
    }

    public function test_daily_cost_limit_and_missing_key_hand_the_turn_to_the_old_path(): void
    {
        AgentTurn::addCost(15.0);
        $this->model();
        $this->assertStringContainsString('<Message>', $this->send('cariler'));
        $this->assertSame([], $this->sent);

        Cache::flush();
        config(['services.anthropic.api_key' => null]);
        $this->assertStringContainsString('<Message>', $this->send('cariler'));

        config(['services.anthropic.api_key' => 'agent-test-key', 'services.whatsapp.agent' => 'off']);
        $this->assertStringContainsString('<Message>', $this->send('cariler'));
        $this->assertSame([], $this->sent);
    }

    public function test_refusal_and_step_limit_end_with_an_apology_not_silence(): void
    {
        $this->model(['model' => 'claude-fable-5-2', 'stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => null], 'content' => [], 'usage' => []]);
        $this->send('bir şey');
        $this->assertSame(AgentTurn::SORRY, $this->lastOut());

        config(['agent.max_steps' => 2]);
        $this->model($this->toolUse(['find_party', ['name' => 'a b']]), $this->toolUse(['find_party', ['name' => 'c d']]), $this->say('buraya gelinmez'));
        $this->send('başka');
        $this->assertCount(2, $this->sent);
        $this->assertSame(AgentTurn::SORRY, $this->lastOut());
    }

    public function test_shadow_runs_after_the_old_reply_stores_nothing_and_sends_nothing(): void
    {
        config(['modules.agent_live' => false, 'modules.agent_shadow' => true]);
        $party = Party::create(['name' => 'Gölge Testi Usta']);
        $this->model(
            $this->toolUse(['propose', ['ops' => [['type' => 'record', 'entry' => ['kind' => 'payment', 'party_id' => $party->id, 'amount' => 5000, 'purpose' => 'advance']]]]],
                ['confirm', ['proposal_id' => 1]], ['remember', ['kind' => 'note', 'subject' => 'x', 'fact' => 'gölge notu']]),
            $this->say('Kart hazır.'));

        $twiml = $this->send('cariler');
        $this->assertStringContainsString('<Message>', $twiml, 'müşteriye eski yol cevap verdi');
        $this->assertSame([], $this->twilioBodies(), 'gölge hiçbir şey göndermez');
        $this->assertSame(0, WhatsappPendingExpense::whereIn('status', WhatsappPendingExpense::AGENT_STATUSES)->where('phone', self::PHONE)->count(), 'öneri saklanmaz');
        $this->assertSame(0, PartyLedgerEntry::where('party_id', $party->id)->count());
        $this->assertNull(AssistantMemory::where('fact', 'gölge notu')->first());

        $log = AgentLog::read('2026-11-09');
        $this->assertCount(1, $log);
        $this->assertSame('shadow', $log[0]['mode']);
        $this->assertNotEmpty($log[0]['legacy_replies']);
        $this->assertStringContainsString('Ödeme (avans) — Gölge Testi Usta *5.000,00 ₺*', $log[0]['outcome']['card']);
        $this->assertStringContainsString('Ödeme (avans)', $log[0]['replies'][0]);
    }

    /** Ajanın yazamadığı iş (maaş, hatırlatma, stok, anlaşma, çek…) eski asistana devredilir: aynı mesaj eski yolda işlenir, cevap müşteriye gider. */
    public function test_hand_over_runs_the_same_message_through_the_old_assistant(): void
    {
        $party = Party::create(['name' => 'Devir Testi Cari']);
        // Eski (akıllı) asistanın modeli mock: aynı mesajdan tahsilat kaydı çıkarır.
        $chat = \Mockery::mock(\App\Services\Whatsapp\ChatAssistant::class);
        $chat->shouldReceive('respond')->once()->andReturn(['type' => 'entry', 'text' => '', 'data' => ['kind' => 'collection', 'items' => [], 'amount' => 4100,
            'date' => now()->format('Y-m-d'), 'description' => 'Tahsilat', 'party_id' => $party->id, 'party_name' => null, 'project_id' => null,
            'project_name' => null, 'confidence' => 'high', 'question' => null, 'is_new_entry' => true]]);
        $this->app->instance(\App\Services\Whatsapp\ChatAssistant::class, $chat);
        config(['services.anthropic.chat_assistant' => true]);
        $in = WhatsappMessage::where('phone', Phone::normalize(self::PHONE))->where('direction', 'in')->count();

        $this->model($this->toolUse(['hand_over', ['reason' => 'test: eski asistanın işi']]));
        $twiml = $this->send('Devir testi cariden 4100 aldım');

        $this->assertStringNotContainsString('<Message>', $twiml);
        // (Eski yolun kendi model çağrıları da olabilir — ajanınkiler araç listesinden tanınır.)
        $agentCalls = fn () => array_values(array_filter($this->sent, fn ($s) => in_array('propose', array_column($s['body']['tools'] ?? [], 'name'), true)));
        $this->assertCount(1, $agentCalls(), 'ajan tek çağrıda devretti');
        // Eski yol aynı mesajdan kendi taslağını açtı ve kartını gönderdi.
        $draft = WhatsappPendingExpense::legacyOpen(self::PHONE)->latest('id')->first();
        $this->assertNotNull($draft);
        $this->assertEquals(4100, $draft->extracted['amount']);
        $this->assertStringContainsString('4.100,00', (string) $this->lastOut());
        $this->assertCount(1, $this->twilioBodies());
        $this->assertSame($in + 1, WhatsappMessage::where('phone', Phone::normalize(self::PHONE))->where('direction', 'in')->count(), 'gelen mesaj bir kez kayıtlı');
        $this->assertSame('agent_handover', WhatsappMessage::where('phone', Phone::normalize(self::PHONE))->where('direction', 'in')->latest('id')->value('kind'));
        $this->assertNull(AgentProposal::open(self::PHONE));

        // Eski asistanın kartı açıkken cevap ona gider: ajan çağrılmaz, "evet" eski yolda kaydeder.
        $this->model();
        $twiml = $this->send('evet');
        $this->assertSame([], $agentCalls(), 'ajan araya girmedi');
        $this->assertStringContainsString('Kaydedildi', html_entity_decode($twiml));
        $this->assertEquals([4100.0], PartyLedgerEntry::where('party_id', $party->id)->pluck('amount')->map(fn ($a) => (float) $a)->all());

        // Kart kapandı: sıradaki mesaj yine ajana gelir.
        $this->model($this->say('Buyur.'));
        $this->send('selam');
        $this->assertCount(1, $agentCalls());
        $this->assertSame('Buyur.', $this->lastOut());
    }

    public function test_photo_is_passed_to_the_model_as_an_image_block(): void
    {
        $messages = AgentContext::messages(self::PHONE, 'bunu yaz', ['media_type' => 'image/jpeg', 'data' => 'QUJD']);
        $last = end($messages);
        $this->assertSame('user', $last['role']);
        $this->assertSame(['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => 'QUJD'], $last['content'][0]['source']);
        $this->assertStringContainsString('bunu yaz', $last['content'][1]['text']);
        $pdf = AgentContext::messages(self::PHONE, '', ['media_type' => 'application/pdf', 'data' => 'QUJD']);
        $this->assertSame('document', end($pdf)['content'][0]['type']);
        $this->assertSame('user', $messages[0]['role']);
    }

    public function test_tool_definitions_are_stable_and_valid(): void
    {
        $tools = AgentToolbox::definitions();
        $this->assertSame(['find_party', 'party_overview', 'records', 'report', 'search_conversations', 'sql_select', 'propose', 'confirm', 'cancel', 'ask', 'send_statement', 'hand_over', 'remember'],
            array_column($tools, 'name'));
        $this->assertSame(json_encode($tools), json_encode(AgentToolbox::definitions()), 'önbellek için bayt bayt aynı');
        foreach ($tools as $tool) {
            $this->assertSame('object', $tool['input_schema']['type']);
            $this->assertNotFalse(json_encode($tool, JSON_THROW_ON_ERROR));
        }
        $this->assertGreaterThan(3000, mb_strlen(AgentContext::core()));
        $this->assertStringNotContainsString(date('Y'), AgentContext::core(), 'çekirdek talimatta tarih yok (önbellek)');
    }
}
