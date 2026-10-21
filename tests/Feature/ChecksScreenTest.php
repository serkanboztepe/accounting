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

    /** Faz 2.3: cari sayfasında "Ödeme Yaptım" + çek(ler) → ödemeye bağlı "verildi" çekler; ekstrede vade. */
    public function test_payment_with_cheques_from_party_page(): void
    {
        $party = Party::create(['name' => 'Çek Ekranı Osman']);

        Livewire::test(\App\Filament\Resources\Parties\Pages\EditParty::class, ['record' => $party->getKey()])
            ->mountAction('newLedgerEntry', ['type' => 'odeme'])
            ->setActionData(['entry_date' => '2026-10-17', 'amount' => '670.000', 'payment_type' => 'check',
                'cheques' => [['check_number' => '8037567', 'bank_name' => 'Halkbank', 'due_date' => '2027-03-31', 'amount' => '335.000'],
                    ['check_number' => '8037568', 'bank_name' => 'Halkbank', 'due_date' => '2027-04-30', 'amount' => '335.000']]])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $entry = \App\Models\PartyLedgerEntry::where('party_id', $party->id)->sole();
        $this->assertSame('check', $entry->payment_type);
        $this->assertEqualsWithDelta(670000, (float) $entry->amount, 0.01);
        $this->assertSame(['8037567', '8037568'], $entry->checks()->orderBy('check_number')->pluck('check_number')->all());
        $this->assertSame(['issued', 'issued'], $entry->checks()->pluck('status')->all());
        $this->assertEqualsWithDelta(335000, (float) $entry->checks()->first()->amount, 0.01);

        $desc = collect(\App\Support\PartyStatement::build($party)['rows'])->pluck('desc')->implode(' ');
        $this->assertStringContainsString('vade 31.03.2027', $desc, 'ekstrede çek vadesi');
    }

    public function test_cheque_total_mismatch_is_not_saved(): void
    {
        $party = Party::create(['name' => 'Çek Ekranı Uyuşmaz']);

        Livewire::test(\App\Filament\Resources\Parties\Pages\EditParty::class, ['record' => $party->getKey()])
            ->mountAction('newLedgerEntry', ['type' => 'odeme'])
            ->setActionData(['entry_date' => '2026-10-17', 'amount' => '670.000', 'payment_type' => 'check',
                'cheques' => [['due_date' => '2027-03-31', 'amount' => '300.000']]])
            ->callMountedAction();

        $this->assertSame(0, \App\Models\PartyLedgerEntry::where('party_id', $party->id)->count());
    }

    /** 17.10: karşılıksız/iptal yok → "Çeki sil" bağlı ödemeyle birlikte geri alır; çok çekli ödemede yalnız o çekin tutarı düşer. */
    public function test_remove_check_reverses_its_payment(): void
    {
        $party = Party::create(['name' => 'Çek Ekranı Silme']);
        $entry = \App\Models\PartyLedgerEntry::create(['party_id' => $party->id, 'type' => \App\Models\PartyLedgerEntry::TYPE_PAYMENT,
            'amount' => 670000, 'entry_date' => now()->toDateString(), 'payment_type' => 'check']);
        $c1 = Check::create(['party_id' => $party->id, 'party_ledger_entry_id' => $entry->id, 'amount' => 335000, 'due_date' => now()->addMonth(), 'status' => 'issued']);
        $c2 = Check::create(['party_id' => $party->id, 'party_ledger_entry_id' => $entry->id, 'amount' => 335000, 'due_date' => now()->addMonths(2), 'status' => 'issued']);

        Livewire::test(ListChecks::class)->callTableAction('remove', $c1);
        $this->assertNull(Check::find($c1->id));
        $this->assertEqualsWithDelta(335000, (float) $entry->fresh()->amount, 0.01, 'iki çekli ödemeden yalnız bu çekin tutarı düşer');

        \App\Support\CheckRemoval::remove($c2->fresh());
        $this->assertNull(\App\Models\PartyLedgerEntry::find($entry->id), 'son çek silinince ödeme satırı da gider');
        $this->assertEqualsWithDelta(0, \App\Support\PartyStatement::build($party)['balance'], 0.01, 'cari etkisiz');
    }

    /** Firma 7, 21.10: cari sayfasından çekli ödeme silindi, çekler Çekler ekranında kaldı. */
    public function test_deleting_payment_deletes_its_cheques(): void
    {
        $party = Party::create(['name' => 'Çek Ekranı Silme']);
        $entry = \App\Models\PartyLedgerEntry::create(['party_id' => $party->id, 'type' => \App\Models\PartyLedgerEntry::TYPE_PAYMENT,
            'payment_type' => 'check', 'amount' => 200000, 'entry_date' => now()->toDateString(), 'description' => 'Avans — 2 çek']);
        foreach (['2027-02-28', '2027-03-31'] as $i => $due) {
            Check::create(['party_id' => $party->id, 'party_ledger_entry_id' => $entry->id, 'check_number' => 'SIL' . $i,
                'issue_date' => now(), 'due_date' => $due, 'amount' => 100000, 'status' => 'issued']);
        }
        $other = Check::create(['party_id' => $party->id, 'check_number' => 'KALIR', 'issue_date' => now(), 'due_date' => '2027-04-30',
            'amount' => 5000, 'status' => 'issued']);

        $entry->delete();

        $this->assertSame(0, Check::whereIn('check_number', ['SIL0', 'SIL1'])->count());
        $this->assertTrue($other->exists(), 'bağsız çek kalır');
        $this->assertSame(1, Check::where('party_id', $party->id)->count());
    }

    public function test_form_offers_only_two_states_per_direction(): void
    {
        Livewire::test(CreateCheck::class)
            ->assertFormFieldExists('status', fn ($field) => array_keys($field->getOptions()) === ['issued', 'paid']);
    }
}
