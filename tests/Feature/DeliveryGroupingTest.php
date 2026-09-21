<?php

namespace Tests\Feature;

use App\Filament\Pages\ProjectReports;
use App\Filament\Resources\Contracts\Pages\EditContract;
use App\Filament\Resources\Contracts\RelationManagers\DeliveriesRelationManager;
use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\ContractItem;
use App\Models\Party;
use App\Models\Project;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class DeliveryGroupingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_deliveries_table_groups_by_item_and_renders_totals(): void
    {
        $this->actingAs(User::factory()->create());

        $party   = Party::create(['name' => 'Beton A.Ş.']);
        $project = Project::create(['name' => 'A Blok', 'party_id' => $party->id, 'status' => 'active']);
        $unit    = Unit::create(['name' => 'Metrekare', 'code' => 'm²']);

        $contract = Contract::create([
            'project_id'    => $project->id,
            'party_id'      => $party->id,
            'title'         => 'Fayans Alımı',
            'contract_type' => 'supplier',
            'direction'     => Contract::DIRECTION_PURCHASE,
            'contract_date' => now(),
            'total_amount'  => 8400,
            'status'        => 'active',
        ]);

        $item = ContractItem::create([
            'contract_id' => $contract->id,
            'description' => 'Fayans',
            'unit_id'     => $unit->id,
            'quantity'    => 14,
            'unit_price'  => 600,
            'amount'      => 8400,
        ]);

        // Aynı kalemden parça parça teslimatlar (5 + 3 + 6 = 14 m²).
        foreach ([5, 3, 6] as $qty) {
            ContractDelivery::create([
                'contract_id'      => $contract->id,
                'contract_item_id' => $item->id,
                'project_id'       => $project->id,
                'unit_id'          => $unit->id,
                'delivery_date'    => now(),
                'quantity'         => $qty,
                'unit_price'       => 600,
                'amount'           => $qty * 600,
            ]);
        }

        // İkinci kalem + teslimatı: filtrenin ayırdığını doğrulamak için.
        $demir         = ContractItem::create(['contract_id' => $contract->id, 'description' => 'Demir', 'unit_id' => $unit->id, 'quantity' => 2, 'unit_price' => 20000, 'amount' => 40000]);
        $demirDelivery = ContractDelivery::create(['contract_id' => $contract->id, 'contract_item_id' => $demir->id, 'project_id' => $project->id, 'unit_id' => $unit->id, 'delivery_date' => now(), 'quantity' => 2, 'unit_price' => 20000, 'amount' => 40000]);

        $fayansDeliveries = $contract->deliveries()->where('contract_item_id', $item->id)->get();

        Livewire::test(DeliveriesRelationManager::class, [
            'ownerRecord' => $contract->fresh(),
            'pageClass'   => EditContract::class,
        ])
            ->assertOk()
            // Varsayılan gruplı GELMEZ; düz liste.
            ->assertSet('tableGrouping', null)
            ->assertCanSeeTableRecords($fayansDeliveries)
            ->assertCanSeeTableRecords([$demirDelivery])
            // "Şuna göre grupla" → Kalem seçilince grup miktar toplamı (5+3+6) çıkar.
            ->set('tableGrouping', 'contractItem.description')
            ->assertSeeText('14,00')
            // Kalem filtresi: sadece Fayans seçilince Demir teslimatı gizlenir.
            ->filterTable('contract_item_id', $item->id)
            ->assertCanSeeTableRecords($fayansDeliveries)
            ->assertCanNotSeeTableRecords([$demirDelivery]);
    }

    public function test_project_report_groups_deliveries_by_material_within_each_contract(): void
    {
        $this->actingAs(User::factory()->create());

        $party   = Party::create(['name' => 'Beton A.Ş.']);
        $project = Project::create(['name' => 'A Blok', 'party_id' => $party->id, 'status' => 'active']);
        $m2      = Unit::create(['name' => 'Metrekare', 'code' => 'm²']);
        $ton     = Unit::create(['name' => 'Ton', 'code' => 'ton']);

        $contract = Contract::create([
            'project_id'    => $project->id,
            'party_id'      => $party->id,
            'title'         => 'Karma Alım',
            'contract_type' => 'supplier',
            'direction'     => Contract::DIRECTION_PURCHASE,
            'contract_date' => now(),
            'total_amount'  => 0,
            'status'        => 'active',
        ]);

        $fayans = ContractItem::create(['contract_id' => $contract->id, 'description' => 'Fayans', 'unit_id' => $m2->id, 'quantity' => 14, 'unit_price' => 600, 'amount' => 8400]);
        $demir  = ContractItem::create(['contract_id' => $contract->id, 'description' => 'Demir', 'unit_id' => $ton->id, 'quantity' => 3, 'unit_price' => 20000, 'amount' => 60000]);

        foreach ([5, 3, 6] as $qty) {
            ContractDelivery::create(['contract_id' => $contract->id, 'contract_item_id' => $fayans->id, 'project_id' => $project->id, 'unit_id' => $m2->id, 'delivery_date' => now(), 'quantity' => $qty, 'unit_price' => 600, 'amount' => $qty * 600]);
        }
        foreach ([1, 2] as $qty) {
            ContractDelivery::create(['contract_id' => $contract->id, 'contract_item_id' => $demir->id, 'project_id' => $project->id, 'unit_id' => $ton->id, 'delivery_date' => now(), 'quantity' => $qty, 'unit_price' => 20000, 'amount' => $qty * 20000]);
        }

        $page = new ProjectReports();
        $page->projectId = $project->id;
        $rows = $page->getDeliveryRows();

        $this->assertCount(1, $rows); // tek sözleşme
        $materials = collect($rows[0]['materials'])->keyBy('name');

        // En pahalı malzeme üstte (Demir 60.000 > Fayans 8.400)
        $this->assertSame('Demir', $rows[0]['materials'][0]['name']);

        $this->assertEqualsWithDelta(14.0, $materials['Fayans']['quantity'], 0.001); // 5+3+6
        $this->assertEqualsWithDelta(8400.0, $materials['Fayans']['amount'], 0.001);
        $this->assertSame(3, $materials['Fayans']['delivery_count']);

        $this->assertEqualsWithDelta(3.0, $materials['Demir']['quantity'], 0.001); // 1+2
        $this->assertEqualsWithDelta(60000.0, $materials['Demir']['amount'], 0.001);
        $this->assertSame(2, $materials['Demir']['delivery_count']);
    }
}
