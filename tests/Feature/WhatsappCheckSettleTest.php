<?php

namespace Tests\Feature;

use App\Models\Check;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\CheckLedger;
use App\Support\PartyStatement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Vadesi gelen çek (kullanıcı 26.10): "tahsil ettim / ödendi / çek yattı" yalnız çeki işaretler — para çek alındığı / verildiği
 * gün işlendi, YENİ tahsilat / ödeme yazılmaz. Önceden: "Ali'nin çekini tahsil ettim" → bakiye 0 → "Ali'ye 20.000 borçlusun"
 * (çift sayım); "Kuşak'ın çekini ödedim" → "ne için? 1-4" → yeni ödeme. AI sahte.
 */
class WhatsappCheckSettleTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905556660009';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 666 00 09']]);
        Http::fake(['*' => Http::response('', 200)]);
        WhatsappMessage::create(['phone' => '905556660009', 'direction' => 'in', 'body' => 'önceki']);
        // Ortak geliştirme veritabanındaki bekleyen çekler kişisiz eşleşmeye karışmasın (işlem sonunda geri alınır).
        Check::whereIn('status', ['issued', 'portfolio'])->update(['status' => 'paid']);
    }

    private function ai(array $o): void
    {
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturn(array_merge(['kind' => 'collection', 'items' => [], 'amount' => 0.0,
            'date' => now()->toDateString(), 'description' => '', 'party_id' => null, 'party_name' => null, 'confidence' => 'high',
            'question' => null, 'is_new_entry' => true, 'payment_type' => null, 'paid' => null, 'project_id' => null, 'project_name' => null,
            'category_id' => null, 'category_name' => null, 'due_date' => null, 'debt_side' => null, 'reply' => null,
            'payment_purpose' => null, 'settles_expense_id' => null, 'totals_side' => null, 'date_from' => null, 'date_to' => null], $o));
        $this->app->instance(ExpenseExtractor::class, $mock);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    /** Satış/alış + çekle kapanmış cari (bakiye 0), çek bugün vadeli. */
    private function closedWithCheck(string $name, string $status, float $amount = 20000, ?string $due = null): array
    {
        $party = Party::create(['name' => $name]);
        PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => '2026-09-01',
            'type' => $status === 'portfolio' ? 'satis' : 'alis', 'amount' => $amount]);
        $check = Check::create(['party_id' => $party->id, 'amount' => $amount, 'due_date' => $due ?? now()->toDateString(),
            'status' => $status, 'issue_date' => '2026-09-01', 'check_number' => 'T-' . $party->id]);
        CheckLedger::attach($check);

        return [$party, $check->fresh()];
    }

    public function test_received_check_collected_only_marks_check(): void
    {
        [$ali, $check] = $this->closedWithCheck('Ali Çek Tahsil', 'portfolio');
        $entries = PartyLedgerEntry::where('party_id', $ali->id)->count();
        $this->ai(['kind' => 'collection', 'party_id' => $ali->id, 'amount' => 20000.0, 'description' => 'Çek tahsilatı']);

        $card = $this->send("Ali'nin çekini tahsil ettim");
        $this->assertStringContainsString('Çek *tahsil edildi* olarak işaretlensin mi', $card);
        $this->assertStringContainsString('deftere işlenmişti, tekrar yazılmaz', $card);

        $done = $this->send('evet');
        $this->assertStringContainsString('*tahsil edildi*', $done);
        $this->assertSame('collected', $check->fresh()->status);
        $this->assertSame(0.0, PartyStatement::build($ali)['balance'], 'bakiye değişmez (çift sayım yok)');
        $this->assertSame($entries, PartyLedgerEntry::where('party_id', $ali->id)->count(), 'yeni satır yok');
    }

    public function test_given_check_paid_by_name_only_marks_check(): void
    {
        [$kusak, $check] = $this->closedWithCheck('Kuşak Çek Ödeme', 'issued', 50000);
        $this->ai(['kind' => 'payment', 'party_id' => $kusak->id, 'amount' => 50000.0, 'description' => 'Çek ödemesi']);

        $card = $this->send("Kuşak'ın çekini ödedim");
        $this->assertStringContainsString('Çek *ödendi* olarak işaretlensin mi', $card);
        $this->assertStringNotContainsString('ne için', $card, 'eski "1 İş/mal geldi…" sorusu çıkmaz');

        $this->send('evet');
        $this->assertSame('paid', $check->fresh()->status);
        $this->assertSame(0.0, PartyStatement::build($kusak)['balance']);
    }

    public function test_baska_returns_to_normal_record(): void
    {
        [$ali, $check] = $this->closedWithCheck('Ali Ayrı Ödeme', 'portfolio');
        $this->ai(['kind' => 'collection', 'party_id' => $ali->id, 'amount' => 20000.0, 'description' => 'Nakit']);

        $this->send('Ali 20 bin verdi');
        $reply = $this->send('başka');

        $this->assertStringNotContainsString('işaretlensin', $reply);
        $this->assertSame('portfolio', $check->fresh()->status, 'çek işaretlenmez');
    }

    public function test_nameless_check_deposit_lists_candidates_and_picks_by_number(): void
    {
        [, $a] = $this->closedWithCheck('Liste Ali', 'portfolio', 20000);
        [, $b] = $this->closedWithCheck('Liste Veli', 'portfolio', 15000, now()->subDay()->toDateString());
        $this->ai(['kind' => 'collection', 'amount' => 0.0, 'description' => 'Çek yattı']);

        $list = $this->send('çek yattı');
        $this->assertStringContainsString('Vadesi gelen aldığın çekler', $list);
        $this->assertStringContainsString('*hepsi*', $list);

        $pickedIndex = str_contains(explode('*2*', $list)[1] ?? '', 'Liste Veli') ? '2' : '1';
        $this->send($pickedIndex);

        $this->assertSame('collected', $b->fresh()->status);
        $this->assertSame('portfolio', $a->fresh()->status, 'seçilmeyen kalır');
    }

    public function test_nameless_hepsi_marks_all(): void
    {
        [, $a] = $this->closedWithCheck('Hepsi Ali', 'portfolio', 20000);
        [, $b] = $this->closedWithCheck('Hepsi Veli', 'portfolio', 15000);
        $this->ai(['kind' => 'collection', 'amount' => 0.0, 'description' => 'Çekler tahsil edildi']);

        $this->send('çekler tahsil edildi');
        $this->send('hepsi');

        $this->assertSame('collected', $a->fresh()->status);
        $this->assertSame('collected', $b->fresh()->status);
    }

    public function test_far_due_check_does_not_match(): void
    {
        [$ali, $check] = $this->closedWithCheck('Uzak Vade', 'portfolio', 20000, now()->addDays(30)->toDateString());
        $this->ai(['kind' => 'collection', 'party_id' => $ali->id, 'amount' => 20000.0, 'description' => 'Nakit']);

        $this->assertStringNotContainsString('işaretlensin', $this->send('Ali 20 bin verdi'));
        $this->assertSame('portfolio', $check->fresh()->status);
    }

    public function test_nameless_without_check_word_does_not_match(): void
    {
        $this->closedWithCheck('Sözsüz', 'portfolio');
        $this->ai(['kind' => 'collection', 'amount' => 20000.0, 'description' => 'Para geldi']);

        $this->assertStringNotContainsString('işaretlensin', $this->send('20 bin geldi'));
    }

    public function test_legacy_nameless_check_paid_still_works(): void
    {
        [, $check] = $this->closedWithCheck('Eski Yol Kuşak', 'issued', 50000);
        $this->ai(['kind' => 'expense', 'amount' => 50000.0, 'description' => 'Çek ödendi']);

        $this->assertStringContainsString('*ödendi* olarak işaretlensin', $this->send('50 bin çek ödendi'));
        $this->send('evet');
        $this->assertSame('paid', $check->fresh()->status);
    }

    public function test_morning_check_reminder_is_noted_for_the_assistant(): void
    {
        [, $check] = $this->closedWithCheck('Sabah Ali', 'portfolio', 20000);
        config(['services.whatsapp.reminder_phones' => ['905556660009'], 'services.whatsapp.check_reminder_content_sid' => 'HXcheck',
            'services.twilio.sid' => 'ACtest', 'services.twilio.token' => 'tok', 'services.whatsapp.from' => '+14786665916',
            'services.whatsapp.check_reminder_days' => [0]]);
        \Illuminate\Support\Facades\Cache::flush();

        $this->artisan('checks:remind')->assertSuccessful();

        $note = WhatsappMessage::where('phone', '905556660009')->where('kind', 'check_reminder')->latest('id')->value('body');
        $this->assertStringContainsString('📥 Sabah Ali 20.000,00 ₺ (tahsil edilecek)', (string) $note);
        $this->assertContains('check_reminder', \App\Services\Whatsapp\ChatAssistant::OUTBOUND_KINDS);
    }
}
