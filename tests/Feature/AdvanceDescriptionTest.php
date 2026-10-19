<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Services\Whatsapp\AdvanceOffset;
use App\Services\Whatsapp\ExpenseExtractor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Yeni Yapı 19.10: "Boyacı Ahmet'e 20 bin avans" → açıklama "Boyacı Ahmet avansı" (zaten "avans" içerdiği için başına "Avans" eklenmedi);
 * açık avans yalnız "Avans…" ile başlayanlarda arandığı için 0 sayıldı → "Ahmet işimi yaptı" avanstan düşme akışına girmedi, garip soru sordu.
 */
class AdvanceDescriptionTest extends TestCase
{
    use DatabaseTransactions;

    private function paid(string $description): Party
    {
        $p = Party::create(['name' => 'Testboyacı ' . uniqid()]);
        PartyLedgerEntry::create(['party_id' => $p->id, 'type' => PartyLedgerEntry::TYPE_PAYMENT, 'amount' => 20000,
            'entry_date' => now()->toDateString(), 'description' => $description]);

        return $p;
    }

    public function test_advance_word_anywhere_counts_as_open_advance(): void
    {
        $this->assertEqualsWithDelta(20000, AdvanceOffset::open($this->paid('Boyacı Ahmet avansı'), ExpenseExtractor::KIND_PURCHASE), 0.01);
        $this->assertEqualsWithDelta(20000, AdvanceOffset::open($this->paid('Avans — boya işi'), ExpenseExtractor::KIND_PURCHASE), 0.01);
    }

    public function test_plain_payment_is_not_an_advance(): void
    {
        $this->assertEqualsWithDelta(0, AdvanceOffset::open($this->paid('Ödeme'), ExpenseExtractor::KIND_PURCHASE), 0.01);
        $this->assertEqualsWithDelta(0, AdvanceOffset::open($this->paid('Borç verildi — Ahmet'), ExpenseExtractor::KIND_PURCHASE), 0.01);
    }
}
