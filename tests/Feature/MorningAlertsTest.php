<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Sale;
use App\Support\ModuleProfiles;
use App\Support\MorningAlerts;
use App\Support\TradeDesk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Faz 4 (B): sabah haberi — girilmeyen kasa (alışkanlık varsa), Pazartesi haftalık özet; modül kapalıyken sessiz. */
class MorningAlertsTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('trade', ['projects' => true, 'proactive_alerts' => true]), 'app.profile' => 'trade',
            'services.whatsapp.reminder_phones' => ['905558880013']]);
        Project::query()->update(['status' => 'cancelled']);
        Sale::query()->update(['sale_date' => '2000-01-01']); // yerel eski kasalar alışkanlığa karışmasın (geri alınır)
        $this->today = Carbon::parse('2031-03-10'); // Pazartesi
    }

    private function till(Project $p, int $daysAgo, float $amount = 20000): void
    {
        TradeDesk::saveSale(['mode' => 'cash', 'manual_amount' => (string) $amount, 'project_id' => $p->id,
            'sale_date' => $this->today->copy()->subDays($daysAgo)->toDateString()]);
    }

    public function test_missing_till_only_with_habit(): void
    {
        $erg = Project::create(['name' => 'Sabah Ergenekon', 'status' => 'active']);
        $izz = Project::create(['name' => 'Sabah İzzetpaşa', 'status' => 'active']);
        $new = Project::create(['name' => 'Sabah Yeni', 'status' => 'active']);
        foreach ([2, 3, 4, 5, 6] as $d) {
            $this->till($erg, $d);           // alışkanlık var, dün YOK → uyarı
            $this->till($izz, $d);           // alışkanlık var, dün VAR → sessiz
        }
        $this->till($izz, 1);
        $this->till($new, 3);                // alışkanlık yok → sessiz

        $lines = collect(MorningAlerts::missingTills($this->today->copy()->subDay()))->pluck(0)->implode("\n");

        $this->assertStringContainsString('Sabah Ergenekon kasası girilmedi', $lines);
        $this->assertStringNotContainsString('İzzetpaşa', $lines);
        $this->assertStringNotContainsString('Sabah Yeni', $lines);
    }

    public function test_open_advance_asked_on_day_14_and_30_until_work_arrives(): void
    {
        $osman = \App\Models\Party::create(['name' => 'Sabah Avans Osman']);
        $usta = \App\Models\Party::create(['name' => 'Sabah Avans Personel', 'is_employee' => true]);
        foreach ([$osman, $usta] as $p) {
            \App\Models\PartyLedgerEntry::create(['party_id' => $p->id, 'type' => \App\Models\PartyLedgerEntry::TYPE_PAYMENT, 'amount' => 1005000,
                'entry_date' => $this->today->copy()->subDays(14)->toDateString(), 'description' => 'Avans — asansör']);
        }

        $day14 = implode("\n", MorningAlerts::openAdvances($this->today));
        $this->assertStringContainsString('Sabah Avans Osman', $day14);
        $this->assertStringContainsString("1.005.000,00 ₺'nin işi/malı geldi mi", $day14);
        $this->assertStringNotContainsString('Personel', $day14, 'personel avansı maaştan düşer, sorulmaz');
        $this->assertStringNotContainsString('Sabah Avans Osman', implode("\n", MorningAlerts::openAdvances($this->today->copy()->subDay())), '13. gün sessiz');
        $this->assertStringContainsString('Sabah Avans Osman', implode("\n", MorningAlerts::openAdvances($this->today->copy()->addDays(16))), '30. gün');

        // Asansör takıldı (alış avanstan düştü) → bir daha sorulmaz.
        \App\Models\PartyLedgerEntry::create(['party_id' => $osman->id, 'type' => \App\Models\PartyLedgerEntry::TYPE_PURCHASE, 'amount' => 1005000,
            'entry_date' => $this->today->copy()->subDays(2)->toDateString(), 'description' => 'Asansör']);
        $this->assertStringNotContainsString('Sabah Avans Osman', implode("\n", MorningAlerts::openAdvances($this->today->copy()->addDays(16))));
    }

    public function test_monday_weekly_summary_with_branches(): void
    {
        $a = Project::create(['name' => 'Hafta A', 'status' => 'active']);
        $b = Project::create(['name' => 'Hafta B', 'status' => 'active']);
        $this->till($a, 3, 30000);
        $this->till($b, 4, 10000);

        $text = MorningAlerts::weekly($this->today);

        $this->assertStringContainsString('Geçen hafta', $text);
        $this->assertStringContainsString('Ciro: 40.000,00', $text);
        $this->assertStringContainsString('Hafta A 30.000,00 · Hafta B 10.000,00', $text);
        $this->assertNull(MorningAlerts::weekly($this->today->copy()->addWeeks(3)), 'boş haftada özet yok');
    }

    public function test_command_off_module_and_dry_run(): void
    {
        Http::fake();
        config(['modules.proactive_alerts' => false]);
        $this->artisan('alerts:morning', ['--date' => $this->today->toDateString()])->expectsOutput('Kendiliğinden haber modülü kapalı.');

        config(['modules.proactive_alerts' => true]);
        $p = Project::create(['name' => 'Komut Şube', 'status' => 'active']);
        foreach ([2, 3, 4, 5] as $d) {
            $this->till($p, $d);
        }
        $this->artisan('alerts:morning', ['--date' => $this->today->toDateString(), '--dry-run' => true])
            ->expectsOutputToContain('Komut Şube kasası girilmedi');
        Http::assertNothingSent();
    }

    /** Ürünlü peşin satış (çiftlikte saman) kasa alışkanlığı sayılmaz — "kasa girilmedi" denmez. */
    public function test_product_sales_are_not_till_habit(): void
    {
        $unit = \App\Models\Unit::firstOrCreate(['code' => 'kg-sabah'], ['name' => 'kg']);
        $hay = \App\Models\Product::create(['name' => 'Sabah Saman', 'type' => \App\Models\Product::TYPE_PRODUCT, 'unit_id' => $unit->id]);
        config(['modules.projects' => false]);
        foreach ([2, 3, 4, 5, 6] as $d) {
            TradeDesk::saveSale(['mode' => 'cash', 'sale_date' => $this->today->copy()->subDays($d)->toDateString(),
                'lines' => [['product_id' => $hay->id, 'quantity' => '100', 'unit_price' => '5']]]);
        }

        $this->assertSame([], MorningAlerts::missingTills($this->today->copy()->subDay()));
    }
}
