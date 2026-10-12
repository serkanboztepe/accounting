<?php

namespace Tests\Feature\Products;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Party;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Support\StockUsage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Otomatik stoktan düş (fırın): günlük kullanım her sabah "Kullanım" çıkışı olarak yazılır,
 * eşiğe inince bir kez "bitmek üzere" denir.
 */
class AutoUsageTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local', 'modules.stock' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function flour(float $stock = 20, ?string $supplier = null): Product
    {
        $unit = Unit::firstOrCreate(['code' => 'cuval'], ['name' => 'çuval']);
        $p = Product::create(['name' => 'Un', 'type' => Product::TYPE_PRODUCT, 'unit_id' => $unit->id]);
        StockMovement::create([
            'product_id' => $p->id, 'direction' => 'in', 'reason' => 'purchase', 'quantity' => $stock,
            'party_id' => $supplier ? Party::create(['name' => $supplier])->id : null, 'movement_date' => now(),
        ]);

        return $p;
    }

    public function test_enabling_starts_from_next_morning(): void
    {
        Carbon::setTestNow('2026-10-12 14:00:00');
        $p = $this->flour();
        $p->update(['auto_usage' => true, 'daily_usage' => 3, 'reorder_level' => 6]);

        $this->assertSame('2026-10-12', $p->fresh()->usage_booked_through->toDateString());
        $this->assertSame([], StockUsage::book(), 'Açıldığı gün düşmemeli');
        $this->assertSame(20.0, $p->currentStock());

        Carbon::setTestNow('2026-10-13 08:00:00');
        StockUsage::book();
        $this->assertSame(17.0, $p->currentStock());
        $this->assertSame(1, $p->stockMovements()->where('reason', StockMovement::REASON_USAGE)->count());
    }

    public function test_same_day_twice_does_not_double_and_missed_days_catch_up(): void
    {
        Carbon::setTestNow('2026-10-12 14:00:00');
        $p = $this->flour();
        $p->update(['auto_usage' => true, 'daily_usage' => 3]);

        Carbon::setTestNow('2026-10-15 08:00:00');   // 13, 14, 15 kaçırıldı
        StockUsage::book();
        StockUsage::book();

        $this->assertSame(11.0, $p->currentStock());
        $this->assertEqualsCanonicalizing(
            ['2026-10-13', '2026-10-14', '2026-10-15'],
            $p->stockMovements()->where('reason', 'usage')->get()->map(fn ($m) => $m->movement_date->toDateString())->all(),
        );
    }

    public function test_never_goes_below_zero(): void
    {
        Carbon::setTestNow('2026-10-12 14:00:00');
        $p = $this->flour(5);
        $p->update(['auto_usage' => true, 'daily_usage' => 3]);

        Carbon::setTestNow('2026-10-16 08:00:00');
        StockUsage::book();

        $this->assertSame(0.0, $p->currentStock());
    }

    public function test_disabled_or_service_products_are_not_touched(): void
    {
        Carbon::setTestNow('2026-10-12 14:00:00');
        $off = $this->flour();
        $off->update(['daily_usage' => 3]);   // anahtar kapalı

        Carbon::setTestNow('2026-10-14 08:00:00');
        StockUsage::book();

        $this->assertSame(20.0, $off->currentStock());
    }

    public function test_low_stock_alerts_once_and_resets_after_purchase(): void
    {
        Carbon::setTestNow('2026-10-12 14:00:00');
        $p = $this->flour(20, 'Ak Un');
        $p->update(['auto_usage' => true, 'daily_usage' => 3, 'reorder_level' => 6]);

        Carbon::setTestNow('2026-10-16 08:00:00');   // 20 − 12 = 8 → henüz değil
        StockUsage::book();
        $this->assertTrue(StockUsage::newlyLow()->isEmpty());

        Carbon::setTestNow('2026-10-17 08:00:00');   // 5 → eşikte
        StockUsage::book();
        $low = StockUsage::newlyLow();
        $this->assertCount(1, $low);
        $this->assertSame('Un: 5 çuval kaldı (~1 gün yeter) — son alış: Ak Un', StockUsage::line($low->first()));

        $p->update(['low_stock_alerted_at' => now()]);
        $this->assertTrue(StockUsage::newlyLow()->isEmpty(), 'Aynı ürün ikinci kez haber verilmemeli');

        StockMovement::create(['product_id' => $p->id, 'direction' => 'in', 'reason' => 'purchase', 'quantity' => 20, 'movement_date' => now()]);
        StockUsage::newlyLow();
        $this->assertNull($p->fresh()->low_stock_alerted_at, 'Mal girişinden sonra işaret sıfırlanmalı');
    }

    public function test_command_dry_run_writes_nothing(): void
    {
        Carbon::setTestNow('2026-10-12 14:00:00');
        $p = $this->flour(6);
        $p->update(['auto_usage' => true, 'daily_usage' => 3, 'reorder_level' => 6]);

        Carbon::setTestNow('2026-10-13 08:00:00');
        $this->artisan('stock:remind', ['--dry-run' => true])
            ->expectsOutputToContain('Un: 6 çuval kaldı')
            ->assertSuccessful();

        $this->assertSame(6.0, $p->currentStock());
        $this->assertNull($p->fresh()->low_stock_alerted_at);
    }

    public function test_threshold_works_without_auto_usage(): void
    {
        // Lastikçi: her gün aynı sayıda kullanmaz ama "4 kalınca haber ver" ister; satışla düşer.
        $p = $this->flour(10);
        $p->update(['reorder_level' => 4]);
        StockMovement::create(['product_id' => $p->id, 'direction' => 'out', 'reason' => 'sale', 'quantity' => 6, 'movement_date' => now()]);

        $low = StockUsage::newlyLow();
        $this->assertCount(1, $low);
        $this->assertSame('Un: 4 çuval kaldı', StockUsage::line($low->first()));
    }

    public function test_threshold_field_visible_without_auto_usage(): void
    {
        $this->actingAs(User::factory()->create());
        $p = $this->flour();

        Livewire::test(EditProduct::class, ['record' => $p->getRouteKey()])
            ->assertFormFieldVisible('reorder_level')
            ->assertFormFieldHidden('daily_usage')
            ->fillForm(['auto_usage' => true])
            ->assertFormFieldVisible('daily_usage');
    }

    public function test_edit_page_shows_toggle_and_fields(): void
    {
        $this->actingAs(User::factory()->create());
        $p = $this->flour();
        $p->update(['auto_usage' => true, 'daily_usage' => 3, 'reorder_level' => 6]);

        Livewire::test(EditProduct::class, ['record' => $p->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Otomatik stoktan düş')
            ->assertSee('Günde kaç tane kullanılıyor?')
            ->assertSee('Kaç tane kalınca haber verilsin?')
            ->assertSee('yaklaşık 6 gün yeter');
    }
}
