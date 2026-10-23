<?php

namespace Tests\Feature\Eval;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ChatAssistant;
use App\Services\Whatsapp\DeferTurn;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Yeniden oynatma düzeni (AI taklit): arka plana bırakılan tur sonraki istekte TEKRAR çalışmamalı. 23.10 ölçümünde test
 * uygulaması istek sonrası geri çağrıları her istekte baştan çalıştırdığı için ertelenen tur ikinci taslak açmıştı.
 */
class ReplayHarnessTest extends TestCase
{
    use DatabaseTransactions;
    use ReplayRequests;

    private int $aiCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->phone = 'whatsapp:+905559990002';
        config(['modules' => ModuleProfiles::resolve('alacak_verecek', ['smart_assistant' => true]), 'app.profile' => 'alacak_verecek',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 999 00 02'],
            'services.twilio.sid' => 'ACtest', 'services.twilio.token' => 'tok', 'services.whatsapp.from' => '+14786665916']);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SMx'], 201)]);
        WhatsappPendingExpense::where('phone', $this->phone)->update(['status' => 'superseded']);
    }

    /** İlk tur süreye sığmaz (arka plana), arka planda alış taslağı; sonra "evet". */
    private function deferredThenConfirm(Party $party): void
    {
        $entry = ['kind' => ExpenseExtractor::KIND_PURCHASE, 'items' => [], 'amount' => 12000, 'paid' => false, 'date' => now()->format('Y-m-d'),
            'description' => 'malzeme', 'party_id' => $party->id, 'party_name' => null, 'project_id' => null, 'project_name' => null,
            'confidence' => 'high', 'question' => null, 'is_new_entry' => true];
        $results = [new DeferTurn('test'), ['type' => 'entry', 'text' => '', 'data' => $entry], ['type' => 'entry', 'text' => '', 'data' => $entry]];
        $chat = Mockery::mock(ChatAssistant::class);
        $chat->shouldReceive('respond')->andReturnUsing(function () use (&$results) {
            $this->aiCalls++;
            $r = array_shift($results);
            if ($r instanceof \Throwable) {
                throw $r;
            }

            return $r;
        });
        $this->app->instance(ChatAssistant::class, $chat);

        $this->assertStringContainsString('Bakıyorum', $this->sendMessage('Düzen cariden 12 bin malzeme aldım'));
        $this->assertStringContainsString('Kaydedildi', $this->sendMessage('evet'));
    }

    public function test_deferred_turn_runs_once_per_request(): void
    {
        $party = Party::create(['name' => 'Düzen Cari']);

        $this->deferredThenConfirm($party);

        $this->assertSame(2, $this->aiCalls, 'ertelenen tur bir kez (ilk deneme + arka plan)');
        $this->assertSame(1, WhatsappPendingExpense::where('phone', $this->phone)->count(), 'tek taslak');
        $this->assertSame(1, PartyLedgerEntry::where('party_id', $party->id)->count(), 'tek alış');
    }

    /** Pozitif kontrol: sıfırlama kapalıyken eski hata görünür (ertelenen tur ikinci istekte tekrar çalışır, ikinci taslak açar). */
    public function test_without_reset_the_deferred_turn_runs_again(): void
    {
        $this->resetTerminating = false;
        $party = Party::create(['name' => 'Düzen Cari']);

        $this->deferredThenConfirm($party);

        $this->assertSame(3, $this->aiCalls);
        $this->assertSame(2, WhatsappPendingExpense::where('phone', $this->phone)->count(), 'ikinci (yinelenen) taslak');
    }
}
