<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\ChatAssistant;
use App\Support\ModuleProfiles;
use App\Support\PartyStatement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Oktay 18.10: "Akkuş fırına giden son satışı iptal et" → "bulamadım" (arama cari/ürünlü satışa bakmıyordu). Artık kişiyle
 * bulunur; silinince bağlı stok hareketi ve cari satırı da gider (panelin "geri al"ı gibi).
 */
class WhatsappUndoPartySaleTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905557770017';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'modules' => ModuleProfiles::resolve('trade', ['smart_assistant' => true]), 'app.profile' => 'trade',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 777 00 17'],
        ]);
        Http::fake();
        WhatsappMessage::create(['phone' => '905557770017', 'direction' => 'in', 'body' => 'önceki']);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    private function undo(array $find): void
    {
        $mock = Mockery::mock(ChatAssistant::class);
        $mock->shouldReceive('respond')->andReturn(['type' => 'undo', 'replacement' => null, 'find' => $find]);
        $this->app->instance(ChatAssistant::class, $mock);
    }

    private function milkSale(Party $party, Product $milk, float $litres, string $date): Sale
    {
        $sale = Sale::create(['party_id' => $party->id, 'sale_date' => $date, 'total_amount' => $litres * 30,
            'notes' => $party->name . "'a {$litres} litre süt (WhatsApp)"]);
        StockMovement::create(['product_id' => $milk->id, 'direction' => StockMovement::DIRECTION_OUT, 'reason' => StockMovement::REASON_SALE,
            'quantity' => $litres, 'unit_price' => 30, 'amount' => $litres * 30, 'movement_date' => $date, 'sale_id' => $sale->id, 'party_id' => $party->id]);
        PartyLedgerEntry::create(['party_id' => $party->id, 'type' => PartyLedgerEntry::TYPE_SALE, 'amount' => $litres * 30,
            'entry_date' => $date, 'description' => 'Satış', 'sale_id' => $sale->id]);

        return $sale;
    }

    public function test_latest_party_sale_is_found_and_deleted_with_its_rows(): void
    {
        $akkus = Party::create(['name' => 'Akkuş Fırın']);
        $milk = Product::create(['name' => 'Süt', 'type' => 'product']);
        $older = $this->milkSale($akkus, $milk, 30, now()->subDays(3)->toDateString());
        $latest = $this->milkSale($akkus, $milk, 50, now()->subDay()->toDateString());
        $this->undo(['party' => 'Akkuş', 'latest' => true]);

        $card = $this->send('Akkuş fırına giden son satışı iptal et');
        $this->assertStringContainsString('Şu kaydı sileceğim', $card);
        $this->assertStringContainsString('Satış — Akkuş Fırın', $card);
        $this->assertStringContainsString('1.500,00', $card);
        $this->assertStringNotContainsString('Birden çok', $card, 'carideki ayna satırı ayrıca listelenmez');

        $this->send('evet');
        $this->assertNull(Sale::find($latest->id));
        $this->assertSame(0, StockMovement::where('sale_id', $latest->id)->count());
        $this->assertSame(0, PartyLedgerEntry::where('sale_id', $latest->id)->count());
        $this->assertNotNull(Sale::find($older->id), 'eski satış yerinde');
        $this->assertEqualsWithDelta(900, PartyStatement::build($akkus)['balance'], 0.01);
    }

    public function test_party_word_in_text_is_used_as_party(): void
    {
        $akkus = Party::create(['name' => 'Akkuş Fırın']);
        $sale = $this->milkSale($akkus, Product::create(['name' => 'Süt', 'type' => 'product']), 50, now()->subDay()->toDateString());
        $this->undo(['text' => 'Akkuş', 'amount' => 1500]);

        $this->assertStringContainsString('Satış — Akkuş Fırın', $this->send('Akkuştan 50 litre süt düş'));
        $this->send('evet');
        $this->assertNull(Sale::find($sale->id));
    }

    public function test_number_in_message_picks_the_matching_record(): void
    {
        $akkus = Party::create(['name' => 'Akkuş Fırın']);
        $milk = Product::create(['name' => 'Süt', 'type' => 'product']);
        $this->milkSale($akkus, $milk, 50, now()->subDays(3)->toDateString());
        $this->milkSale($akkus, $milk, 30, now()->subDay()->toDateString());
        $this->undo(['party' => 'Akkuş', 'message' => 'Akkuş fırından 50 litre süt düş']);

        $card = $this->send('Akkuş fırından 50 litre süt düş');
        $this->assertStringContainsString('Şu kaydı sileceğim', $card);
        $this->assertStringContainsString('50 litre', $card);
        $this->assertStringNotContainsString('30 litre', $card);
    }

    public function test_unknown_party_says_so(): void
    {
        $this->undo(['party' => 'Olmayan Bakkal']);

        $this->assertStringContainsString('"Olmayan Bakkal" adında biri yok', $this->send('Olmayan bakkala satışı sil'));
    }
}
