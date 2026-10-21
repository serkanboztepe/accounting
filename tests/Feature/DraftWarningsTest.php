<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\DraftWarnings;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\ModuleProfiles;
use App\Support\TradeDesk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/** Faz 4 — çift kayıt ve olağandışı tutar uyarısı (özete ⚠️, engellemez); normal tutarda sessiz. */
class DraftWarningsTest extends TestCase
{
    use DatabaseTransactions;

    private string $day;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('trade', ['projects' => true]), 'app.profile' => 'trade']);
        $this->day = now()->addYears(4)->toDateString(); // yerel eski veriden uzak
    }

    public function test_duplicate_expense_in_same_category(): void
    {
        $cat = ExpenseCategory::create(['name' => 'Uyarı Testi Kira']);
        Expense::create(['amount' => 12000, 'expense_date' => $this->day, 'expense_category_id' => $cat->id, 'description' => 'Kira', 'payment_status' => 'paid']);

        $w = DraftWarnings::for(['kind' => 'expense', 'amount' => 12000, 'category_id' => $cat->id, 'date' => $this->day]);

        $this->assertStringContainsString('Aynı tutarda kira', $w[0]);
    }

    public function test_duplicate_payment_to_same_party(): void
    {
        $p = Party::create(['name' => 'Uyarı Testi Usta']);
        PartyLedgerEntry::create(['party_id' => $p->id, 'type' => 'odeme', 'amount' => 5000, 'entry_date' => $this->day, 'description' => 'Ödeme']);

        $this->assertNotEmpty(DraftWarnings::for(['kind' => 'payment', 'amount' => 5000, 'party_id' => $p->id, 'date' => $this->day]));
        $this->assertSame([], DraftWarnings::for(['kind' => 'payment', 'amount' => 5500, 'party_id' => $p->id, 'date' => $this->day]), 'farklı tutar');
    }

    public function test_unusual_till_amount_extra_zero(): void
    {
        $branch = Project::create(['name' => 'Uyarı Testi Şube', 'status' => 'active']);
        foreach ([21000, 24000, 26000, 22500] as $i => $amt) {
            TradeDesk::saveSale(['mode' => 'cash', 'manual_amount' => (string) $amt, 'project_id' => $branch->id,
                'sale_date' => now()->addYears(4)->subDays($i + 1)->toDateString()]);
        }
        $base = ['kind' => 'trade_sale', 'project_id' => $branch->id, 'party_id' => null, 'party_name' => null, 'date' => $this->day];

        $w = DraftWarnings::for($base + ['amount' => 244830]);
        $this->assertStringContainsString('genelde ~', $w[0]);
        $this->assertStringContainsString('244.830', $w[0]);
        $this->assertSame([], DraftWarnings::for($base + ['amount' => 27440]), 'normal kasa uyarısız');
        $this->assertSame([], DraftWarnings::for($base + ['amount' => 5000]), 'düşük tutar uyarısız (kullanıcı 20.10)');
        $this->assertSame([], DraftWarnings::for($base + ['amount' => 120000]), '~5 kat: uyarısız, yalnız fazladan sıfır');
    }

    public function test_warning_appears_before_footer_in_reply(): void
    {
        config(['services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 888 00 12'],
            'services.anthropic.chat_assistant' => false, 'modules' => ModuleProfiles::resolve('alacak_verecek')]);
        Http::fake();
        $p = Party::create(['name' => 'Uyarı Testi Recep']);
        PartyLedgerEntry::create(['party_id' => $p->id, 'type' => 'odeme', 'amount' => 8000, 'entry_date' => now()->toDateString(), 'description' => 'Ödeme']);
        $m = Mockery::mock(ExpenseExtractor::class);
        $m->shouldReceive('extract')->andReturn(['kind' => 'payment', 'items' => [], 'amount' => 8000, 'paid' => true, 'date' => now()->toDateString(),
            'description' => 'Ödeme', 'party_id' => $p->id, 'party_name' => null, 'project_id' => null, 'project_name' => null,
            'confidence' => 'high', 'question' => null, 'is_new_entry' => true]);
        $this->app->instance(ExpenseExtractor::class, $m);
        WhatsappPendingExpense::where('phone', 'whatsapp:+905558880012')->update(['status' => 'superseded']);

        $reply = html_entity_decode($this->post('/whatsapp/webhook', ['From' => 'whatsapp:+905558880012', 'Body' => 'Recepe 8000 ödedim', 'NumMedia' => 0])
            ->getContent(), ENT_QUOTES | ENT_XML1);

        $this->assertMatchesRegularExpression('/⚠️ Aynı tutarda[^\n]*\n(\n?Kısaca yaz|\n?Kaydedeyim mi|Vazgeçmek için)/u', $reply);
    }
}
