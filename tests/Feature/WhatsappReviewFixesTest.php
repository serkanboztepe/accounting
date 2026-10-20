<?php

namespace Tests\Feature;

use App\Http\Controllers\WhatsappWebhookController;
use App\Models\Expense;
use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ChatAssistant;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/** Review 18.10 (para kaydının doğruluğu): çift onay, sıradaki kalemin asıl mesajla işlenmesi, iptalde sıradaki iş, "teslim etmedim". */
class WhatsappReviewFixesTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905557770020';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'modules' => ModuleProfiles::resolve('alacak_verecek', ['smart_assistant' => true]), 'app.profile' => 'alacak_verecek',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 777 00 20'],
        ]);
        Http::fake();
        WhatsappMessage::create(['phone' => '905557770020', 'direction' => 'in', 'body' => 'önceki']);
    }

    private function entry(array $o): array
    {
        return ['type' => 'entry', 'text' => '', 'data' => array_merge(['kind' => ExpenseExtractor::KIND_EXPENSE, 'items' => [], 'amount' => 0,
            'paid' => null, 'date' => now()->format('Y-m-d'), 'description' => 'İş', 'party_id' => null, 'party_name' => null,
            'project_id' => null, 'project_name' => null, 'category_id' => null, 'category_name' => null, 'confidence' => 'high',
            'question' => null, 'is_new_entry' => true, 'payment_purpose' => null, 'settles_expense_id' => null, 'next_items' => null], $o)];
    }

    private function chat(array ...$results): void
    {
        $mock = Mockery::mock(ChatAssistant::class);
        $mock->shouldReceive('respond')->andReturn(...$results);
        $this->app->instance(ChatAssistant::class, $mock);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    /** Aynı taslağa iki onay (kilit dolması / tekrar) — ikinci kayıt açılmaz; kontrol satır kilidiyle, SID'den bağımsız. */
    public function test_second_confirm_of_same_draft_writes_nothing(): void
    {
        $pending = WhatsappPendingExpense::create(['phone' => '905557770020', 'status' => 'awaiting_confirmation', 'summary' => 'x',
            'extracted' => $this->entry(['amount' => 750, 'description' => 'Mazot', 'paid' => true])['data']]);
        $stale = WhatsappPendingExpense::find($pending->id);
        $commit = (new \ReflectionClass(WhatsappWebhookController::class))->getMethod('commit');
        $controller = app(WhatsappWebhookController::class);
        $before = Expense::count();

        $commit->invoke($controller, $pending);
        $second = $commit->invoke($controller, $stale); // eski nesne hâlâ "onay bekliyor" sanıyor

        $this->assertSame($before + 1, Expense::count());
        $this->assertStringContainsString('zaten kaydedildi', $second);
    }

    /** Sıradaki kalem "evet" turunda işlenir — emniyetler asıl mesajı görür ("teslim etmedim" → peşin para, satış değil). */
    public function test_next_item_is_checked_against_the_original_message(): void
    {
        $this->chat(
            $this->entry(['kind' => ExpenseExtractor::KIND_DEBT_NOTE, 'debt_side' => 'receivable', 'amount' => 5000, 'party_name' => 'Recep Demirci',
                'description' => 'Eski alacak', 'next_items' => 'Recep\'e 3.000 peynir sattım parasını aldım']),
            $this->entry(['kind' => ExpenseExtractor::KIND_SALE, 'amount' => 3000, 'party_name' => 'Recep Demirci', 'paid' => true, 'description' => 'Peynir']),
        );

        $this->send("Recep'ten 5 bin alacağım var, bir de 3.000 peynir parasını aldım ama peyniri daha teslim etmedim");
        $this->assertStringContainsString('Peşin para* ←', $this->send('evet'));

        $next = WhatsappPendingExpense::where('phone', self::PHONE)->where('status', 'awaiting_confirmation')->latest('id')->first();
        $this->assertNotNull($next);
        $this->assertSame(ExpenseExtractor::KIND_COLLECTION, $next->extracted['kind'], 'teslim edilmemiş satış = peşin para');
        $this->assertSame('advance', $next->extracted['payment_purpose']);
    }

    public function test_cancel_mentions_the_next_item(): void
    {
        $this->chat(
            $this->entry(['amount' => 5400, 'description' => 'Parça', 'paid' => true, 'next_items' => '10.000 TL traktör ustalık parası ödedim']),
            ['type' => 'cancel'],
        );

        $this->send('5400 parça 10.000 işçilik');
        $reply = $this->send('vazgeç bunu');

        $this->assertStringContainsString('İptal edildi', $reply);
        $this->assertStringContainsString('Aynı mesajda bir de şu vardı: 10.000 TL traktör ustalık parası ödedim', $reply);
    }

    public function test_paid_but_not_delivered_sale_is_an_advance(): void
    {
        $sale = ['kind' => ExpenseExtractor::KIND_SALE, 'paid' => true, 'amount' => 3000, 'items' => [['description' => 'Peynir', 'amount' => 3000]]];

        foreach (['parasını aldım malı teslim etmedim', 'parasını aldım ama daha vermedim', 'peşin aldım henüz götürmedim'] as $text) {
            $this->assertSame(ExpenseExtractor::KIND_COLLECTION, ExpenseExtractor::guardUndeliveredSale($sale, $text)['kind'], $text);
        }
        $this->assertSame(ExpenseExtractor::KIND_SALE, ExpenseExtractor::guardUndeliveredSale($sale, 'malı teslim ettim parasını aldım')['kind']);
    }
}
