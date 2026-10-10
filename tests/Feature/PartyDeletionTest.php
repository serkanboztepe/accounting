<?php

namespace Tests\Feature;

use App\Filament\Resources\Parties\Pages\EditParty;
use App\Models\Check;
use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\User;
use App\Support\PartyDeletion;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/** Cari silme: hareketleri de silineceği açıkça söylenir; çek/sözleşme/teklif varsa silinmez (eskiden veritabanı hatası). */
class PartyDeletionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_description_lists_what_will_be_deleted(): void
    {
        $empty = Party::create(['name' => 'Silme Test Boş']);
        $this->assertSame('Bu carinin hiç hareketi yok, güvenle silinebilir.', PartyDeletion::description($empty));

        $burak = Party::create(['name' => 'Silme Test Burak']);
        PartyLedgerEntry::create(['party_id' => $burak->id, 'entry_date' => '2026-10-10', 'type' => PartyLedgerEntry::TYPE_COLLECTION, 'amount' => 20000]);
        Expense::create(['expense_date' => '2026-10-10', 'amount' => 500, 'payment_status' => 'paid', 'party_id' => $burak->id]);

        $text = PartyDeletion::description($burak);
        $this->assertStringContainsString('1 cari hareketi de SİLİNECEK (Silme Test Burak: borcun 20.000,00 ₺)', $text);
        $this->assertStringContainsString('1 gider silinmez, carisiz kalır.', $text);
    }

    public function test_party_with_check_cannot_be_deleted_and_shows_reason(): void
    {
        $this->actingAs(User::factory()->create());
        $party = Party::create(['name' => 'Silme Test Çekli']);
        Check::create(['party_id' => $party->id, 'due_date' => '2031-01-01', 'amount' => 1000, 'status' => 'issued', 'check_number' => 'SIL1']);

        $this->assertSame(['1 çek'], PartyDeletion::blockers($party));

        Livewire::test(EditParty::class, ['record' => $party->getRouteKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified('Cari silinemedi');

        $this->assertNotNull($party->fresh());
    }

    public function test_party_without_blockers_is_deleted(): void
    {
        $this->actingAs(User::factory()->create());
        $party = Party::create(['name' => 'Silme Test Serbest']);

        Livewire::test(EditParty::class, ['record' => $party->getRouteKey()])->callAction(DeleteAction::class);

        $this->assertNull($party->fresh());
    }
}
