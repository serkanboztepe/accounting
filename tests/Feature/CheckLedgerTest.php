<?php

namespace Tests\Feature;

use App\Filament\Resources\Checks\Pages\CreateCheck;
use App\Filament\Resources\Checks\Pages\EditCheck;
use App\Models\Check;
use App\Models\Contract;
use App\Models\ContractPayment;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Models\User;
use App\Support\CheckLedger;
use App\Support\CheckRemoval;
use App\Support\ModuleProfiles;
use App\Support\PartyStatement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Çekler ekranından girilen çek cariye işlenir (23.10 "tasarım hatası", 26.10 düzeltme): verilen = ödeme, alınan = tahsilat;
 * WhatsApp / "Ödeme Yaptım" ile aynı yapı (çek satıra bağlı), "Çeki sil" ikisini birlikte geri alır.
 */
class CheckLedgerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('muteahhit', ['checks' => true])]);
        $this->actingAs(User::factory()->create());
    }

    private function given(Party $party, float $amount = 650000, array $extra = []): Check
    {
        return Check::create(['party_id' => $party->id, 'amount' => $amount, 'due_date' => '2026-12-15',
            'issue_date' => '2026-10-26', 'check_number' => 'A-1', 'status' => 'issued'] + $extra);
    }

    public function test_given_check_becomes_payment_and_reduces_our_debt(): void
    {
        $party = Party::create(['name' => 'Mehmet Uçar Deneme']);
        PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => '2026-10-01', 'type' => PartyLedgerEntry::TYPE_PURCHASE, 'amount' => 1000000]);
        $this->assertSame(-1000000.0, PartyStatement::build($party)['balance'], 'biz borçluyuz');

        $entry = CheckLedger::attach($check = $this->given($party));

        $this->assertSame(PartyLedgerEntry::TYPE_PAYMENT, $entry->type);
        $this->assertSame('check', $entry->payment_type);
        $this->assertSame('2026-10-26', $entry->entry_date->toDateString(), 'çek verildiği gün');
        $this->assertSame($entry->id, $check->fresh()->party_ledger_entry_id);
        $this->assertSame(-350000.0, PartyStatement::build($party)['balance'], 'borç çek kadar düştü');
        $this->assertSame('Çek no A-1 vade 15.12.2026', $entry->description, 'ekstrede çek no + vade');
    }

    public function test_received_check_becomes_collection(): void
    {
        $party = Party::create(['name' => 'Alınan Çek Müşteri']);
        $entry = CheckLedger::attach(Check::create(['party_id' => $party->id, 'amount' => 20000, 'due_date' => '2026-11-30', 'status' => 'portfolio']));

        $this->assertSame(PartyLedgerEntry::TYPE_COLLECTION, $entry->type);
    }

    public function test_contract_check_and_already_linked_check_are_not_written_again(): void
    {
        $party = Party::create(['name' => 'Sözleşmeli Çek']);
        $project = Project::firstOrCreate(['name' => 'Çek Ledger Proje'], ['status' => 'active']);
        $contract = Contract::create(['project_id' => $project->id, 'party_id' => $party->id, 'title' => 'Beton', 'contract_type' => 'supply',
            'direction' => Contract::DIRECTION_PURCHASE, 'status' => 'active', 'total_amount' => 100000]);
        $payment = ContractPayment::create(['contract_id' => $contract->id, 'amount' => 100000, 'payment_date' => now(),
            'due_date' => now()->addMonth(), 'payment_type' => 'check', 'status' => 'unpaid']);
        $check = $payment->checks()->first() ?? $this->given($party, 100000, ['contract_payment_id' => $payment->id]);

        $this->assertNull(CheckLedger::attach($check), 'sözleşme kendi ödemesini tutar');

        $linked = $this->given($party, 5000);
        CheckLedger::attach($linked);
        $this->assertNull(CheckLedger::attach($linked->fresh()), 'ikinci kez yazılmaz');
        $this->assertSame(1, PartyLedgerEntry::where('party_id', $party->id)->where('type', PartyLedgerEntry::TYPE_PAYMENT)->count());
    }

    public function test_edit_amount_moves_only_the_difference(): void
    {
        $party = Party::create(['name' => 'Çek + Nakit']);
        // WhatsApp: 100.000 ödeme = 60.000 çek + 40.000 nakit, tek satır.
        $entry = PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => '2026-10-20', 'type' => PartyLedgerEntry::TYPE_PAYMENT,
            'payment_type' => 'check', 'amount' => 100000]);
        $check = $this->given($party, 60000, ['party_ledger_entry_id' => $entry->id]);

        $check->update(['amount' => 70000]);
        CheckLedger::sync($check);

        $this->assertEquals(110000.0, (float) $entry->fresh()->amount, 'nakit kısım (40.000) korunur');
    }

    public function test_edit_party_and_direction_follow_on_single_check_entry(): void
    {
        $a = Party::create(['name' => 'Yanlış Cari']);
        $b = Party::create(['name' => 'Doğru Cari']);
        $check = $this->given($a, 8000);
        $entry = CheckLedger::attach($check);

        $check->update(['party_id' => $b->id, 'status' => 'portfolio', 'issue_date' => '2026-10-25']);
        CheckLedger::sync($check);

        $entry->refresh();
        $this->assertSame($b->id, $entry->party_id);
        $this->assertSame(PartyLedgerEntry::TYPE_COLLECTION, $entry->type);
        $this->assertSame('2026-10-25', $entry->entry_date->toDateString());
    }

    public function test_remove_takes_back_the_payment(): void
    {
        $party = Party::create(['name' => 'Silinecek Çek']);
        $check = $this->given($party, 3000);
        $entry = CheckLedger::attach($check);

        CheckRemoval::remove($check->fresh());

        $this->assertNull(PartyLedgerEntry::find($entry->id));
        $this->assertSame(0.0, PartyStatement::build($party)['balance']);
    }

    public function test_create_screen_with_party_always_writes_ledger(): void
    {
        $party = Party::create(['name' => 'Ekrandan Çek']);

        Livewire::test(CreateCheck::class)
            ->fillForm(['direction' => 'given', 'status' => 'issued', 'party_id' => $party->id, 'due_date' => '2026-12-01', 'amount' => '12.500,00'])
            ->assertSee('Kaydedince cariye ÖDEME olarak işlenir')
            ->call('create')
            ->assertHasNoFormErrors();

        $check = Check::where('party_id', $party->id)->first();
        $this->assertNotNull($check?->party_ledger_entry_id, 'cariye işlendi (seçenek yok, her zaman)');
        $this->assertSame(12500.0, PartyStatement::build($party)['balance'], 'ödeme satırı cariyi borçlandırır');
    }

    public function test_create_screen_without_party_is_reminder_only(): void
    {
        $before = PartyLedgerEntry::count();

        Livewire::test(CreateCheck::class)
            ->fillForm(['direction' => 'received', 'status' => 'portfolio', 'due_date' => '2026-12-01', 'amount' => '1.000,00', 'check_number' => 'CARISIZ-1'])
            ->assertSee('Carisiz çek — cariye işlenmez')
            ->call('create')
            ->assertHasNoFormErrors();

        $check = Check::where('check_number', 'CARISIZ-1')->sole();
        $this->assertNull($check->party_id);
        $this->assertNull($check->party_ledger_entry_id);
        $this->assertSame($before, PartyLedgerEntry::count(), 'cariye satır açılmaz');
        $this->assertTrue(\App\Support\CheckReminders::upcoming()->whereKey($check->id)->exists(), 'hatırlatma olarak durur');
    }

    public function test_old_unlinked_check_is_untouched_until_saved(): void
    {
        $party = Party::create(['name' => 'Eski Çek']);
        $check = $this->given($party, 500000);   // sistem kendiliğinden bağlamaz

        $this->assertNull($check->fresh()->party_ledger_entry_id);

        Livewire::test(EditCheck::class, ['record' => $check->getKey()])
            ->fillForm(['description' => 'Mimarsinan'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertNotNull($check->fresh()->party_ledger_entry_id, 'kullanıcı kaydedince kural uygulanır');
    }

    public function test_removing_party_on_edit_removes_it_from_ledger(): void
    {
        $party = Party::create(['name' => 'Carisi Kaldırılan']);
        $check = $this->given($party, 7000);
        $entry = CheckLedger::attach($check);

        Livewire::test(EditCheck::class, ['record' => $check->getKey()])
            ->fillForm(['party_id' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull(PartyLedgerEntry::find($entry->id), 'cariden silindi');
        $this->assertNull($check->fresh()->party_ledger_entry_id);
        $this->assertSame(0.0, PartyStatement::build($party)['balance']);
    }
}
