<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ChatAssistant;
use App\Services\Whatsapp\DeferTurn;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\ModuleProfiles;
use App\Support\Phone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/** "Önce cevap, sonra düşün" (Faz 1): Twilio süresine sığmayan tur → hemen "bakıyorum", sonuç ayrı mesajla. */
class WhatsappDeferredTurnTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905558880009';

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('alacak_verecek', ['smart_assistant' => true]), 'app.profile' => 'alacak_verecek',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 888 00 09'],
            'services.twilio.sid' => 'ACtest', 'services.twilio.token' => 'tok', 'services.whatsapp.from' => '+14786665916']);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SMx'], 201)]);
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
    }

    private function chat(array|\Throwable ...$results): void
    {
        $m = Mockery::mock(ChatAssistant::class);
        $exp = $m->shouldReceive('respond');
        $exp->andReturnUsing(function () use (&$results) {
            $r = array_shift($results);
            if ($r instanceof \Throwable) {
                throw $r;
            }

            return $r;
        });
        $this->app->instance(ChatAssistant::class, $m);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    public function test_slow_turn_answers_now_and_sends_result_after(): void
    {
        $this->chat(new DeferTurn('test'), ['type' => 'reply', 'text' => 'Bu ay Recep\'e 8.000 ödedin.']);

        $this->assertStringContainsString('Bakıyorum', $this->send('Bu ay kime ne kadar ödedim'));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.twilio.com') && ($r['Body'] ?? null) === 'Bu ay Recep\'e 8.000 ödedin.');
        $out = WhatsappMessage::where('phone', Phone::normalize(self::PHONE))->where('direction', 'out')->latest('id')->value('body');
        $this->assertSame('Bu ay Recep\'e 8.000 ödedin.', $out);
        $this->assertSame('chat', WhatsappMessage::where('phone', Phone::normalize(self::PHONE))->where('direction', 'in')->latest('id')->value('kind'));
    }

    public function test_deferred_entry_opens_draft_and_lock_is_released(): void
    {
        $party = Party::create(['name' => 'Ertelenen Tur Cari']);
        $entry = ['kind' => ExpenseExtractor::KIND_PURCHASE, 'items' => [], 'amount' => 12000, 'paid' => false, 'date' => now()->format('Y-m-d'),
            'description' => 'yem', 'party_id' => $party->id, 'party_name' => null, 'project_id' => null, 'project_name' => null,
            'confidence' => 'high', 'question' => null, 'is_new_entry' => true];
        $this->chat(new DeferTurn('test'), ['type' => 'entry', 'text' => '', 'data' => $entry], ['type' => 'reply', 'text' => 'ikinci mesaj işlendi']);

        $this->send('Ertelenen tur cariden 12 bin yem aldım');

        Http::assertSent(fn (Request $r) => str_contains((string) ($r['Body'] ?? ''), 'Ertelenen Tur Cari'));
        $this->assertSame(ExpenseExtractor::KIND_PURCHASE, WhatsappPendingExpense::where('phone', self::PHONE)->latest('id')->first()->extracted['kind']);
        // Kilit bırakıldı: sıradaki mesaj "hâlâ işliyorum" almaz.
        $this->assertStringNotContainsString('hâlâ işliyorum', $this->send('merhaba'));
    }

    public function test_fast_turn_is_unchanged(): void
    {
        $this->chat(['type' => 'reply', 'text' => 'Merhaba!']);

        $this->assertStringContainsString('Merhaba!', $this->send('selam'));
        Http::assertNothingSent();
    }

    public function test_deadline_passed_defers_without_calling_ai(): void
    {
        config(['services.anthropic.api_key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'x']]], 200)]);
        ChatAssistant::$deadline = microtime(true) - 1;
        try {
            app(ChatAssistant::class)->respond(self::PHONE, 'selam', null);
            $this->fail('DeferTurn bekleniyordu');
        } catch (DeferTurn) {
            Http::assertNothingSent();
        } finally {
            ChatAssistant::$deadline = null;
        }
    }
}
