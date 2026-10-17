<?php

namespace Tests\Feature\Trade;

use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\TradeDraft;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 17.10 Oktay: "Akkuş fırına 50 litre süt gitti" + "litresi 30 TL" → toplam 0 kalıyordu (süt kartta yok).
 * Kartsız kalemde de miktar × birim fiyat (lt / kg / birim yoksa adet); fiyat yoksa sorulur.
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
            'amount' => null, 'party_name' => 'Akkuş Fırın', 'date' => now()->format('Y-m-d')]);
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
