<?php

namespace Tests\Feature;

use App\Filament\Resources\Checks\Pages\CreateCheck;
use App\Filament\Resources\Checks\Pages\EditCheck;
use App\Filament\Resources\Checks\Pages\ListChecks;
use App\Models\Check;
use App\Models\Contract;
use App\Models\ContractPayment;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/** Faz 2.2 Çekler ekranı: yön + durum (veri aynı), modüle göre alanlar, satırda "Ödendi" (sözleşmeliyse ödeme de). */
class ChecksScreenTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('muteahhit', ['checks' => true])]);
        $this->actingAs(User::factory()->create());
    }

    public function test_form_shows_direction_and_hides_contract_without_module(): void
    {
        config(['modules.contracts' => false, 'modules.projects' => false]);
        Livewire::test(CreateCheck::class)
            ->assertFormFieldVisible('direction')
            ->assertFormFieldHidden('contract_payment_id')
            ->assertFormFieldHidden('project_id')
            ->assertFormSet(['direction' => 'given', 'status' => 'issued']);

        config(['modules.contracts' => true, 'modules.projects' => true]);
        Livewire::test(CreateCheck::class)->assertFormFieldVisible('contract_payment_id')->assertFormFieldVisible('project_id');
    }

    public function test_received_check_opens_as_received(): void
    {
        $party = Party::create(['name' => 'Çek Ekranı Müşteri']);
        $check = Check::create(['party_id' => $party->id, 'amount' => 5000, 'due_date' => now()->addDays(3), 'status' => 'portfolio']);

        Livewire::test(EditCheck::class, ['record' => $check->getKey()])->assertFormSet(['direction' => 'received', 'status' => 'portfolio']);
    }

    public function test_settle_marks_contract_payment_and_check_paid(): void
    {
        $party = Party::create(['name' => 'Çek Ekranı Kuşak']);
        $project = Project::firstOrCreate(['name' => 'Çek Ekranı Proje'], ['status' => 'active']);
        $contract = Contract::create(['project_id' => $project->id, 'party_id' => $party->id, 'title' => 'Beton', 'contract_type' => 'supply',
            'direction' => Contract::DIRECTION_PURCHASE, 'status' => 'active', 'total_amount' => 100000]);
        $payment = ContractPayment::create(['contract_id' => $contract->id, 'amount' => 100000, 'payment_date' => now()->subDays(30),
            'due_date' => now()->subDay(), 'payment_type' => 'check', 'status' => 'unpaid']);
        $check = $payment->checks()->first() ?? Check::create(['party_id' => $party->id, 'contract_payment_id' => $payment->id,
            'amount' => 100000, 'due_date' => now()->subDay(), 'status' => 'issued']);

        Livewire::test(ListChecks::class)->assertCanSeeTableRecords([$check])->callTableAction('settle', $check);

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('paid', $check->fresh()->status);
    }
}
