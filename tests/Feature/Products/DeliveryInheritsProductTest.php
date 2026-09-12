<?php

namespace Tests\Feature\Products;

use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\ContractItem;
use App\Models\Party;
use App\Models\Product;
use App\Models\Project;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * ② Teslimat, ürün kimliğini kaleminden otomatik miras alır (model saving hook).
 */
class DeliveryInheritsProductTest extends TestCase
{
    use DatabaseTransactions;

    private function makeItem(?int $productId): ContractItem
    {
        $project = Project::create(['name' => 'Test Proje', 'status' => 'active']);
        $party = Party::create(['name' => 'Test Tedarikçi']);
        $contract = Contract::create([
            'project_id' => $project->id,
            'party_id' => $party->id,
            'title' => 'Test Sözleşme',
            'contract_type' => 'supplier',
            'direction' => 'in',
            'total_amount' => 0,
            'status' => 'active',
        ]);

        return ContractItem::create([
            'contract_id' => $contract->id,
            'product_id' => $productId,
            'description' => 'Çimento',
            'quantity' => 100,
            'unit_price' => 185.50,
            'amount' => 18550,
        ]);
    }

    public function test_delivery_inherits_product_id_from_item(): void
    {
        $product = Product::create(['name' => 'Çimento 50kg', 'type' => Product::TYPE_PRODUCT]);
        $item = $this->makeItem($product->id);

        // Teslimatı product_id VERMEDEN oluştur → kaleminden miras almalı.
        $delivery = ContractDelivery::create([
            'contract_id' => $item->contract_id,
            'contract_item_id' => $item->id,
            'project_id' => $item->contract->project_id,
            'delivery_date' => now()->toDateString(),
            'quantity' => 10,
            'unit_price' => 185.50,
            'amount' => 1855,
        ]);

        $this->assertSame($product->id, $delivery->fresh()->product_id);
    }

    public function test_free_text_item_leaves_delivery_product_null(): void
    {
        $item = $this->makeItem(null); // katalogsuz (serbest) kalem

        $delivery = ContractDelivery::create([
            'contract_id' => $item->contract_id,
            'contract_item_id' => $item->id,
            'project_id' => $item->contract->project_id,
            'delivery_date' => now()->toDateString(),
            'quantity' => 10,
            'unit_price' => 185.50,
            'amount' => 1855,
        ]);

        $this->assertNull($delivery->fresh()->product_id);
    }

    public function test_explicit_product_id_is_not_overwritten(): void
    {
        $itemProduct = Product::create(['name' => 'Çimento 50kg', 'type' => Product::TYPE_PRODUCT]);
        $otherProduct = Product::create(['name' => 'Demir 12mm', 'type' => Product::TYPE_PRODUCT]);
        $item = $this->makeItem($itemProduct->id);

        // Teslimata farklı bir ürün açıkça verilirse hook ezmemeli.
        $delivery = ContractDelivery::create([
            'contract_id' => $item->contract_id,
            'contract_item_id' => $item->id,
            'product_id' => $otherProduct->id,
            'project_id' => $item->contract->project_id,
            'delivery_date' => now()->toDateString(),
            'quantity' => 10,
            'unit_price' => 100,
            'amount' => 1000,
        ]);

        $this->assertSame($otherProduct->id, $delivery->fresh()->product_id);
    }
}
