<?php

namespace Tests\Feature;

use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Parties\Pages\EditParty;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Alacak-verecek (esnaf) sektörü: cari (iki yön) + gider; proje, mimar modülleri, çek, ana sayfa yok.
 * Proje kapalıyken panelde ve WhatsApp'ta proje hiç görünmez / sorulmaz / açılmaz.
 */
class AlacakVerecekProfileTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('alacak_verecek')]);
    }

    public function test_profile_is_cari_and_expenses_without_projects(): void
    {
        $m = config('modules');

        foreach (['expenses', 'cari_supplier'] as $on) {
            $this->assertTrue($m[$on], "{$on} açık olmalı");
        }
        foreach (['projects', 'land_share', 'property_tax', 'checks', 'dashboard', 'contracts', 'stock', 'quotes', 'direct_sales'] as $off) {
            $this->assertFalse($m[$off], "{$off} kapalı olmalı");
        }
        $this->assertTrue(ModuleProfiles::resolve('mimar')['projects'], 'mimarda projeler açık kalmalı');
    }

    public function test_panel_hides_projects(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/projects')->assertForbidden();

        // Gider ekleme liste sayfasındaki pencere (ayrı sayfa yok).
        Livewire::test(ListExpenses::class)
            ->assertTableColumnHidden('project.name')
            ->mountAction('create')
            ->assertFormFieldHidden('project_id');

        config(['modules.projects' => true]);
        Livewire::test(ListExpenses::class)
            ->assertTableColumnVisible('project.name')
            ->mountAction('create')
            ->assertFormFieldVisible('project_id');
    }

    public function test_whatsapp_expense_never_mentions_or_asks_project(): void
    {
        config(['services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['05550000000']]);
        Http::fake();
        Project::create(['name' => 'Eski Şantiye', 'status' => 'active']);

        $mock = Mockery::mock(ExpenseExtractor::class);
        $mock->shouldReceive('extract')->andReturn([
            'kind' => ExpenseExtractor::KIND_EXPENSE, 'items' => [], 'payment_type' => null, 'amount' => 500.0,
            'date' => now()->format('Y-m-d'), 'due_date' => null, 'description' => 'Market',
            'project_id' => null, 'project_name' => null, 'party_id' => null, 'party_name' => null,
            'category_id' => null, 'category_name' => null, 'paid' => true, 'confidence' => 'high',
            'question' => null, 'is_new_entry' => true,
        ]);
        $this->app->instance(ExpenseExtractor::class, $mock);

        $reply = $this->post('/whatsapp/webhook', ['From' => 'whatsapp:+905550000000', 'Body' => 'Market 500', 'NumMedia' => 0])
            ->assertOk()->getContent();

        $this->assertStringContainsString('500', $reply);
        $this->assertStringNotContainsString('Proje', $reply);
        $this->assertStringNotContainsString('Eski Şantiye', $reply);
    }

    public function test_extractor_drops_project_ai_suggested(): void
    {
        config(['services.anthropic.api_key' => 'sk-test']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'tool_use', 'name' => 'save_entry', 'input' => [
                'kind' => 'expense', 'amount' => 500, 'project_name' => 'Yeni Şantiye', 'project_id' => 3,
            ]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])]);

        $out = app(ExpenseExtractor::class)->extract('Yeni şantiyeye 500 TL çivi');

        $this->assertNull($out['project_id']);
        $this->assertNull($out['project_name'], 'projesiz kurulumda AI yeni proje açtıramaz');
    }

    public function test_cari_page_works_without_projects(): void
    {
        $this->actingAs(User::factory()->create());
        $party = Party::create(['name' => 'Ali Usta']);

        $this->get("/admin/parties/{$party->id}/edit")->assertOk()->assertSee('Ali Usta');

        // Satış / alış / ödeme (elle hareket) ve tahsilat pencerelerinde proje seçimi yok.
        $page = Livewire::test(EditParty::class, ['record' => $party->getRouteKey()]);
        foreach (['satis', 'alis', 'odeme'] as $type) {
            $page->mountAction('newLedgerEntry', ['type' => $type])->assertFormFieldHidden('project_id')->unmountAction();
        }
        $page->mountAction('newCollection')->assertFormFieldHidden('project_id');

        config(['modules.projects' => true]);
        Livewire::test(EditParty::class, ['record' => $party->getRouteKey()])
            ->mountAction('newLedgerEntry', ['type' => 'odeme'])->assertFormFieldVisible('project_id');
    }
}
