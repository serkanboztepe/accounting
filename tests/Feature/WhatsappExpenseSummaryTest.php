<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\ContractItem;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\ExpenseSummary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * WhatsApp "bu ay ne kadar giderim var?" — eskiden toplam BORÇ sorusu sanılıp carilere olan
 * açık borç söyleniyordu. Gider özeti rapor sayfasıyla aynı hesap: gider + sözleşme teslimatı;
 * cari "Alış / Hizmet" toplama girmez, ayrı satırda bilgi olarak gösterilir.
 * Tarihler 2031: geliştirme veritabanındaki gerçek kayıtlar karışmasın.
 */
class WhatsappExpenseSummaryTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905550000000';

    private Party $party;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.twilio.verify_signature' => false,
            'services.twilio.allowed_phones' => ['0555 000 00 00'],
            'modules.expenses' => true,
            'modules.contracts' => true,
        ]);
        Http::fake();
        $this->party = Party::create(['name' => 'Özet Test Beton']);
        $this->project = Project::create(['name' => 'Özet Test Projesi', 'status' => 'active']);
    }

    private function ask(?string $from, ?string $to, ?int $projectId = null): string
    {
        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturn([
            'kind' => ExpenseExtractor::KIND_EXPENSE_SUMMARY, 'items' => [], 'totals_side' => null,
            'date_from' => $from, 'date_to' => $to, 'payment_type' => null, 'amount' => 0.0,
            'date' => now()->format('Y-m-d'), 'due_date' => null, 'description' => '',
            'project_id' => $projectId, 'project_name' => null, 'party_id' => null, 'party_name' => null,
            'category_id' => null, 'category_name' => null, 'paid' => null, 'confidence' => 'high',
            'question' => null, 'is_new_entry' => false,
        ]);
        $this->app->instance(ExpenseExtractor::class, $mock);

        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => 'bu ay ne kadar giderim var', 'NumMedia' => 0])
            ->assertOk()
            ->getContent(), ENT_QUOTES | ENT_XML1);
    }

    private function expense(string $date, float $amount, string $category, string $status = 'paid', ?int $projectId = null): void
    {
        Expense::create([
            'expense_date' => $date,
            'amount' => $amount,
            'payment_status' => $status,
            'expense_category_id' => ExpenseCategory::firstOrCreate(['name' => $category])->id,
            'project_id' => $projectId,
        ]);
    }

    private function delivery(string $date, float $amount, ?int $projectId = null): void
    {
        $contract = Contract::create([
            'project_id' => $this->project->id, 'party_id' => $this->party->id, 'title' => 'Özet Test Sözleşme',
            'contract_type' => Contract::TYPE_SUPPLY, 'direction' => Contract::DIRECTION_PURCHASE,
            'total_amount' => 0, 'status' => 'active',
        ]);
        $item = ContractItem::create(['contract_id' => $contract->id, 'description' => 'Beton', 'quantity' => 100, 'unit_price' => 100, 'amount' => 10000]);
        ContractDelivery::create([
            'contract_id' => $contract->id, 'contract_item_id' => $item->id, 'project_id' => $projectId ?? $this->project->id,
            'delivery_date' => $date, 'quantity' => $amount / 100, 'unit_price' => 100, 'amount' => $amount,
        ]);
    }

    public function test_period_total_with_categories_and_unpaid_excludes_other_months(): void
    {
        $this->expense('2031-03-02', 10000, 'Özet Malzeme');
        $this->expense('2031-03-20', 5000, 'Özet Yakıt', 'unpaid');
        $this->expense('2031-03-31', 2500.5, 'Özet Malzeme');
        $this->expense('2031-04-01', 99999, 'Özet Malzeme');   // dönem dışı
        $this->expense('2031-02-28', 88888, 'Özet Yakıt');     // dönem dışı

        $reply = $this->ask('2031-03-01', '2031-03-31');

        $this->assertStringContainsString('Mart 2031 giderin: 17.500,50 ₺* (3 kayıt)', $reply);
        $this->assertStringContainsString('• Özet Malzeme: 12.500,50', $reply);
        $this->assertStringContainsString('• Özet Yakıt: 5.000,00', $reply);
        $this->assertStringContainsString('Ödemesi tamamlanmamış: 5.000,00 ₺', $reply);
        // Eski hata: borç sorusu sanılıyordu.
        $this->assertStringNotContainsString('borcun', $reply);
    }

    public function test_contract_deliveries_are_added_on_separate_line_like_the_report(): void
    {
        $this->expense('2031-03-05', 20000, 'Özet İşçilik', 'paid', $this->project->id);
        $this->delivery('2031-03-10', 30000);
        $this->delivery('2031-05-10', 70000); // dönem dışı

        $reply = $this->ask('2031-03-01', '2031-03-31');

        $this->assertStringContainsString('Mart 2031 giderin: 50.000,00 ₺*', $reply);
        $this->assertStringContainsString('• Giderler: 20.000,00 ₺ (1 kayıt)', $reply);
        $this->assertStringContainsString('• Sözleşme teslimatları: 30.000,00 ₺', $reply);
    }

    public function test_project_filter_matches_report_cost_sources(): void
    {
        $other = Project::create(['name' => 'Özet Diğer Proje', 'status' => 'active']);
        $this->expense('2031-03-05', 20000, 'Özet İşçilik', 'paid', $this->project->id);
        $this->expense('2031-03-06', 7000, 'Özet İşçilik', 'paid', $other->id);
        // Çapraz proje: sözleşme bu projenin ama teslimat diğer projeye → diğerine sayılır.
        $this->delivery('2031-03-10', 30000, $other->id);
        $this->delivery('2031-03-11', 4000);

        $s = ExpenseSummary::build('2031-03-01', '2031-03-31', $this->project->id);

        $this->assertEqualsWithDelta(20000, $s['expenses'], 0.001);
        $this->assertEqualsWithDelta(4000, $s['deliveries'], 0.001);
        $this->assertEqualsWithDelta(24000, $s['total'], 0.001);
        $this->assertStringContainsString('Özet Test Projesi — Mart 2031 giderin: 24.000,00 ₺', $this->ask('2031-03-01', '2031-03-31', $this->project->id));
    }

    public function test_ledger_purchases_are_shown_but_not_added_to_total(): void
    {
        $this->expense('2031-03-05', 1000, 'Özet Yakıt');
        PartyLedgerEntry::create([
            'party_id' => $this->party->id, 'entry_date' => '2031-03-07',
            'type' => PartyLedgerEntry::TYPE_PURCHASE, 'amount' => 80000, 'description' => 'Mal alışı',
        ]);

        $reply = $this->ask('2031-03-01', '2031-03-31');

        $this->assertStringContainsString('Mart 2031 giderin: 1.000,00 ₺*', $reply);
        $this->assertStringContainsString('Ayrıca cari alışların: 80.000,00 ₺ (gidere dahil değil)', $reply);
    }

    public function test_deliveries_ignored_when_contracts_module_is_off(): void
    {
        config(['modules.contracts' => false]); // alacak_verecek / mimar
        $this->expense('2031-03-05', 1000, 'Özet Yakıt');
        $this->delivery('2031-03-10', 30000);

        $reply = $this->ask('2031-03-01', '2031-03-31');

        $this->assertStringContainsString('Mart 2031 giderin: 1.000,00 ₺* (1 kayıt)', $reply);
        $this->assertStringNotContainsString('teslimat', $reply);
    }

    public function test_empty_period_and_default_is_current_month(): void
    {
        $this->assertStringContainsString('Mart 2031 kayıtlı giderin yok.', $this->ask('2031-03-01', '2031-03-31'));

        $s = ExpenseSummary::build(null, null);
        $today = now(ExpenseSummary::TIMEZONE);
        $this->assertSame($today->copy()->startOfMonth()->toDateString(), $s['from']);
        $this->assertSame($today->toDateString(), $s['to']);
    }

    public function test_kind_is_offered_only_when_expenses_module_is_on(): void
    {
        $this->assertContains(ExpenseExtractor::KIND_EXPENSE_SUMMARY, ExpenseExtractor::allowedKinds());

        config(['modules.expenses' => false]); // mimar
        $this->assertNotContains(ExpenseExtractor::KIND_EXPENSE_SUMMARY, ExpenseExtractor::allowedKinds());
    }
}
