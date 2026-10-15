<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\ModuleProfiles;
use App\Support\Phone;
use App\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Kayıt güvenliği (review A/B/C/F/G/H, 16.10): yarım kayıt yok, düzeltme tek parça, kilitsiz işlem yok,
 * aynı mesaj iki kez işlenmez, tanınmayan tür gidere düşmez, olmayan numara kayda gitmez. AI mock.
 */
class WhatsappSafetyTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905558880006';

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('alacak_verecek'), 'app.profile' => 'alacak_verecek',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 888 00 06'],
            'services.anthropic.chat_assistant' => false]);
        Http::fake();
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
    }

    private function purchase(Party $party, array $o = []): array
    {
        return array_merge(['kind' => ExpenseExtractor::KIND_PURCHASE, 'items' => [], 'amount' => 5000, 'paid' => true,
            'date' => now()->format('Y-m-d'), 'description' => 'Malzeme', 'party_id' => $party->id, 'party_name' => null,
            'project_id' => null, 'project_name' => null, 'confidence' => 'high', 'question' => null, 'is_new_entry' => true], $o);
    }

    private function ai(array ...$r): void
    {
        $m = Mockery::mock(ExpenseExtractor::class)->makePartial();
        $m->shouldReceive('extract')->andReturn(...$r);
        $this->app->instance(ExpenseExtractor::class, $m);
    }

    private function send(string $body, ?string $sid = null)
    {
        return $this->post('/whatsapp/webhook', array_filter(['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0, 'MessageSid' => $sid]));
    }

    private function text($response): string
    {
        return html_entity_decode($response->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    /** A: alış + ödeme birlikte yazılır; ikincisi patlarsa birincisi de geri alınır, taslak açık kalır. */
    public function test_failed_commit_leaves_no_half_record(): void
    {
        $party = Party::create(['name' => 'Güvenlik Testi Cari']);
        $this->ai($this->purchase($party));
        $this->text($this->send('Güvenlik testi cariden 5000 malzeme aldım ödedim'));

        PartyLedgerEntry::creating(function (PartyLedgerEntry $e) {
            if ($e->type === PartyLedgerEntry::TYPE_PAYMENT) {
                throw new \RuntimeException('test: ödeme satırı yazılamadı');
            }
        });
        $this->send('evet');

        $this->assertSame(0, PartyLedgerEntry::where('party_id', $party->id)->count(), 'yarım kayıt (yalnız alış) kalmamalı');
        $this->assertSame('awaiting_confirmation', WhatsappPendingExpense::where('phone', self::PHONE)->latest('id')->value('status'));
    }

    /** A pozitif kontrol: normal kayıtta ikisi de yazılır. */
    public function test_normal_commit_writes_both_rows(): void
    {
        $party = Party::create(['name' => 'Güvenlik Testi Cari 2']);
        $this->ai($this->purchase($party));
        $this->send('Güvenlik testi cari 2den 5000 malzeme aldım ödedim');
        $this->send('evet');

        $this->assertSame(2, PartyLedgerEntry::where('party_id', $party->id)->count());
    }

    /** B: düzeltmenin doğrusu eksikse eski kayıt silinmiş kalmaz. */
    public function test_failed_correction_keeps_old_record(): void
    {
        $party = Party::create(['name' => 'Güvenlik Testi Düzeltme']);
        $old = PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => now(), 'type' => PartyLedgerEntry::TYPE_PURCHASE,
            'description' => 'Eski', 'amount' => 3000]);
        // Doğrusu: carisi olmayan ödeme → kaydedilemez (kime?).
        WhatsappPendingExpense::create(['phone' => self::PHONE, 'status' => 'awaiting_confirmation', 'summary' => 'düzelt',
            'extracted' => ['kind' => 'undo', 'targets' => [['ledger', $old->id]], 'undo_of' => 0,
                'replacement' => ['kind' => ExpenseExtractor::KIND_PAYMENT, 'amount' => 3000, 'paid' => true, 'items' => [],
                    'party_id' => null, 'party_name' => null, 'description' => 'Ödeme', 'date' => now()->format('Y-m-d'), 'confidence' => 'high']]]);

        $reply = $this->text($this->send('evet'));

        $this->assertStringContainsString('eski kayıt yerinde duruyor', $reply);
        $this->assertTrue(PartyLedgerEntry::whereKey($old->id)->exists());
    }

    /** G: aynı Twilio mesajı iki kez gelirse ikincisi işlenmez. */
    public function test_duplicate_message_sid_is_ignored(): void
    {
        $party = Party::create(['name' => 'Güvenlik Testi Tekrar']);
        $this->ai($this->purchase($party), $this->purchase($party));
        $sid = 'SM' . uniqid();

        $this->send('Güvenlik testi tekrar 5000', $sid);
        $before = WhatsappMessage::where('phone', Phone::normalize(self::PHONE))->count();
        $this->assertSame('', trim(strip_tags($this->send('Güvenlik testi tekrar 5000', $sid)->getContent())));
        $this->assertSame($before, WhatsappMessage::where('phone', Phone::normalize(self::PHONE))->count());
        // Pozitif kontrol: farklı mesaj işlenir.
        $this->assertStringContainsString('Güvenlik Testi Tekrar', $this->text($this->send('Güvenlik testi tekrar 5000', 'SM' . uniqid())));
    }

    /** C: bir önceki mesaj işlenirken (kilit dolu) kilitsiz devam edilmez. */
    public function test_busy_lock_does_not_process_message(): void
    {
        $this->ai($this->purchase(Party::create(['name' => 'Güvenlik Testi Kilit'])));
        $lock = Cache::lock(Tenancy::key('wa-msg:' . Phone::normalize(self::PHONE)), 60);
        $this->assertTrue($lock->get());
        $before = WhatsappPendingExpense::count();
        try {
            $reply = $this->text($this->send('Güvenlik testi kilit 5000'));
        } finally {
            $lock->release();
        }

        $this->assertStringContainsString('hâlâ işliyorum', $reply);
        $this->assertSame($before, WhatsappPendingExpense::count());
    }

    /** F: tanınmayan tür gider olmaz; H: olmayan proje/cari numarası boşalır. */
    public function test_unknown_kind_and_ids_are_neutralized(): void
    {
        config(['modules' => ModuleProfiles::resolve('muteahhit'), 'app.profile' => 'muteahhit']);
        $x = app(ExpenseExtractor::class);

        $this->assertSame(ExpenseExtractor::KIND_HELP, $x->normalizeEntry(['kind' => 'uydurma_tur', 'amount' => 500, 'description' => 'x', 'paid' => true, 'confidence' => 'high'])['kind']);
        $this->assertSame(ExpenseExtractor::KIND_EXPENSE, $x->normalizeEntry(['kind' => 'expense', 'amount' => 500, 'description' => 'yakıt', 'paid' => true, 'confidence' => 'high'])['kind'], 'pozitif kontrol');

        $missing = (int) Project::max('id') + 1000;
        $d = $x->normalizeEntry(['kind' => 'expense', 'amount' => 500, 'description' => 'yakıt', 'paid' => true, 'confidence' => 'high', 'project_id' => $missing]);
        $this->assertNull($d['project_id']);
        $real = Project::create(['name' => 'Güvenlik Testi Proje', 'status' => 'active']);
        $d = $x->normalizeEntry(['kind' => 'expense', 'amount' => 500, 'description' => 'yakıt', 'paid' => true, 'confidence' => 'high', 'project_id' => $real->id]);
        $this->assertSame($real->id, $d['project_id'], 'pozitif kontrol');
    }
}
