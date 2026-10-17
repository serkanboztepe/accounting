<?php

namespace Tests\Feature\Trade;

use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\TradeDraft;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 17.10 Oktay: "Akkuş fırına 50 litre süt gitti" + "litresi 30 TL" → toplam 0 kalıyordu (süt kartta yok).
 * Kartsız kalemde de miktar × birim fiyat (lt / kg / birim yoksa adet); fiyat yoksa sorulur.
 * Satış onaylanınca STOKSUZ kart açılır (satış fiyatıyla) — bir dahakine fiyat sorulmaz, stok eksiye düşmez.
 */
class CardlessItemPriceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('trade'), 'app.profile' => 'trade']);
    }

    private function sale(array $item): array
    {
        return TradeDraft::route(['kind' => ExpenseExtractor::KIND_TRADE_SALE, 'items' => [$item + ['product_id' => null]],
            'amount' => null, 'paid' => true, 'cash_amount' => null, 'card_amount' => null, 'description' => 'süt',
            'date' => now()->format('Y-m-d')]);
    }

    public function test_sale_opens_stockless_card_and_next_sale_knows_price(): void
    {
        $d = $this->sale(['description' => 'Süt Cardless', 'quantity' => 50, 'unit' => 'litre', 'unit_price' => 30, 'amount' => null]);
        $this->assertStringContainsString('ürün kartı açılacak', TradeDraft::summary($d));
        $this->assertStringContainsString('stok tutulmaz', TradeDraft::summary($d));

        [$text] = TradeDraft::commit($d);
        $this->assertStringContainsString('Süt Cardless ürün kartı açıldı', $text);
        $this->assertStringContainsString('stok tutulmuyor', $text);

        $card = Product::where('name', 'Süt Cardless')->sole();
        $this->assertSame(Product::TYPE_SERVICE, $card->type);
        $this->assertSame(30.0, (float) $card->default_price);

        // İkinci satış: fiyat söylenmedi → karttan, soru yok, ikinci kart yok.
        $next = $this->sale(['description' => 'süt cardless', 'quantity' => 40, 'unit' => 'litre', 'unit_price' => null, 'amount' => null]);
        $this->assertSame($card->id, $next['items'][0]['product_id']);
        $this->assertSame(1200.0, (float) $next['amount']);
        $this->assertNull(TradeDraft::blocker($next));
        TradeDraft::commit($next);
        $this->assertSame(1, Product::where('name', 'Süt Cardless')->count());

        // Fiyat değişti → WhatsApp'tan açılmış stoksuz kartta güncellenir.
        TradeDraft::commit($this->sale(['description' => 'Süt Cardless', 'quantity' => 10, 'unit' => 'litre', 'unit_price' => 32, 'amount' => null]));
        $this->assertSame(32.0, (float) $card->fresh()->default_price);
    }

    public function test_manual_card_price_untouched_and_stock_card_still_stocked(): void
    {
        $stocked = Product::create(['name' => 'Saman Cardless', 'type' => Product::TYPE_PRODUCT, 'default_price' => 2500, 'is_active' => true]);
        StockMovement::create(['product_id' => $stocked->id, 'direction' => StockMovement::DIRECTION_IN, 'reason' => StockMovement::REASON_PRODUCTION,
            'quantity' => 100, 'movement_date' => now()->toDateString()]);

        $d = $this->sale(['description' => 'Saman Cardless', 'quantity' => 10, 'unit' => 'ton', 'unit_price' => 2700, 'amount' => null]);
        $this->assertFalse($d['items'][0]['new']);
        TradeDraft::commit($d);

        $this->assertSame(2500.0, (float) $stocked->fresh()->default_price, 'elle açılmış karta fiyat yazılmaz');
        $this->assertSame(90.0, (float) $stocked->currentStock());
    }

    public function test_similar_card_is_flagged(): void
    {
        Product::create(['name' => 'Kuzu Cardless', 'type' => Product::TYPE_PRODUCT, 'is_active' => true]);

        $d = $this->sale(['description' => 'Kuzu derisi', 'quantity' => 3, 'unit' => null, 'unit_price' => 100, 'amount' => null]);
        $summary = TradeDraft::summary($d);
        $this->assertStringContainsString('ürün kartı açılacak', $summary);
        $this->assertStringContainsString('Kuzu Cardless', $summary);
    }

    public function test_litre_price_times_quantity(): void
    {
        $d = $this->sale(['description' => 'Süt Cardless', 'quantity' => 50, 'unit' => 'litre', 'unit_price' => 30, 'amount' => null]);

        $this->assertSame(1500.0, (float) $d['amount']);
        $this->assertSame(1500.0, (float) $d['items'][0]['amount']);
        $this->assertNull($d['question'] ?? null);
    }

    public function test_piece_price_without_unit(): void
    {
        $d = $this->sale(['description' => 'Kasa Cardless', 'quantity' => 10, 'unit' => null, 'unit_price' => 40, 'amount' => null]);

        $this->assertSame(400.0, (float) $d['amount']);
    }

    public function test_total_only_gives_unit_price(): void
    {
        $d = $this->sale(['description' => 'Süt Cardless', 'quantity' => 50, 'unit' => 'litre', 'unit_price' => null, 'amount' => 1500]);

        $this->assertSame(30.0, (float) $d['items'][0]['unit_price']);
        $this->assertSame(1500.0, (float) $d['amount']);
    }

    public function test_no_price_asks(): void
    {
        $d = $this->sale(['description' => 'Süt Cardless', 'quantity' => 50, 'unit' => 'litre', 'unit_price' => null, 'amount' => null]);

        $this->assertStringContainsString('fiyatı ne', (string) $d['question']);
    }
}
