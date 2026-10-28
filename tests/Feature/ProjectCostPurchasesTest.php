<?php

namespace Tests\Feature;

use App\Filament\Pages\ProjectReports;
use App\Models\Contract;
use App\Models\ContractDelivery;
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
 * Sade müteahhit (20.10): proje maliyeti = projeli gider + projeli cari alışı (iade düşer); ödeme ve avans maliyet değil.
 * Sözleşmeli firmada (Muhasebe) teslimatlar da eklenir; aynı cari + tutar hem teslimat hem alışsa uyarı.
 */
class ProjectCostPurchasesTest extends TestCase
{
    use DatabaseTransactions;

    private function ledger(Party $party, ?Project $project, string $type, float $amount, string $desc = 'İş'): void
    {
        PartyLedgerEntry::create(['party_id' => $party->id, 'project_id' => $project?->id, 'type' => $type,
            'amount' => $amount, 'entry_date' => now()->toDateString(), 'description' => $desc]);
    }

    public function test_cost_is_expenses_plus_purchases_not_payments(): void
    {
        config(['modules' => ModuleProfiles::resolve('muteahhit'), 'app.profile' => 'muteahhit']);
        $this->actingAs(User::factory()->create());
        $fatih = Project::create(['name' => 'Maliyet Testi Fatih', 'status' => 'active']);
        $other = Project::create(['name' => 'Maliyet Testi Atatürk', 'status' => 'active']);
        $beton = Party::create(['name' => 'Maliyet Testi Beton']);
        $usta = Party::create(['name' => 'Maliyet Testi Usta']);

        Expense::create(['amount' => 3000, 'expense_date' => now(), 'project_id' => $fatih->id, 'description' => 'Mazot', 'payment_status' => 'paid']);
        $this->ledger($beton, $fatih, PartyLedgerEntry::TYPE_PURCHASE, 72000, '30 m³ beton');
        $this->ledger($beton, $fatih, PartyLedgerEntry::TYPE_PURCHASE_RETURN, 2000, 'Fazla beton iadesi');
        $this->ledger($usta, $fatih, PartyLedgerEntry::TYPE_PURCHASE, 80000, 'Sıva');
        $this->ledger($usta, $fatih, PartyLedgerEntry::TYPE_PAYMENT, 50000, 'Avans');      // maliyet değil
        $this->ledger($beton, null, PartyLedgerEntry::TYPE_PAYMENT, 72000, 'Ödeme');        // maliyet değil
        $this->ledger($usta, $other, PartyLedgerEntry::TYPE_PURCHASE, 10000, 'Başka şantiye'); // başka proje
        $this->ledger($usta, null, PartyLedgerEntry::TYPE_PURCHASE, 5000, 'Projesiz');      // genel

        $page = Livewire::test(ProjectReports::class)->set('projectId', $fatih->id);
        $stats = $page->instance()->getSummaryStats();

        $this->assertEqualsWithDelta(150000, $stats['purchase_cost'], 0.01);
        $this->assertEqualsWithDelta(153000, $stats['total_cost'], 0.01);
        $rows = $page->instance()->getPurchaseRows();
        $this->assertSame(['Maliyet Testi Usta', 'Maliyet Testi Beton'], array_column($rows, 'party'));
        $this->assertEqualsWithDelta(70000, $rows[1]['total'], 0.01);

        $page->assertSee('Alışlar')->assertSee('Alış (iş / malzeme)')->assertSee('153.000,00')
            ->assertDontSee('Sözleşme Teslimatları')->assertDontSee('Faturasız');
    }

    public function test_contract_firm_keeps_deliveries_and_flags_possible_double(): void
    {
        config(['modules' => ModuleProfiles::resolve('muteahhit', ['contracts' => true, 'purchase_contracts' => true, 'dashboard' => true])]);
        $this->actingAs(User::factory()->create());
        $party = Party::create(['name' => 'Maliyet Testi Demir']);
        $project = Project::create(['name' => 'Maliyet Testi B Blok', 'status' => 'active']);
        $contract = Contract::create(['project_id' => $project->id, 'party_id' => $party->id, 'title' => 'Demir alımı',
            'contract_type' => 'supplier', 'direction' => Contract::DIRECTION_PURCHASE, 'contract_date' => now(),
            'total_amount' => 40000, 'status' => 'active']);
        ContractDelivery::create(['contract_id' => $contract->id, 'project_id' => $project->id, 'delivery_date' => now(),
            'quantity' => 1, 'unit_price' => 40000, 'amount' => 40000]);
        $this->ledger($party, $project, PartyLedgerEntry::TYPE_PURCHASE, 40000, 'Demir');

        $page = Livewire::test(ProjectReports::class)->set('projectId', $project->id);

        $this->assertEqualsWithDelta(80000, $page->instance()->getSummaryStats()['total_cost'], 0.01);
        $this->assertTrue($page->instance()->getPurchaseRows()[0]['items'][0]['maybe_duplicate']);
        $page->assertSee('Sözleşme Teslimatları')->assertSee('iki kez girilmiş olabilir');
    }
}
