<?php

namespace Tests\Feature;

use App\Filament\Resources\Parties\Pages\EditParty;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/** Cari → Satış / Alış penceresinde isteğe bağlı "Ödeme tarihi"; tahsilat/ödemede yok. */
class LedgerDueDateTest extends TestCase
{
    use DatabaseTransactions;

    public function test_due_date_field_only_on_sale_and_purchase(): void
    {
        $this->actingAs(User::factory()->create());
        config(['modules.cari_supplier' => true]);
        $party = Party::create(['name' => 'Vade Test Ali']);

        $page = Livewire::test(EditParty::class, ['record' => $party->getRouteKey()]);
        foreach (['satis', 'alis'] as $type) {
            $page->mountAction('newLedgerEntry', ['type' => $type])->assertFormFieldExists('due_date')->unmountAction();
        }
        foreach (['tahsilat', 'odeme'] as $type) {
            $page->mountAction('newLedgerEntry', ['type' => $type])->assertFormFieldDoesNotExist('due_date')->unmountAction();
        }
    }

    public function test_sale_with_due_date_is_saved_from_the_window(): void
    {
        $this->actingAs(User::factory()->create());
        $party = Party::create(['name' => 'Vade Test Kayıt']);

        Livewire::test(EditParty::class, ['record' => $party->getRouteKey()])
            ->mountAction('newLedgerEntry', ['type' => 'satis'])
            ->fillForm(['entry_date' => '2026-10-10', 'amount' => '45.000,00', 'description' => 'Bal', 'due_date' => '2026-11-14'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $entry = PartyLedgerEntry::where('party_id', $party->id)->sole();
        $this->assertSame(PartyLedgerEntry::TYPE_SALE, $entry->type);
        $this->assertSame('2026-11-14', $entry->due_date->toDateString());
        $this->assertEqualsWithDelta(45000, (float) $entry->amount, 0.001);
    }

    public function test_edit_form_is_filled_with_existing_due_date(): void
    {
        $this->actingAs(User::factory()->create());
        $party = Party::create(['name' => 'Vade Test Veli']);
        $entry = PartyLedgerEntry::create([
            'party_id' => $party->id, 'entry_date' => '2026-10-10', 'due_date' => '2026-11-14',
            'type' => PartyLedgerEntry::TYPE_SALE, 'amount' => 45000, 'description' => 'Bal',
        ]);

        $this->assertSame('2026-11-14', $entry->fresh()->due_date->toDateString());

        Livewire::test(EditParty::class, ['record' => $party->getRouteKey()])
            ->mountAction('editLedgerEntry', ['entry' => $entry->id])
            ->assertFormFieldVisible('due_date')
            ->assertSet('mountedActions.0.data.due_date', '2026-11-14');

        // Tahsilat satırında alan görünmez.
        $collection = PartyLedgerEntry::create([
            'party_id' => $party->id, 'entry_date' => '2026-10-11', 'type' => PartyLedgerEntry::TYPE_COLLECTION, 'amount' => 1000,
        ]);
        Livewire::test(EditParty::class, ['record' => $party->getRouteKey()])
            ->mountAction('editLedgerEntry', ['entry' => $collection->id])
            ->assertFormFieldHidden('due_date');
    }
}
