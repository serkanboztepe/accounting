<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Cari listesinde ekstre bakiyesi: "Durum" (Borçlu/Alacaklı/Kapalı) + "Tutar" sütunları. */
class PartyListBalanceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_list_shows_status_and_amount_columns(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Party::create(['name' => 'Liste Müşteri Ali']);
        $supplier = Party::create(['name' => 'Liste Tedarikçi Veli']);
        PartyLedgerEntry::create(['party_id' => $customer->id, 'type' => 'satis', 'amount' => 45000, 'entry_date' => now()]);
        PartyLedgerEntry::create(['party_id' => $customer->id, 'type' => 'tahsilat', 'amount' => 5000, 'entry_date' => now()]);
        PartyLedgerEntry::create(['party_id' => $supplier->id, 'type' => 'alis', 'amount' => 120500, 'entry_date' => now()]);

        Party::create(['name' => 'Liste Boş Hasan']);

        $this->get('/admin/parties')->assertOk()
            ->assertSeeInOrder(['Durum', 'Tutar'])
            ->assertSeeInOrder(['Liste Boş Hasan', 'Kapalı'])
            ->assertSeeInOrder(['Liste Müşteri Ali', 'Borçlu', '40.000,00 ₺'])
            ->assertSeeInOrder(['Liste Tedarikçi Veli', 'Alacaklı', '120.500,00 ₺']);
    }
}
