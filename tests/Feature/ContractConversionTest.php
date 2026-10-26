<?php

namespace Tests\Feature;

use App\Models\Agreement;
use App\Models\Check;
use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\ContractItem;
use App\Models\ContractPayment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Support\ContractConversion;
use App\Support\PartyStatement;
use App\Support\ProjectCost;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Sözleşmeler → Anlaşmalar (26.10, Muhasebe verisinin kalıpları): fiyat listesi, tutarı 0 yazılmış miktarlı kalem, anahtar teslim,
 * kalemsiz sözleşme, fiyatı 1 yer tutucu, kalemsiz teslimat, çekli ödeme, fatura. Teslimat tutarı ve proje maliyeti aynen kalmalı.
 */
class ContractConversionTest extends TestCase
{
    use DatabaseTransactions;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.agreements' => true, 'modules.contracts' => true, 'modules.projects' => true, 'modules.expenses' => true]);
        $this->project = Project::create(['name' => 'CC Yeşilyurt', 'status' => 'active']);
    }

    private function contract(string $title, string $type, float $total, array $items): array
    {
        $party = Party::create(['name' => 'CC ' . $title]);
        $c = Contract::create(['project_id' => $this->project->id, 'party_id' => $party->id, 'title' => $title, 'contract_type' => $type,
            'direction' => Contract::DIRECTION_PURCHASE, 'status' => 'active', 'total_amount' => $total, 'contract_date' => '2026-05-01']);
        $made = [];
        foreach ($items as $i) {
            $made[] = ContractItem::create(['contract_id' => $c->id] + $i);
        }

        return [$c, $party, $made];
    }

    private function deliver(Contract $c, ?ContractItem $item, ?float $qty, ?float $price, float $amount, string $date = '2026-06-01'): void
    {
        ContractDelivery::create(['contract_id' => $c->id, 'contract_item_id' => $item?->id, 'project_id' => $this->project->id,
            'delivery_date' => $date, 'quantity' => $qty, 'unit_price' => $price, 'amount' => $amount]);
    }

    public function test_muhasebe_patterns_convert_without_losing_money(): void
    {
        // Fiyat listesi (Tuğla & Çimento) + kalemsiz teslimat.
        [$brick, $brickParty, [$tugla, $cimento]] = $this->contract('Tuğla & Çimento', 'supply', 826000, [
            ['description' => "13'lük tuğla", 'quantity' => null, 'unit_price' => 8.50, 'amount' => 0],
            ['description' => 'Çimento', 'quantity' => null, 'unit_price' => 125, 'amount' => 0],
        ]);
        $this->deliver($brick, $tugla, 40000, 8.50, 340000);
        $this->deliver($brick, $cimento, 1000, 125, 125000);
        $this->deliver($brick, null, 1, 361000, 361000);   // kalemsiz
        // Miktarlı ama tutarı 0 yazılmış (Banyo) + teslimatta kuruş farkı.
        [$bath, $bathParty, [$fayans]] = $this->contract('Banyo', 'supply', 1142000, [
            ['description' => 'Fayans', 'quantity' => 1244.16, 'unit_price' => 450, 'amount' => 0],
        ]);
        $this->deliver($bath, $fayans, 600, 450, 270000.03);
        // Anahtar teslim (Elektrik) — götürü, ödeme çekle.
        [$elec, $elecParty, [$turnkey]] = $this->contract('Elektrik Tesisatı', 'subcontract', 600000, [
            ['description' => 'Anahtar Teslim Sözleşme', 'quantity' => 1, 'unit_price' => 600000, 'amount' => 600000],
        ]);
        $this->deliver($elec, $turnkey, 0.5, 600000, 300000);
        $pay = ContractPayment::create(['contract_id' => $elec->id, 'payment_date' => '2026-05-10', 'payment_type' => 'check',
            'status' => 'paid', 'amount' => 600000, 'due_date' => '2026-08-10']);
        $check = Check::create(['party_id' => $elecParty->id, 'contract_payment_id' => $pay->id, 'amount' => 600000,
            'due_date' => '2026-08-10', 'status' => 'paid', 'check_number' => 'CC-1']);
        // Kalemsiz sözleşme (Su Tesisatı 380.000) + havale.
        [$water, $waterParty] = $this->contract('Su Tesisatı', 'subcontract', 380000, []);
        ContractPayment::create(['contract_id' => $water->id, 'payment_date' => '2026-05-12', 'payment_type' => 'bank_transfer',
            'status' => 'paid', 'amount' => 100000]);
        // Yer tutucu (PVC: tek kalem fiyat 1) + fatura.
        [$pvc, $pvcParty, [$pvcItem]] = $this->contract('PVC', 'supply', 1250000, [
            ['description' => 'PVC', 'quantity' => null, 'unit_price' => 1, 'amount' => 0],
        ]);
        $this->deliver($pvc, $pvcItem, 230000, 1, 230000);
        Invoice::create(['contract_id' => $pvc->id, 'invoice_number' => 'F-77', 'invoice_date' => '2026-06-30', 'total_amount' => 230000]);
        Expense::create(['project_id' => $this->project->id, 'expense_date' => '2026-06-01', 'amount' => 5000, 'payment_status' => 'paid', 'description' => 'mazot']);

        $deliveredBefore = round(340000 + 125000 + 361000 + 270000.03 + 300000 + 230000, 2);
        $costBefore = 5000 + $deliveredBefore;     // Proje Raporu: gider + teslimat

        $r = ContractConversion::run();

        // Sözleşmeler gitti, anlaşmalar geldi.
        $this->assertSame(0, Contract::withoutGlobalScopes()->whereIn('id', [$brick->id, $bath->id, $elec->id, $water->id, $pvc->id])->count());
        $parties = [$brickParty, $bathParty, $elecParty, $waterParty, $pvcParty];
        $agreements = Agreement::whereIn('party_id', collect($parties)->pluck('id'))->with('items.product')->get()->keyBy('party_id');
        $this->assertCount(5, $agreements);

        // Kalem türleri.
        $brickAg = $agreements[$brickParty->id];
        $this->assertTrue($brickAg->items->firstWhere('product.name', "13'lük tuğla")->isList(), 'fiyat listesi');
        $this->assertSame(3, $brickAg->items->count(), 'kalemsiz teslimat ayrı kalem');
        $this->assertSame(826000.0, (float) $brickAg->budget, 'elle girilen toplam → bütçe');
        $this->assertEqualsWithDelta(559872.0, (float) $agreements[$bathParty->id]->items->first()->agreedAmount(), 0.01, 'miktar × fiyat');
        $elecItem = $agreements[$elecParty->id]->items->first();
        $this->assertTrue($elecItem->is_lump);
        $this->assertSame('Elektrik Tesisatı', $elecItem->product->name, '"Anahtar Teslim Sözleşme" yerine başlık');
        $this->assertSame(300000.0, $elecItem->remainingAmount());
        $this->assertTrue($agreements[$waterParty->id]->items->first()->is_lump);
        $this->assertSame(1250000.0, (float) $agreements[$pvcParty->id]->items->first()->unit_price, 'yer tutucu → toplamla götürü');
        $this->assertStringContainsString('Faturalar: no F-77 30.06.2026 230.000,00 ₺', $agreements[$pvcParty->id]->notes);

        // Para: teslimat ve proje maliyeti aynen.
        $deliveredAfter = round($agreements->sum(fn ($a) => $a->items->sum(fn ($i) => $i->deliveredAmount())), 2);
        $this->assertSame($deliveredBefore, $deliveredAfter);
        $this->assertEqualsWithDelta($costBefore, ProjectCost::expenses($this->project->id) + ProjectCost::purchases($this->project->id), 0.01);

        // Ödemeler + çek.
        $entry = PartyLedgerEntry::where('party_id', $elecParty->id)->where('type', PartyLedgerEntry::TYPE_PAYMENT)->sole();
        $this->assertSame(600000.0, (float) $entry->amount);
        $this->assertSame($entry->id, $check->fresh()->party_ledger_entry_id);
        $this->assertNull($check->fresh()->contract_payment_id);
        $this->assertSame('paid', $check->fresh()->status, 'çek durumu korunur');
        // Cari bakiyesi: anlaşmada borç teslimatla yazılır (model farkı): 300.000 iş − 600.000 ödeme.
        $this->assertSame(300000.0, PartyStatement::build($elecParty)['balance']);
        $this->assertGreaterThanOrEqual(5, $r['agreements']);
    }
}
