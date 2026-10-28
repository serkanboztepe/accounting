<?php

namespace Tests\Feature;

use App\Filament\Resources\Parties\Pages\EditParty;
use App\Models\Check;
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
        $this->assertSame('Bu kişinin hiç hareketi yok, güvenle silinebilir.', PartyDeletion::description($empty));

        $burak = Party::create(['name' => 'Silme Test Burak']);
        PartyLedgerEntry::create(['party_id' => $burak->id, 'entry_date' => '2026-10-10', 'type' => PartyLedgerEntry::TYPE_COLLECTION, 'amount' => 20000]);

        $text = PartyDeletion::description($burak);
        $this->assertStringContainsString('1 hesap hareketi de SİLİNECEK (Silme Test Burak: borcun 20.000,00 ₺)', $text);
    }

    public function test_party_with_check_cannot_be_deleted_and_shows_reason(): void
    {
        $this->actingAs(User::factory()->create());
        $party = Party::create(['name' => 'Silme Test Çekli']);
        Check::create(['party_id' => $party->id, 'due_date' => '2031-01-01', 'amount' => 1000, 'status' => 'issued', 'check_number' => 'SIL1']);

        $this->assertSame(['1 çek'], PartyDeletion::blockers($party));

        Livewire::test(EditParty::class, ['record' => $party->getRouteKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified('Silinemedi');

        $this->assertNotNull($party->fresh());
    }

    public function test_party_without_blockers_is_deleted(): void
    {
        $this->actingAs(User::factory()->create());
        $party = Party::create(['name' => 'Silme Test Serbest']);

        Livewire::test(EditParty::class, ['record' => $party->getRouteKey()])
            ->callAction(DeleteAction::class, data: ['confirm_phrase' => 'Eminim Sil']);

        $this->assertNull($party->fresh());
    }

    // Kullanıcı 21.10: tek tıkla carinin tüm hareketleri gitmesin — "Eminim Sil" yazılmadan silinmez.
    public function test_party_is_not_deleted_without_typed_confirmation(): void
    {
        $this->actingAs(User::factory()->create());
        $party = Party::create(['name' => 'Silme Test Onaysız']);

        foreach ([null, 'sil', 'eminim', 'Eminim Sill'] as $typed) {
            Livewire::test(EditParty::class, ['record' => $party->getRouteKey()])
                ->callAction(DeleteAction::class, data: ['confirm_phrase' => $typed])
                ->assertHasActionErrors(['confirm_phrase']);
            $this->assertNotNull($party->fresh(), "'{$typed}' ile silinmemeli");
        }
    }

    // Telefon klavyesi baş harfi büyütür, Türkçe I/İ: hepsi kabul.
    public function test_confirmation_phrase_ignores_case_and_turkish_i(): void
    {
        foreach (['Eminim Sil', 'eminim sil', 'EMİNİM SİL', 'EMINIM SIL', '  eminim   sil ', 'Eminim sil'] as $typed) {
            $this->assertTrue(PartyDeletion::confirmed($typed), $typed);
        }
        foreach (['', 'eminim', 'sil', 'emin sil'] as $typed) {
            $this->assertFalse(PartyDeletion::confirmed($typed), $typed);
        }
    }

    // 21.10: stoklu alış/satışı olan cari silinince veritabanı hata veriyordu (500) — artık silinmez, nedeni söylenir.
    public function test_party_with_stocked_purchase_is_not_deleted_and_reason_is_told(): void
    {
        $this->actingAs(User::factory()->create());
        config(['modules.stock' => true, 'modules.purchases' => true]);
        $party = Party::create(['name' => 'Silme Test Uncu']);
        $flour = \App\Models\Product::create(['name' => 'Silme Test Un', 'type' => \App\Models\Product::TYPE_PRODUCT]);
        \App\Support\TradeDesk::savePurchase(['mode' => 'party', 'party_id' => $party->id,
            'lines' => [['product_id' => $flour->id, 'quantity' => 20, 'amount' => '25.000,00']]]);

        $this->assertSame(['1 stoklu alış'], PartyDeletion::blockers($party));
        $this->assertStringContainsString('Alışlar ekranından', PartyDeletion::description($party));

        Livewire::test(EditParty::class, ['record' => $party->getRouteKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified('Silinemedi');

        $this->assertNotNull($party->fresh());
        $this->assertSame(20.0, $flour->currentStock(), 'stok bozulmadı');
    }

    // Veritabanı yine de reddederse: ekran hata vermez, cari ve işlem geçmişi olduğu gibi kalır (yarım "silindi" satırı yok).
    public function test_database_refusal_is_reported_not_crashed(): void
    {
        $this->actingAs(User::factory()->create());
        config(['modules.stock' => true, 'modules.purchases' => true]);
        $party = Party::create(['name' => 'Silme Test Red']);
        $flour = \App\Models\Product::create(['name' => 'Silme Test Red Un', 'type' => \App\Models\Product::TYPE_PRODUCT]);
        \App\Support\TradeDesk::savePurchase(['mode' => 'party', 'party_id' => $party->id,
            'lines' => [['product_id' => $flour->id, 'quantity' => 5, 'amount' => '500,00']]]);
        $logsBefore = \App\Models\ActivityLog::count();

        $this->assertFalse(PartyDeletion::delete($party), 'engel atlansa da veritabanı reddeder (pgsql)');

        $this->assertNotNull($party->fresh());
        $this->assertSame($logsBefore, \App\Models\ActivityLog::count(), 'silinmeyen cari için "silindi" yazılmadı');
    }
}
