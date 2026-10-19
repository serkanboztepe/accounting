<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ChatAssistant;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\RecordsQuery;
use App\Support\ModuleProfiles;
use App\Support\MonthlyResult;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Kullanıcı kararları 19.10 (Akkuş 17-18.10): günlük sonuçta maaş düşülmez (bilgi satırı); "kaç günlük" kayıttan; gece kasası
 * biten güne; bekleyen kayıt yokken "Tamam" → 👍 ya da yarım kalanı adıyla sor.
 */
class WhatsappDailyFixesTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905557770021';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'modules' => ModuleProfiles::resolve('trade', ['smart_assistant' => true, 'expenses' => true]), 'app.profile' => 'trade',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 777 00 21'],
        ]);
        Http::fake();
        WhatsappMessage::create(['phone' => '905557770021', 'direction' => 'in', 'body' => 'önceki']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function akkusDay(string $date): void
    {
        $musteri = Party::create(['name' => 'Kasa Müşterisi']);
        $hikmet = Party::create(['name' => 'Hikmet', 'is_employee' => true]);
        PartyLedgerEntry::create(['party_id' => $musteri->id, 'type' => PartyLedgerEntry::TYPE_SALE, 'amount' => 60750, 'entry_date' => $date, 'description' => 'Ciro']);
        PartyLedgerEntry::create(['party_id' => $hikmet->id, 'type' => PartyLedgerEntry::TYPE_PURCHASE, 'amount' => 62000, 'entry_date' => $date, 'description' => 'Maaş']);
        Expense::create(['expense_date' => $date, 'amount' => 1150, 'payment_status' => 'paid', 'description' => 'Ergenekon gider']);
    }

    /** Akkuş 17.10: 60.750 ciroluk gün maaş yüzünden "🔻 Eksi 2.400" göründü, Emre "eksi ne oluyor" diye sordu. */
    public function test_one_day_result_does_not_subtract_monthly_salary(): void
    {
        $this->akkusDay('2031-03-17');
        $day = Carbon::parse('2031-03-17');

        $text = MonthlyResult::whatsappText(MonthlyResult::forRange($day, $day));

        $this->assertStringContainsString('✅ Kalan: 59.600,00 ₺', $text);
        $this->assertStringContainsString('👷 Bugün maaş ödendi (Hikmet): 62.000,00 ₺ — aylık sonuçta düşülür', $text);
        $this->assertStringNotContainsString('Eksi', $text);
        $this->assertStringNotContainsString('Personel:', $text);

        $month = MonthlyResult::whatsappText(MonthlyResult::forMonth($day));
        $this->assertStringContainsString('Personel: 62.000,00 ₺', $month, 'aylık sonuç maaşı düşer');
        $this->assertStringContainsString('🔻 Eksi: 2.400,00 ₺', $month);
    }

    /** Akkuş 18.10: "Kaç günlük?" → "18 günlük" (kayıtlar 5 günlüktü). Sonuçta satış girilen günler yazar. */
    public function test_range_result_tells_the_days_with_sales(): void
    {
        $m = Party::create(['name' => 'Kasa']);
        foreach (['2031-03-13', '2031-03-14', '2031-03-17'] as $d) {
            PartyLedgerEntry::create(['party_id' => $m->id, 'type' => PartyLedgerEntry::TYPE_SALE, 'amount' => 1000, 'entry_date' => $d, 'description' => 'Ciro']);
        }

        $text = MonthlyResult::whatsappText(MonthlyResult::forMonth(Carbon::parse('2031-03-01')));
        $this->assertStringContainsString('📅 Satış girilen gün: 3 (13–17 Mart)', $text);

        $q = RecordsQuery::run(['types' => ['satis'], 'date_from' => '2031-03-01', 'date_to' => '2031-03-31']);
        $this->assertSame(['ilk' => '2031-03-13', 'son' => '2031-03-17', 'gün_sayısı' => 3], $q['kayıt_günleri']);
    }

    public function test_night_till_goes_to_the_finished_day(): void
    {
        $till = ['kind' => ExpenseExtractor::KIND_TRADE_SALE, 'party_id' => null, 'party_name' => null, 'amount' => 50844, 'paid' => true];

        Carbon::setTestNow(Carbon::parse('2031-03-18 00:30', 'Europe/Istanbul'));
        $d = ExpenseExtractor::guardNightTill($till + ['date' => '2031-03-18'], "Araştırma kasa\nPos 41.694\nNakit 9.150");
        $this->assertSame('2031-03-17', $d['date']);
        $this->assertTrue($d['night_till']);

        $this->assertSame('2031-03-18', ExpenseExtractor::guardNightTill($till + ['date' => '2031-03-18'], 'bugünün kasası nakit 500')['date'], '"bugün" denirse dokunulmaz');
        $this->assertSame('2031-03-18', ExpenseExtractor::guardNightTill(['party_name' => 'Ali'] + $till + ['date' => '2031-03-18'], 'Ali 500')['date'], 'carili satış değil');
        $this->assertSame('2031-03-18', ExpenseExtractor::guardNightTill(['kind' => ExpenseExtractor::KIND_EXPENSE] + $till + ['date' => '2031-03-18'], 'mazot 500')['date'], 'gider gerçek tarihinde');

        Carbon::setTestNow(Carbon::parse('2031-03-18 10:00', 'Europe/Istanbul'));
        $this->assertSame('2031-03-18', ExpenseExtractor::guardNightTill($till + ['date' => '2031-03-18'], 'kasa 500')['date'], 'gündüz normal');
    }

    private function chatConfirm(): void
    {
        $mock = Mockery::mock(ChatAssistant::class);
        $mock->shouldReceive('respond')->andReturn(['type' => 'confirm', 'paid' => null]);
        $this->app->instance(ChatAssistant::class, $mock);
    }

    /** Akkuş 17.10 22:46: açıklamaya "Tamam" → "Onay bekleyen bir kayıt yok (taslaklar 30 dk geçerli)". */
    public function test_ok_after_an_explanation_is_a_thumbs_up(): void
    {
        $this->chatConfirm();

        $reply = $this->send('Tamam');

        $this->assertStringContainsString('👍', $reply);
        $this->assertStringNotContainsString('taslak', $reply);
    }

    public function test_ok_with_a_stale_draft_names_it_and_asks_again(): void
    {
        $old = WhatsappPendingExpense::create(['phone' => self::PHONE, 'status' => 'awaiting_confirmation', 'summary' => 'x',
            'extracted' => ['kind' => ExpenseExtractor::KIND_EXPENSE, 'amount' => 750, 'description' => 'Mazot']]);
        WhatsappPendingExpense::whereKey($old->id)->update(['updated_at' => now()->subMinutes(45)]);
        $this->chatConfirm();
        $before = Expense::count();

        $reply = $this->send('Tamam');

        $this->assertStringContainsString('Az önceki *Gider 750,00 ₺, Mazot* henüz kaydedilmedi. Kaydedeyim mi?', $reply);
        $this->assertSame($before, Expense::count(), 'onay sanılıp kaydedilmez');
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }
}
