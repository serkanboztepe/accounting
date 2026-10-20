<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\ProjectComparisonTable;
use App\Filament\Widgets\PurchaseOverviewCard;
use App\Filament\Widgets\TopPurchasePartiesTable;
use App\Filament\Widgets\TopSuppliersTable;
use App\Filament\Widgets\UnpaidContractBalancesTable;
use App\Models\Check;
use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Models\User;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sade müteahhit ana sayfası (kullanıcı 20.10: "benzer olsun, sadece sözleşmeleri çıkaralım"): aynı sayfa; sözleşmeye
 * dayanan bölümler yok, maliyet = gider + cari alış (Proje Raporu ile aynı), ödenecek = açık cari borcu + bekleyen çek.
 */
class SimpleContractorDashboardTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_simple_contractor_widgets_without_contracts(): void
    {
        config(['modules' => ModuleProfiles::resolve('muteahhit'), 'app.profile' => 'muteahhit']);

        $widgets = (new Dashboard)->getWidgets();

        $this->assertContains(PurchaseOverviewCard::class, $widgets);
        $this->assertContains(ProjectComparisonTable::class, $widgets);
        $this->assertContains(TopPurchasePartiesTable::class, $widgets);
        $this->assertNotContains(UnpaidContractBalancesTable::class, $widgets);
        $this->assertNotContains(TopSuppliersTable::class, $widgets);
    }

    public function test_contract_firm_keeps_old_dashboard(): void
    {
        config(['modules' => ModuleProfiles::resolve('muteahhit', ['contracts' => true, 'purchase_contracts' => true])]);

        $widgets = (new Dashboard)->getWidgets();

        $this->assertContains(UnpaidContractBalancesTable::class, $widgets);
        $this->assertContains(TopSuppliersTable::class, $widgets);
        $this->assertNotContains(TopPurchasePartiesTable::class, $widgets);
        $this->assertArrayNotHasKey('simple', (new PurchaseOverviewCard)->getData());
    }

    public function test_overview_numbers_match_project_cost_and_balances(): void
    {
        config(['modules' => ModuleProfiles::resolve('muteahhit'), 'app.profile' => 'muteahhit']);
        $before = (new PurchaseOverviewCard)->getData();
        $project = Project::create(['name' => 'Ana Sayfa Testi Fatih', 'status' => 'active']);
        $beton = Party::create(['name' => 'Ana Sayfa Testi Beton']);
        $entry = fn (string $type, float $amount, ?int $projectId = null) => PartyLedgerEntry::create(['party_id' => $beton->id,
            'project_id' => $projectId, 'type' => $type, 'amount' => $amount, 'entry_date' => now()->toDateString(), 'description' => 'x']);

        Expense::create(['amount' => 3000, 'expense_date' => now(), 'project_id' => $project->id, 'description' => 'Mazot', 'payment_status' => 'paid']);
        $entry(PartyLedgerEntry::TYPE_PURCHASE, 72000, $project->id);
        $pay = $entry(PartyLedgerEntry::TYPE_PAYMENT, 50000);   // 50.000 çekle ödendi → borç 22.000 kalır
        Check::create(['party_id' => $beton->id, 'party_ledger_entry_id' => $pay->id, 'issue_date' => now(), 'due_date' => now()->addMonth(),
            'amount' => 50000, 'status' => 'issued']);

        $d = (new PurchaseOverviewCard)->getData();

        $this->assertTrue($d['simple']);
        $this->assertEqualsWithDelta(75000, $d['total_cost'] - $before['total_cost'], 0.01);   // gider + alış; ödeme maliyet değil
        $this->assertEqualsWithDelta(22000, $d['payables'] - $before['payables'], 0.01);
        $this->assertEqualsWithDelta(50000, $d['pending_checks'] - $before['pending_checks'], 0.01);
        $this->assertEqualsWithDelta($d['payables'] + $d['pending_checks'], $d['total_due'], 0.01);

        Livewire::test(PurchaseOverviewCard::class)->assertSee('Genel Durum · Maliyet')->assertSee('Carilere Borcum')->assertDontSee('Ödenecek Nakit');
        Livewire::test(TopPurchasePartiesTable::class)->loadTable()->assertSee('Ana Sayfa Testi Beton')->assertSee('22.000,00');
        Livewire::test(ProjectComparisonTable::class)->loadTable()->assertSee('Ana Sayfa Testi Fatih')->assertSee('75.000,00')
            ->assertSee('Toplam Maliyet')->assertDontSee('Gerçek Ödenen');
    }
}
