<?php

namespace Tests\Feature\Agent;

use App\Models\HubFirm;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;
use App\Services\Agent\AgentGate;
use App\Services\Agent\AgentShadow;
use App\Services\Agent\AgentTurn;
use App\Services\Whatsapp\DraftFollowUp;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\FeatureSettings;
use App\Support\ModuleProfiles;
use App\Support\Phone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Ajan asistan — Aşama A zemini (plan v5 §9-A): bayraklar varsayılan kapalı, kapalı firmada tur ajana hiç uğramaz,
 * ajan turu almazsa (null) / gölge patlarsa müşteri eski cevabı aynen alır, ajan önerisini eski yol görmez.
 */
class AgentGateTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905558880041';

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('alacak_verecek', ['smart_assistant' => true]), 'app.profile' => 'alacak_verecek',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 888 00 41'],
            'services.anthropic.chat_assistant' => false, 'services.whatsapp.agent' => 'on']);
        Http::fake();
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        // İlk mesaj karşılaması cevapları değiştirmesin.
        WhatsappMessage::create(['phone' => Phone::normalize(self::PHONE), 'direction' => 'in', 'body' => 'önceki']);
    }

    private function send(string $body)
    {
        return $this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0]);
    }

    private function text($response): string
    {
        return html_entity_decode($response->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    private function ai(array ...$r): void
    {
        $m = Mockery::mock(ExpenseExtractor::class)->makePartial();
        $m->shouldReceive('extract')->andReturn(...$r);
        $this->app->instance(ExpenseExtractor::class, $m);
    }

    private function payment(Party $party, array $o = []): array
    {
        return array_merge(['kind' => ExpenseExtractor::KIND_COLLECTION, 'items' => [], 'amount' => 4100,
            'date' => now()->format('Y-m-d'), 'description' => 'Tahsilat', 'party_id' => $party->id, 'party_name' => null,
            'project_id' => null, 'project_name' => null, 'confidence' => 'high', 'question' => null, 'is_new_entry' => true], $o);
    }

    public function test_agent_is_off_by_default_for_every_profile(): void
    {
        foreach ([null, 'mimar', 'muteahhit', 'trade', 'alacak_verecek'] as $profile) {
            $modules = ModuleProfiles::resolve($profile, ['smart_assistant' => true]);
            $this->assertFalse($modules['agent_shadow'], (string) $profile);
            $this->assertFalse($modules['agent_live'], (string) $profile);
            config(['modules' => $modules]);
            $this->assertSame(AgentGate::OFF, AgentGate::mode());
        }
        // Saklı listede kutu yoksa kapalı (canlı firmaların bugünkü ayarı).
        $stored = FeatureSettings::toModules(['features' => ['expenses' => true], 'assistant' => ['smart_assistant' => true]]);
        $this->assertFalse($stored['agent_shadow']);
        $this->assertFalse($stored['agent_live']);
    }

    public function test_mode_follows_boxes_kill_switch_and_smart_assistant(): void
    {
        config(['modules.agent_shadow' => true]);
        $this->assertSame(AgentGate::SHADOW, AgentGate::mode());

        config(['modules.agent_live' => true]);
        $this->assertSame(AgentGate::LIVE, AgentGate::mode(), 'ikisi de açıksa canlı');

        config(['services.whatsapp.agent' => 'OFF']);
        $this->assertSame(AgentGate::OFF, AgentGate::mode(), 'acil kapatma kutuları ezer');

        config(['services.whatsapp.agent' => 'on', 'modules.smart_assistant' => false]);
        $this->assertSame(AgentGate::OFF, AgentGate::mode(), 'akıllı asistan kapalıyken ajan yok');
    }

    public function test_hub_boxes_are_stored_and_read_back(): void
    {
        $firm = new HubFirm(['settings' => HubFirm::settingsFromFeaturesForm([
            'feat_expenses' => true, 'asst_smart_assistant' => true, 'asst_agent_shadow' => true])]);

        $this->assertTrue($firm->resolvedModules()['agent_shadow']);
        $this->assertFalse($firm->resolvedModules()['agent_live']);
        $this->assertTrue($firm->featuresFormData()['asst_agent_shadow']);
        $this->assertFalse($firm->featuresFormData()['asst_agent_live']);
    }

    public function test_agent_is_never_called_when_the_firm_has_it_off(): void
    {
        $turn = Mockery::mock(AgentTurn::class);
        $turn->shouldNotReceive('prepare');
        $this->app->instance(AgentTurn::class, $turn);
        $shadow = Mockery::mock(AgentShadow::class);
        $shadow->shouldNotReceive('observe');
        $this->app->instance(AgentShadow::class, $shadow);

        $this->assertNotSame('', trim(strip_tags($this->text($this->send('cariler')))));
    }

    public function test_when_the_agent_declines_the_turn_the_old_path_answers_exactly_as_before(): void
    {
        $off = $this->text($this->send('nasıl'));

        config(['modules.agent_live' => true]);
        $turn = Mockery::mock(AgentTurn::class);
        $turn->shouldReceive('prepare')->once()->andReturn(null);
        $this->app->instance(AgentTurn::class, $turn);

        $this->assertSame($off, $this->text($this->send('nasıl')));
    }

    public function test_photos_and_ready_commands_stay_on_the_old_path_even_when_the_agent_is_live(): void
    {
        config(['modules.agent_live' => true, 'services.anthropic.api_key' => 'x']);
        $turn = Mockery::mock(AgentTurn::class);
        $turn->shouldNotReceive('prepare');
        $this->app->instance(AgentTurn::class, $turn);

        foreach (['cariler', 'hatırlatmalarım', 'iptal 2', 'Çekler'] as $command) {
            $this->assertStringContainsString('<Message>', $this->send($command)->assertOk()->getContent(), $command);
        }
        $this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => '', 'NumMedia' => 1, 'MediaUrl0' => 'https://example.test/x.jpg',
            'MediaContentType0' => 'image/jpeg'])->assertOk();
    }

    public function test_shadow_sees_the_old_reply_and_a_shadow_failure_never_reaches_the_customer(): void
    {
        $off = $this->text($this->send('cariler'));

        config(['modules.agent_shadow' => true]);
        $turn = Mockery::mock(AgentTurn::class);
        $turn->shouldNotReceive('prepare');
        $this->app->instance(AgentTurn::class, $turn);
        $seen = null;
        $shadow = Mockery::mock(AgentShadow::class);
        $shadow->shouldReceive('observe')->once()->andReturnUsing(function ($phone, $body, $media, $replies) use (&$seen) {
            $seen = [$phone, $body, $media, $replies];
            throw new \RuntimeException('test: gölge patladı');
        });
        $this->app->instance(AgentShadow::class, $shadow);

        $this->assertSame($off, $this->text($this->send('cariler')));
        $this->assertSame(self::PHONE, $seen[0]);
        $this->assertSame('cariler', $seen[1]);
        $this->assertNull($seen[2]);
        $this->assertCount(1, $seen[3]);
        $this->assertStringContainsString($seen[3][0], $off);
    }

    /** Ajan önerisi eski yolda "açık taslak" sayılmaz: "evet" onu kaydetmez, yeni mesaj onun üstüne yazmaz, takip onu sormaz. */
    public function test_old_path_does_not_see_or_touch_an_agent_proposal(): void
    {
        // Çıkarıcı yolu (AI mock'u basit); açık taslak sorgusu akıllı asistan yoluyla ortaktır.
        config(['modules.smart_assistant' => false]);
        $party = Party::create(['name' => 'Ajan Yalıtım Carisi']);
        $proposal = WhatsappPendingExpense::create(['phone' => self::PHONE, 'status' => WhatsappPendingExpense::AGENT_OPEN,
            'summary' => 'Ajan kartı', 'extracted' => $this->payment($party, ['amount' => 99000])]);

        // 1) "evet" → eski yol açık taslak bulmaz, hiçbir şey yazılmaz.
        $this->text($this->send('evet'));
        $this->assertSame(0, PartyLedgerEntry::where('party_id', $party->id)->count());
        $this->assertSame(WhatsappPendingExpense::AGENT_OPEN, $proposal->fresh()->status);

        // 2) Yeni kayıt eski yolda kendi taslağını açar; öneriye dokunmaz, öneriyi düzeltme sanmaz.
        $this->ai($this->payment($party));
        $this->text($this->send('Ajan yalıtım carisinden 4100 aldım'));
        $draft = WhatsappPendingExpense::legacyOpen(self::PHONE)->latest('id')->first();
        $this->assertNotNull($draft);
        $this->assertNotSame($proposal->id, $draft->id);
        $this->assertEquals(4100, $draft->extracted['amount']);
        $this->assertEquals(99000, $proposal->fresh()->extracted['amount']);
        $this->assertSame('Ajan kartı', $proposal->fresh()->summary);

        // 3) "evet" yalnız eski taslağı yazar.
        $this->text($this->send('evet'));
        $this->assertEquals([4100.0], PartyLedgerEntry::where('party_id', $party->id)->pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->assertSame(WhatsappPendingExpense::AGENT_OPEN, $proposal->fresh()->status);

        // 4) Yarım kayıt takibi ve "açık öneri" kapsamı.
        $proposal->forceFill(['updated_at' => now()->subHours(3), 'created_at' => now()->subHours(3)])->save();
        $this->assertNull(DraftFollowUp::inlineCandidate(self::PHONE, 0));
        $this->assertFalse(collect(DraftFollowUp::dueForMessage())->contains(fn ($p) => $p->id === $proposal->id));
        $this->assertSame([$proposal->id], WhatsappPendingExpense::agentOpen(self::PHONE)->pluck('id')->all());
        $this->assertTrue($proposal->fresh()->isAgentProposal());
    }
}
