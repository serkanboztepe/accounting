<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Models\Sale;
use App\Support\ModuleProfiles;
use App\Support\MonthlyResult;
use App\Support\RevenueQuickAnswer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** "Dünkü ciro" AI'sız (kullanıcı 23.11, Akkuş): yalnız mesajın tamamı ciro sorusuysa; rakamlar MonthlyResult ile aynı. */
class RevenueQuickAnswerTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905558880077';

    private array $branches = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('trade', ['projects' => true]), 'app.profile' => 'trade', 'app.project_label' => null,
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 888 00 77']]);
        Http::fake();
        // Uzak bir gün: yerel denemedeki satışlar karışmasın.
        $this->travelTo(now('Europe/Istanbul')->setDate(2031, 3, 11)->setTime(10, 42)->setTimezone(config('app.timezone')));
        Project::query()->update(['status' => 'cancelled']);
        foreach (['Araştırma', 'Ergenekon', 'İzzetpaşa'] as $n) {
            $this->branches[$n] = Project::create(['name' => $n . ' QA', 'status' => 'active']);
        }
    }

    private function till(string $branch, float $cash, float $card, string $date = '2031-03-10'): void
    {
        Sale::create(['sale_date' => $date, 'total_amount' => $cash + $card, 'cash_amount' => $cash, 'bank_amount' => $card,
            'project_id' => $this->branches[$branch]->id]);
    }

    public function test_only_whole_message_revenue_questions_match(): void
    {
        foreach (['Dünkü ciro', 'Dün ki ciro', 'dünki ciro', 'Dün ciro', 'dünün cirosu', 'DÜNKÜ CİRO?', '  dunku ciro ', 'Dün  ki   ciro'] as $q) {
            $this->assertSame('2031-03-10', RevenueQuickAnswer::day($q)?->toDateString(), $q);
        }
        foreach (['Bugünkü ciro', 'bugün ki ciro', 'Bugün ciro', 'bugünün cirosu', 'bugunku ciro'] as $q) {
            $this->assertSame('2031-03-11', RevenueQuickAnswer::day($q)?->toDateString(), $q);
        }
        foreach (['ciro', 'Dünkü ciro Araştırma', 'geçen hafta ciro', 'ciro ne kadar', 'dünkü ciroyu yaz', 'Araştırma kasa nakit 500', 'dün 5000 ciro'] as $q) {
            $this->assertNull(RevenueQuickAnswer::day($q), $q);
        }
    }

    public function test_branches_party_sales_and_totals_match_the_period_result(): void
    {
        $this->till('Araştırma', 9250, 38047);
        $this->till('Ergenekon', 5950, 20052);
        $this->till('İzzetpaşa', 4500, 14860);
        $this->till('Araştırma', 1000, 0, '2031-03-11'); // bugünkü — dünküne karışmaz
        $onur = Party::create(['name' => 'Onur Sünnetçi QA']);
        PartyLedgerEntry::create(['party_id' => $onur->id, 'entry_date' => '2031-03-10', 'type' => PartyLedgerEntry::TYPE_SALE,
            'direction' => 'borc', 'amount' => 5602, 'description' => 'Simit, poğaça']);

        $text = RevenueQuickAnswer::answer('Dünkü ciro');
        $day = now('Europe/Istanbul')->subDay()->startOfDay();
        $this->assertSame(98261.0, MonthlyResult::forRange($day, $day)['revenue']);
        $this->assertStringContainsString('Dünkü ciro (10 Mart): 98.261 ₺', $text);
        $this->assertStringContainsString('• Araştırma QA: 47.297 ₺ (nakit 9.250 · kart 38.047)', $text);
        $this->assertStringContainsString('• Ergenekon QA: 26.002 ₺ (nakit 5.950 · kart 20.052)', $text);
        $this->assertStringContainsString('• İzzetpaşa QA: 19.360 ₺ (nakit 4.500 · kart 14.860)', $text);
        $this->assertStringContainsString('• Satış — Onur Sünnetçi QA: 5.602 ₺', $text);
        $this->assertStringContainsString('Nakit 19.700 ₺ · Kart 72.959 ₺', $text);
        $this->assertStringNotContainsString('diğer', $text, 'satırlar toplamı tutuyor');

        $this->assertStringContainsString('Bugünkü ciro (11 Mart): 1.000 ₺', RevenueQuickAnswer::answer('bugünkü ciro'));
    }

    public function test_without_places_and_with_unassigned_till_total_still_adds_up(): void
    {
        config(['modules' => ModuleProfiles::resolve('alacak_verecek', []), 'app.profile' => 'alacak_verecek']);
        Sale::create(['sale_date' => '2031-03-10', 'total_amount' => 3000, 'cash_amount' => 1000, 'bank_amount' => 2000]);
        $ali = Party::create(['name' => 'Ali QA']);
        PartyLedgerEntry::create(['party_id' => $ali->id, 'entry_date' => '2031-03-10', 'type' => PartyLedgerEntry::TYPE_SALE,
            'direction' => 'borc', 'amount' => 25000, 'description' => 'Nakliye']);

        $text = RevenueQuickAnswer::answer('dünkü ciro');
        $this->assertStringContainsString('28.000 ₺', $text);
        $this->assertStringContainsString('• Satış — Ali QA: 25.000 ₺', $text);
        $this->assertStringContainsString('• Kasa / diğer: 3.000 ₺', $text);
        $this->assertStringNotContainsString('QA:', str_replace(['Ali QA:'], '', $text), 'şube satırı yok');
    }

    /** Akkuş 00:12 "bugünkü cirolar": gece 00–05 "bugün" = biten iş günü (eski yolla aynı); "dün" takvim günü kalır. */
    public function test_after_midnight_today_means_the_finished_business_day(): void
    {
        $this->till('Ergenekon', 100, 200, '2031-03-10');
        $this->travelTo(now('Europe/Istanbul')->setDate(2031, 3, 11)->setTime(0, 30)->setTimezone(config('app.timezone')));

        $text = RevenueQuickAnswer::answer('Bugünkü ciro');
        $this->assertStringContainsString('Bugünkü ciro (10 Mart): 300 ₺', $text);
        $this->assertStringContainsString('Gece yarısını geçtiği için dünü (10 Mart) gösterdim', $text);
        $this->assertSame('2031-03-10', RevenueQuickAnswer::day('dünkü ciro')->toDateString());

        $this->travelTo(now('Europe/Istanbul')->setDate(2031, 3, 11)->setTime(5, 0)->setTimezone(config('app.timezone')));
        $this->assertStringNotContainsString('Gece yarısını', RevenueQuickAnswer::answer('Bugünkü ciro'));
    }

    public function test_no_sales_says_so(): void
    {
        $this->assertSame('📊 Dünkü ciro (10 Mart): satış yazılmamış.', RevenueQuickAnswer::answer('Dünkü ciro'));
    }

    public function test_webhook_answers_without_any_ai_call(): void
    {
        $this->till('Ergenekon', 100, 200);
        $body = html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => 'Dün ki ciro', 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);

        $this->assertStringContainsString('Dünkü ciro (10 Mart): 300 ₺', $body);
        Http::assertNothingSent();
    }
}
