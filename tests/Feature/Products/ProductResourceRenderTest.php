<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Hizmet/Ürün kartı (①) — gerçek şemaya karşı render duman testi.
 * DatabaseTransactions ile yazımlar geri alınır.
 */
class ProductResourceRenderTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // User FilamentUser implement etmiyor → panel erişimi yalnız local'de açık.
        config(['app.env' => 'local']);
    }

    public function test_index_page_renders_with_product_and_service(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['name' => 'ton', 'code' => 'ton']);

        Product::create([
            'name' => 'Çimento 50kg', 'type' => Product::TYPE_PRODUCT,
            'unit_id' => $unit->id, 'default_price' => '185.50', 'is_active' => true,
        ]);
        Product::create([
            'name' => 'Seramik işçiliği', 'type' => Product::TYPE_SERVICE,
            'default_price' => '90.00', 'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get('/admin/products')
            ->assertOk()
            ->assertSee('Çimento 50kg')
            ->assertSee('Seramik işçiliği')
            ->assertSee('Ürün')
            ->assertSee('Hizmet')
            ->assertSee('185,50'); // Money formatı (varsayılan fiyat)
    }

    public function test_create_page_renders(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/products/create')
            ->assertOk()
            ->assertSee('Tür')
            ->assertSee('Varsayılan fiyat');
    }

    public function test_edit_page_renders(): void
    {
        $user = User::factory()->create();
        $product = Product::create([
            'name' => 'Demir 12mm', 'type' => Product::TYPE_PRODUCT,
            'default_price' => '32000.00', 'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get("/admin/products/{$product->id}/edit")
            ->assertOk()
            ->assertSee('Demir 12mm');
    }
}
