<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Support\ExpensePartyMove;
use App\Support\PartyStatement;
use App\Support\ProjectCost;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Giderden kişi sökümü (kullanıcı 28.10: "hepsini cariye al"): kişili gider → aynı tarih / proje / açıklamayla alış (+ ödendiyse
 * ödeme, aynı proje); gider silinir; bakiye, proje maliyeti ve ay toplamı aynı kalır; kişisiz gidere dokunulmaz.
 */
class ExpensePartyMoveTest extends TestCase
{
    use DatabaseTransactions;

    private Party $party;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.expenses' => true, 'modules.projects' => true, 'modules.cari_supplier' => true]);
        $this->party = Party::create(['name' => 'Taşıma Test Ferhat']);
        $this->project = Project::create(['name' => 'Taşıma Test Cumhuriyet', 'status' => 'active']);
        // Ödenmemiş gideri karşılayan eski ödeme (canlıda Ferhat: bakiye 0).
        PartyLedgerEntry::create(['party_id' => $this->party->id, 'entry_date' => '2026-03-20', 'type' => PartyLedgerEntry::TYPE_PAYMENT, 'amount' => 300000]);
    }

    private function expense(array $o): Expense
    {
        return Expense::create($o + ['expense_date' => '2026-03-26', 'party_id' => $this->party->id, 'project_id' => $this->project->id]);
    }

    public function test_unpaid_and_paid_expenses_move_to_party_with_project(): void
    {
        $cat = ExpenseCategory::firstOrCreate(['name' => 'Resmi / Ruhsat / Harç']);
        $unpaid = $this->expense(['amount' => 300000, 'payment_status' => 'unpaid', 'description' => 'Proje', 'due_date' => '2026-04-30']);
        $paid = $this->expense(['amount' => 45000, 'payment_status' => 'paid', 'description' => 'Yola Terk', 'expense_category_id' => $cat->id]);
        $plain = Expense::create(['expense_date' => '2026-03-26', 'amount' => 900, 'payment_status' => 'paid', 'description' => 'Taşıma Test Yakıt',
            'project_id' => $this->project->id]);
        $balance = PartyStatement::build($this->party)['balance'];
        $projectTotal = ProjectCost::expenses($this->project->id) + ProjectCost::purchases($this->project->id);

        $r = ExpensePartyMove::run();

        $this->assertCount(2, collect($r['rows'])->where('party', $this->party->name), 'yalnız kişili giderler');
        $this->assertNull(Expense::find($unpaid->id), 'gider silindi');
        $this->assertNull(Expense::find($paid->id));
        $this->assertNotNull(Expense::find($plain->id), 'kişisiz gidere dokunulmaz');

        $rows = PartyLedgerEntry::where('party_id', $this->party->id)->where('notes', 'like', ExpensePartyMove::NOTE . '%')->get();
        $purchase = $rows->firstWhere('description', 'Proje');
        $this->assertSame(PartyLedgerEntry::TYPE_PURCHASE, $purchase->type);
        $this->assertSame($this->project->id, $purchase->project_id, 'proje aynen');
        $this->assertSame('2026-03-26', $purchase->entry_date->toDateString());
        $this->assertSame('2026-04-30', $purchase->due_date?->toDateString(), 'ödenmemişin vadesi taşınır');
        $this->assertSame(1, $rows->where('description', 'Proje')->count(), 'ödenmemiş: yalnız alış');

        $this->assertSame(['alis', 'odeme'], $rows->filter(fn ($e) => str_contains($e->description, 'Yola Terk'))->pluck('type')->sort()->values()->all());
        $this->assertTrue($rows->filter(fn ($e) => str_contains($e->description, 'Yola Terk'))->every(fn ($e) => $e->project_id === $this->project->id),
            'ödeme satırı da aynı projede');
        $this->assertNotNull($rows->firstWhere('description', 'Yola Terk · Resmi / Ruhsat / Harç'), 'kategori açıklamaya');

        $this->assertEqualsWithDelta($balance, PartyStatement::build($this->party->fresh())['balance'], 0.001, 'bakiye aynı');
        $this->assertEqualsWithDelta($projectTotal, ProjectCost::expenses($this->project->id) + ProjectCost::purchases($this->project->id), 0.001,
            'proje maliyeti aynı');
        $this->assertSame([], ExpensePartyMove::run()['rows'], 'ikinci çalıştırma bir şey yapmaz');
    }

    public function test_command_is_dry_run_by_default(): void
    {
        $e = $this->expense(['amount' => 20000, 'payment_status' => 'paid', 'description' => 'Ev kira ödemesi']);

        $this->artisan('firms:expense-parties-to-ledger')->expectsOutputToContain('DENEME')->assertSuccessful();
        $this->assertNotNull(Expense::find($e->id), 'deneme yazmaz');

        $this->artisan('firms:expense-parties-to-ledger --write')->expectsOutputToContain('YAZILDI')->assertSuccessful();
        $this->assertNull(Expense::find($e->id));
        $this->assertSame(0, Expense::whereNotNull('party_id')->count());
    }
}
