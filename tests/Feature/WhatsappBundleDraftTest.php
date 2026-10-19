<?php

namespace Tests\Feature;

use App\Models\AssistantMemory;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use App\Models\Reminder;
use App\Models\Sale;
use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\BundleDraft;
use App\Services\Whatsapp\ChatAssistant;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Toplu düzeltme (Oktay yenilik mesajı 19.10: "hepsini düzeltmemi isterseniz *evet* yazın"): müşterinin tek "evet"i listedeki işleri yapar —
 * "evet" boşa düşüp 👍 ile kalmaz. Saatler sonra gelen cevap da tutar (24 saat); "yarım kalan" hatırlatması gitmez.
 */
class WhatsappBundleDraftTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905557770021';

    private string $first = '';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'modules' => ModuleProfiles::resolve('trade', ['smart_assistant' => true, 'expenses' => true]), 'app.profile' => 'trade',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 777 00 21'],
        ]);
        Http::fake();
        WhatsappMessage::create(['phone' => '905557770021', 'direction' => 'in', 'body' => 'önceki']);
        $mock = Mockery::mock(ChatAssistant::class);
        $mock->shouldReceive('respond')->andReturn(['type' => 'confirm', 'paid' => null]);
        $this->app->instance(ChatAssistant::class, $mock);
    }

    /** @return array{0: Sale, 1: Product, 2: AssistantMemory} */
    private function offer(): array
    {
        $cat = ExpenseCategory::firstOrCreate(['name' => 'Çobanlar']);
        $akkus = Party::create(['name' => 'Testakkuş Fırın']);
        $sale = Sale::create(['party_id' => $akkus->id, 'sale_date' => now()->subDays(2)->toDateString(), 'total_amount' => 1500]);
        PartyLedgerEntry::create(['party_id' => $akkus->id, 'type' => PartyLedgerEntry::TYPE_SALE, 'amount' => 1500, 'entry_date' => now()->subDays(2)->toDateString(), 'sale_id' => $sale->id]);
        $yem = Product::create(['name' => 'Testbuzağı Yemi', 'type' => 'product', 'auto_usage' => true, 'daily_usage' => 0.33]);
        $note = AssistantMemory::create(['kind' => 'agreement', 'subject' => 'Çoban ekmeği', 'fact' => 'Çobanlar her gün 2 ekmek; her gün gider olarak kaydedilecek', 'status' => 'active']);
        $today = now('Europe/Istanbul')->toDateString();

        $this->first = BundleDraft::offer('905557770021', [
            ['op' => 'recurring', 'data' => ['kind' => 'recurring_expense', 'description' => 'Testçoban ekmeği', 'amount' => 34.60,
                'category_id' => $cat->id, 'recurring_frequency' => 'daily', 'recurring_start' => $today]],
            ['op' => 'expense', 'date' => now('Europe/Istanbul')->subDay()->toDateString(), 'amount' => 34.60, 'description' => 'Testçoban ekmeği', 'category_id' => $cat->id],
            ['op' => 'product_usage', 'product_id' => $yem->id, 'amount' => 1, 'every_days' => 3],
            ['op' => 'delete_sale', 'id' => $sale->id],
            ['op' => 'forget_memory', 'id' => $note->id],
        ], ['Çoban ekmeğini her gün 34,60 ₺ gider kurayım mı?', 'Dünkü çoban ekmeğini yazayım mı?', 'Buzağı yemi 3 günde 1 düşsün mü?',
            'Akkuş 1.500 ₺ süt satışını iptal edeyim mi?', 'Eski notu sileyim mi?']);

        return [$sale, $yem, $note];
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    public function test_asks_one_by_one_yes_does_no_skips_even_hours_later(): void
    {
        [$sale, $yem, $note] = $this->offer();
        $this->assertStringContainsString('1/5', $this->first);
        $this->assertStringContainsString('Çoban ekmeğini her gün', $this->first);

        $this->travel(5)->hours(); // cevap saatler sonra
        $out = $this->send('evet');
        $this->assertStringContainsString('Düzenli gider kuruldu', $out);
        $this->assertStringContainsString('2/5', $out);
        $this->assertSame(1, Expense::where('description', 'Testçoban ekmeği')->count(), 'yalnız 1. madde (bugünkü)');
        $this->assertEquals(0.33, (float) $yem->fresh()->daily_usage, '3. madde henüz yok');

        $this->assertStringContainsString('3/5', $this->send('hayır'));
        $this->assertSame(1, Expense::where('description', 'Testçoban ekmeği')->count(), '2. atlandı');

        $this->assertStringContainsString('4/5', $this->send('evet'));
        $this->assertSame(3, $yem->fresh()->usage_every_days);

        $this->assertStringContainsString('5/5', $this->send('hayır'));
        $this->assertNotNull(Sale::find($sale->id), '4. atlandı — satış yerinde');

        $end = $this->send('evet');
        $this->assertStringContainsString('Hepsi bu kadar', $end);
        $this->assertSame('deleted', $note->fresh()->status);
        $this->assertSame('confirmed', WhatsappPendingExpense::where('phone', self::PHONE)->latest('id')->value('status'));

        $this->send('evet');
        $this->assertSame(1, Expense::where('description', 'Testçoban ekmeği')->count(), 'bittikten sonra evet bir şey yapmaz');
    }

    public function test_delete_sale_step_removes_sale_with_its_ledger(): void
    {
        [$sale] = $this->offer();
        foreach (['hayır', 'hayır', 'hayır'] as $no) {
            $this->send($no);
        }
        $this->assertStringContainsString('iptal edildi', $this->send('evet'));
        $this->assertNull(Sale::find($sale->id));
        $this->assertSame(0, PartyLedgerEntry::where('sale_id', $sale->id)->count());
    }

    public function test_correction_sentence_does_nothing_and_question_waits(): void
    {
        [$sale, $yem] = $this->offer();
        $mock = Mockery::mock(ChatAssistant::class);
        $mock->shouldReceive('respond')->withArgs(fn ($phone, $text, $pending) => $pending === null)->andReturn(['type' => 'reply', 'text' => 'Anladım.']);
        $this->app->instance(ChatAssistant::class, $mock);

        $this->send('ekmek 3 taneydi aslında');

        $this->assertSame(0, Expense::where('description', 'Testçoban ekmeği')->count());
        $this->assertEquals(0.33, (float) $yem->fresh()->daily_usage);
        $p = WhatsappPendingExpense::where('phone', self::PHONE)->latest('id')->first();
        $this->assertSame('awaiting_confirmation', $p->status);
        $this->assertSame(0, $p->extracted['step'], 'sıra bekler');
    }
}
