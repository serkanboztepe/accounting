<?php

namespace Tests\Feature;

use App\Models\AiCredit;
use App\Models\DevUsage;
use App\Models\HubFirm;
use App\Models\HubLedgerEntry;
use App\Models\HubPhone;
use App\Support\ExchangeRates;
use App\Support\FirmPlan;
use App\Support\HubCosts;
use App\Support\HubReport;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Hub Rapor (29.10): abonelik (deneme / aylık / yıllık), ödendi mi, gecikme, durdurma; ay ay kâr (TL, TCMB kuru),
 * sabit giderin eşit payı, kredi yüklemesi gider DEĞİL, geliştirme ayrı satır.
 */
class HubReportTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake(['www.tcmb.gov.tr/*' => Http::response('<Tarih_Date><Currency Kod="USD"><ForexSelling>40.00</ForexSelling></Currency>'
            . '<Currency Kod="EUR"><ForexSelling>45.00</ForexSelling></Currency></Tarih_Date>')]);
        // Yereldeki diğer firmalar hesaba karışmasın.
        HubFirm::query()->update(['is_active' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function firm(array $attrs = []): HubFirm
    {
        return HubFirm::create(array_merge(['name' => 'Rapor Test ' . uniqid(), 'url' => 'https://rapor.test/'], $attrs));
    }

    private function pay(HubFirm $f, float $amount, string $from, string $to): void
    {
        HubLedgerEntry::create(['kind' => HubLedgerEntry::PAYMENT, 'hub_firm_id' => $f->id, 'amount' => $amount, 'currency' => 'TRY',
            'starts_on' => $from, 'ends_on' => $to, 'paid_on' => $from]);
    }

    public function test_monthly_plan_overdue_then_paid(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00', 'Europe/Istanbul'));
        $f = $this->firm(['plan' => 'monthly', 'plan_amount' => 1500, 'plan_started_on' => '2026-11-01', 'plan_day' => 5]);

        $this->assertSame(['danger', '🔴 Kasım 2026 gecikti (5 gün)', 5], FirmPlan::status($f));
        $this->assertSame('Kasım 2026', FirmPlan::periodLabel($f, FirmPlan::nextUnpaid($f)));

        $this->pay($f, 1500, '2026-11-01', '2026-11-30');
        $this->assertSame('✅ Kasım 2026 ödendi', FirmPlan::status($f)[1]);
        $this->assertSame('Aralık 2026', FirmPlan::periodLabel($f, FirmPlan::nextUnpaid($f)));
        $this->assertSame([1500.0, 0.0], FirmPlan::monthRevenue($f, Carbon::parse('2026-11-01')));
        $this->assertSame([0.0, 1500.0], FirmPlan::monthRevenue($f, Carbon::parse('2026-12-01')), 'Aralık ödenmedi → bekleyen');
    }

    public function test_yearly_payment_is_spread_over_twelve_months(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03 12:00', 'Europe/Istanbul'));
        $f = $this->firm(['plan' => 'yearly', 'plan_amount' => 12000, 'plan_started_on' => '2026-11-01']);
        $this->pay($f, 12000, '2026-11-01', '2027-10-31');

        $this->assertSame([1000.0, 0.0], FirmPlan::monthRevenue($f, Carbon::parse('2026-11-01')));
        $this->assertSame([1000.0, 0.0], FirmPlan::monthRevenue($f, Carbon::parse('2027-10-01')));
        $this->assertSame([0.0, 1000.0], FirmPlan::monthRevenue($f, Carbon::parse('2027-11-01')), 'ikinci yıl ödenmedi');
        $this->assertStringContainsString('ödendi', FirmPlan::status($f)[1]);
    }

    public function test_trial_status(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00', 'Europe/Istanbul'));
        $this->assertSame('🧪 Deneme — 3 gün kaldı', FirmPlan::status($this->firm(['plan' => 'trial', 'trial_ends_on' => '2026-11-13']))[1]);
        $this->assertSame('🧪 Deneme bitti (2 gün önce)', FirmPlan::status($this->firm(['plan' => 'trial', 'trial_ends_on' => '2026-11-08']))[1]);
    }

    public function test_month_report_profit_fixed_share_dev_and_topup(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-20 12:00', 'Europe/Istanbul'));
        $a = $this->firm(['plan' => 'monthly', 'plan_amount' => 1500, 'plan_started_on' => '2026-11-01']);
        $b = $this->firm(['plan' => 'free']);
        $this->pay($a, 1500, '2026-11-01', '2026-11-30');
        HubLedgerEntry::create(['kind' => HubLedgerEntry::FIXED, 'title' => 'Sunucu', 'amount' => 10, 'currency' => 'USD', 'period' => 'monthly', 'starts_on' => '2026-11-01']);
        HubLedgerEntry::create(['kind' => HubLedgerEntry::FIXED, 'title' => 'Alan adı', 'amount' => 1200, 'currency' => 'TRY', 'period' => 'yearly', 'starts_on' => '2026-11-01']);
        HubLedgerEntry::create(['kind' => HubLedgerEntry::FIXED, 'title' => 'Eski', 'amount' => 999, 'currency' => 'TRY', 'period' => 'monthly',
            'starts_on' => '2026-01-01', 'ends_on' => '2026-10-31']);
        HubLedgerEntry::create(['kind' => HubLedgerEntry::TOPUP, 'provider' => 'anthropic', 'amount' => 40, 'currency' => 'USD', 'starts_on' => '2026-11-12']);
        DevUsage::create(['model' => 'x', 'cost_usd' => 0.5]);

        $r = HubReport::compute('2026-11');

        $this->assertSame(40.0, $r['rates']['USD']);
        $this->assertEqualsWithDelta(500.0, array_sum(array_column($r['fixed'], 'monthly_try')), 0.01, '10 $ × 40 + 1200/12; biten gider girmez');
        $rowA = collect($r['rows'])->firstWhere('id', $a->id);
        $rowB = collect($r['rows'])->firstWhere('id', $b->id);
        $this->assertEqualsWithDelta(250.0, $rowA['fixed'], 0.01, 'iki aktif firmaya eşit');
        $this->assertEqualsWithDelta(1500 - 250, $rowA['profit'], 0.01);
        $this->assertEqualsWithDelta(-250, $rowB['profit'], 0.01);
        $this->assertEqualsWithDelta(20.0, $r['dev'], 0.01, '0,5 $ × 40');
        $this->assertCount(1, $r['topups']);
        $this->assertEqualsWithDelta(1500 - 500 - 20, $r['total']['profit'], 0.01, 'yükleme gidere girmez');
    }

    public function test_report_starts_in_november_2026(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-29 12:00', 'Europe/Istanbul'));
        $this->assertSame(['2026-11'], HubReport::months());
        Carbon::setTestNow(Carbon::parse('2027-01-05 12:00', 'Europe/Istanbul'));
        $this->assertSame(['2027-01', '2026-12', '2026-11'], HubReport::months());
    }

    public function test_rates_fall_back_to_last_known(): void
    {
        ExchangeRates::forMonth(Carbon::parse('2026-11-01'));
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response('', 500)]);
        Cache::forget('hub-rate:' . now('Europe/Istanbul')->toDateString());

        $this->assertSame(40.0, ExchangeRates::forMonth(now())['USD']);
    }

    public function test_anthropic_topup_adds_to_remaining(): void
    {
        Carbon::setTestNow('2026-11-14 16:00');
        AiCredit::create(['balance_usd' => 10]);
        Carbon::setTestNow('2026-11-14 17:00');
        HubLedgerEntry::create(['kind' => HubLedgerEntry::TOPUP, 'provider' => 'anthropic', 'amount' => 20, 'currency' => 'USD', 'starts_on' => '2026-11-14']);
        HubLedgerEntry::create(['kind' => HubLedgerEntry::TOPUP, 'provider' => 'twilio', 'amount' => 99, 'currency' => 'USD', 'starts_on' => '2026-11-14']);

        $credit = HubCosts::credit();
        $this->assertEqualsWithDelta(20.0, $credit['topups'], 0.001, 'Twilio yüklemesi Anthropic bakiyesine girmez');
        $this->assertEqualsWithDelta(30.0 - $credit['spent'], $credit['remaining'], 0.001);
    }

    public function test_suspended_firm_gets_payment_message_and_resume_clears(): void
    {
        config(['app.role' => 'hub', 'services.twilio.verify_signature' => false]);
        $f = $this->firm(['is_active' => false, 'suspended_at' => now()]);
        HubPhone::create(['phone' => '05321239876', 'hub_firm_id' => $f->id]);
        Http::fake();

        $res = $this->post('/whatsapp/webhook', ['From' => 'whatsapp:+905321239876', 'Body' => 'x'])->getContent();
        $this->assertStringContainsString('ödeme beklediği için şu an durduruldu', html_entity_decode($res));
        $this->assertStringNotContainsString('bir hesaba bağlı değil', $res);

        $f->update(['is_active' => true]);
        $this->assertNull($f->fresh()->suspended_at, 'Aktif açılınca durdurma kalkar');
        $this->assertSame('⛔ Ödeme yok — durduruldu', FirmPlan::status($this->firm(['is_active' => false, 'suspended_at' => now()]))[1]);
    }

    private function hubLogin(): void
    {
        if (! isset(\Filament\Facades\Filament::getPanels()['hub'])) {
            $this->app->register(\App\Providers\Filament\HubPanelProvider::class);
        }
        \Filament\Facades\Filament::setCurrentPanel('hub');
        $this->actingAs(\App\Models\HubUser::forceCreate(['name' => 'Hub', 'email' => 'hub-' . uniqid() . '@example.com', 'password' => 'x']), 'hub');
    }

    public function test_report_page_lists_months_and_shows_detail(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-20 12:00', 'Europe/Istanbul'));
        $this->hubLogin();
        $f = $this->firm(['plan' => 'monthly', 'plan_amount' => 1500, 'plan_started_on' => '2026-11-01']);
        $this->pay($f, 1500, '2026-11-01', '2026-11-30');

        \Livewire\Livewire::test(\App\Filament\Hub\Pages\CostOverview::class)
            ->assertOk()->assertSee('Kasım 2026')->assertSee('Kâr / zarar')->assertSee('Kredi yükledim');
        \Livewire\Livewire::withQueryParams(['ay' => '2026-11'])->test(\App\Filament\Hub\Pages\CostOverview::class)
            ->assertOk()->assertSee($f->name)->assertSee('1.500,00 ₺')->assertSee('Geliştirme')->assertSee('Sabit giderler bu ay');
    }

    public function test_firm_subscription_menu_shows_status_and_actions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00', 'Europe/Istanbul'));
        $f = $this->firm(['plan' => 'monthly', 'plan_amount' => 900, 'plan_started_on' => '2026-11-01', 'plan_day' => 5]);

        $group = \App\Filament\Hub\HubFirms\PlanActions::group($f);
        $this->assertSame('Abonelik · 🔴 Kasım 2026 gecikti (5 gün)', $group->getLabel());
        $names = array_map(fn ($a) => $a->getName(), $group->getActions());
        $this->assertSame(['plan', 'paid', 'suspend', 'resume'], $names);
    }
}
