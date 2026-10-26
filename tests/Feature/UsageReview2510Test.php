<?php

namespace Tests\Feature;

use App\Models\Agreement;
use App\Models\AgreementItem;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Party;
use App\Models\Product;
use App\Models\Project;
use App\Models\StockMovement;
use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\DraftWarnings;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\TradeDraft;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/** 25.10 kullanım incelemesi maddeleri: 3 götürü kalan, 4 düzenli gider uyarısı, 6 amaç taşınması, 8 alakasız cari, 9 kasa başlığı. */
class UsageReview2510Test extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905556660010';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 666 00 10'],
            'services.anthropic.chat_assistant' => false]);
        Http::fake(['*' => Http::response('', 200)]);
        WhatsappMessage::create(['phone' => '905556660010', 'direction' => 'in', 'body' => 'önceki']);
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
    }

    private function ai(array $o): void
    {
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturn(array_merge(['kind' => 'payment', 'items' => [], 'amount' => 0.0,
            'date' => now()->toDateString(), 'description' => '', 'party_id' => null, 'party_name' => null, 'confidence' => 'high',
            'question' => null, 'is_new_entry' => true, 'payment_type' => null, 'paid' => null, 'project_id' => null, 'project_name' => null,
            'category_id' => null, 'category_name' => null, 'due_date' => null, 'debt_side' => null, 'reply' => null,
            'payment_purpose' => null, 'settles_expense_id' => null, 'totals_side' => null, 'date_from' => null, 'date_to' => null], $o));
        $this->app->instance(ExpenseExtractor::class, $mock);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    // ── 3 ─ götürü kalan ────────────────────────────────────────────

    public function test_3_lump_remaining_is_plain_subtraction(): void
    {
        config(['modules.agreements' => true]); // kapalıyken anlaşma yazılamaz (RequiresFeature)
        $party = Party::create(['name' => 'UR Götürü']);
        $product = Product::create(['name' => 'UR Tesisat', 'type' => Product::TYPE_SERVICE]);
        $agreement = Agreement::create(['party_id' => $party->id, 'direction' => 'purchase', 'title' => 'UR Götürü tesisat',
            'agreement_date' => now()->toDateString()]);
        $item = AgreementItem::create(['agreement_id' => $agreement->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 600000,
            'amount' => 600000, 'is_lump' => true]);
        StockMovement::create(['product_id' => $product->id, 'agreement_item_id' => $item->id, 'direction' => 'in', 'reason' => 'purchase',
            'quantity' => 0.02, 'amount' => 10000, 'movement_date' => now()]);

        $this->assertSame(590000.0, $item->fresh()->remainingAmount(), 'önceden 589.980 çıkıyordu');
    }

    // ── 4 ─ düzenli gider uyarı ölçüsüne girmez ─────────────────────

    public function test_4_recurring_expenses_do_not_make_normal_expense_look_unusual(): void
    {
        $cat = ExpenseCategory::create(['name' => 'UR Çobanlar']);
        foreach (range(1, 5) as $i) {
            Expense::create(['expense_category_id' => $cat->id, 'expense_date' => now()->subDays($i), 'amount' => 34.60,
                'payment_status' => 'paid', 'description' => 'ekmek', 'notes' => 'Düzenli gider #99 (her gün)']);
        }
        $d = ['kind' => ExpenseExtractor::KIND_EXPENSE, 'category_id' => $cat->id, 'amount' => 500.0];

        $this->assertSame([], DraftWarnings::for($d), 'düzenli 34,60 ölçü değil');

        // Karşı durum: aynı tutarlar elle girilmiş normal giderse uyarı çıkar.
        foreach (range(1, 5) as $i) {
            Expense::create(['expense_category_id' => $cat->id, 'expense_date' => now()->subDays($i), 'amount' => 34.60,
                'payment_status' => 'paid', 'description' => 'ekmek elle']);
        }
        $this->assertStringContainsString('fazladan sıfır', implode(' ', DraftWarnings::for($d)));
    }

    // ── 6 ─ amaç önceki ödemeden taşınmaz ───────────────────────────

    public function test_6_purpose_carried_from_previous_numbered_answer_is_asked_again(): void
    {
        $alim = Party::create(['name' => 'UR Alim Tok']);
        WhatsappPendingExpense::create(['phone' => self::PHONE, 'status' => 'confirmed', 'summary' => 'önceki',
            'extracted' => ['kind' => 'payment', 'party_id' => $alim->id, 'amount' => 30000, 'work_status' => 'not_yet',
                'work_status_known' => true, 'work_chosen' => true]]);
        $this->ai(['party_id' => $alim->id, 'amount' => 20000.0, 'description' => 'Önceden ödeme', 'work_status' => 'not_yet',
            'work_status_known' => true, 'payment_purpose' => 'advance']);

        $reply = $this->send("Alim Tok'a 20 bin verdim");
        $this->assertStringContainsString('ne için', mb_strtolower($reply), 'önceki "2 = peşin" taşınmaz, sorulur');
    }

    public function test_6_purpose_said_now_is_not_asked_and_written_once(): void
    {
        $alim = Party::create(['name' => 'UR Alim Tok 2']);
        $this->ai(['party_id' => $alim->id, 'amount' => 20000.0, 'description' => 'Önceden ödeme', 'work_status' => 'not_yet',
            'work_status_known' => true, 'payment_purpose' => 'advance']);

        $card = $this->send("Alim Tok'a işe başlamadan önce 20 bin verdim");
        $this->assertStringNotContainsString('ne için', mb_strtolower($card));
        $this->assertSame(1, mb_substr_count(mb_strtolower($card), 'önceden ödeme'), '"önceden ödeme" bir kez');
    }

    // ── 8 ─ alakasız cari önerisi ───────────────────────────────────

    public function test_8_unrelated_party_suggestion_is_dropped(): void
    {
        $ayhan = Party::create(['name' => 'UR Ayhan Ergül']);
        $this->ai(['kind' => 'collection', 'party_id' => $ayhan->id, 'party_said' => 'Ali', 'party_certain' => false,
            'amount' => 5000.0, 'question' => 'Listedeki Ayhan Ergül mü?']);

        $reply = $this->send('Ali 5 bin verdi');
        $this->assertStringContainsString('Carilerde "Ali" yok', $reply);
        $this->assertStringNotContainsString('Ayhan', $reply);
    }

    public function test_8_related_name_still_asks_which_person(): void
    {
        $sinan = Party::create(['name' => 'UR Sinan Sele']);
        $this->ai(['kind' => 'collection', 'party_id' => $sinan->id, 'party_said' => 'Sinan usta', 'party_certain' => false,
            'amount' => 5000.0, 'question' => 'UR Sinan Sele mi, başka bir Sinan mı?']);

        $reply = $this->send('Sinan usta 5 bin verdi');
        $this->assertStringContainsString('Sinan Sele mi', $reply, 'ortak kelime var → eski soru');
    }

    // ── 9 ─ kasa başlığı ────────────────────────────────────────────

    public function test_9_till_title_is_always_branch_kasa(): void
    {
        config(['modules.projects' => true]);
        $erg = Project::create(['name' => 'Ergenekon', 'status' => 'active']);
        $izz = Project::create(['name' => 'İzzetpaşa', 'status' => 'active']);
        $title = fn (array $d) => (new \ReflectionMethod(TradeDraft::class, 'tillTitle'))->invoke(null, $d);

        $this->assertSame('Ergenekon kasa', $title(['project_id' => $erg->id, 'description' => 'Ergenekon şubesi günlük kasa (kart+nakit)']));
        $this->assertSame('Ergenekon kasa', $title(['project_id' => $erg->id, 'description' => 'Ergenekon akşam kasa']));
        $this->assertSame('İzzetpaşa kasa', $title(['project_id' => $izz->id, 'description' => 'İzzetpaşa kasa - nakit + pos']));
        $this->assertSame('Ergenekon kasa — Ergenekon kasa, 500 tl eksik çıktı',
            $title(['project_id' => $erg->id, 'description' => 'Ergenekon kasa, 500 tl eksik çıktı']), 'kalıp dışı not korunur');
        $this->assertSame('Kasa', $title(['description' => 'günlük kasa nakit']));
    }
}
