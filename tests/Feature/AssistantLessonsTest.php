<?php

namespace Tests\Feature;

use App\Models\AssistantLesson;
use App\Models\ConversationReport;
use App\Services\Whatsapp\ConversationAnalyzer;
use App\Services\Whatsapp\ExpenseContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Hatadan öğrenme (Faz 3): gece analizi ders önerir → onaysız uygulanmaz → onaylı kural asistan bağlamına girer. */
class AssistantLessonsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        AssistantLesson::query()->update(['status' => 'retired']);
        AssistantLesson::forgetCache();
    }

    public function test_analyzer_extracts_lessons_block_and_keeps_report_clean(): void
    {
        config(['services.anthropic.api_key' => 'test', 'services.anthropic.analysis_model' => 'claude-opus-5-5']);
        $text = "ÖZET: 1 takılma\n**Bugün yapılması gereken:** x\n## Genel durum\nİyi.\n\n```dersler\n"
            . json_encode([['firm' => 'Akkuş Fırın', 'scope' => 'general', 'problem' => 'Kasa mesajı 25000 gider sanıldı',
                'cause' => 'karışık mesaj', 'rule' => 'Nakit + kart rakamı olan mesaj kasadır.', 'example_message' => 'Nakit 5000 pos 19483',
                'expected' => 'peşin satış']], JSON_UNESCAPED_UNICODE) . "\n```";
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => $text]], 'usage' => []], 200)]);

        $r = app(ConversationAnalyzer::class)->analyze('transcript', now());

        $this->assertStringNotContainsString('```dersler', $r['body']);
        $this->assertCount(1, $r['lessons']);
        $this->assertSame('Nakit + kart rakamı olan mesaj kasadır.', $r['lessons'][0]['rule']);
        $this->assertStringContainsString('[tutar]', $r['lessons'][0]['problem'], 'tutar maskeli');
    }

    public function test_only_approved_lessons_reach_the_assistant(): void
    {
        $report = ConversationReport::create(['report_date' => now()->addYears(3)->toDateString(), 'summary' => 's', 'body' => 'b']);
        $pending = AssistantLesson::create(['conversation_report_id' => $report->id, 'problem' => 'p', 'rule' => 'Onaysız kural XYZ', 'status' => 'pending']);
        AssistantLesson::forgetCache();
        $this->assertStringNotContainsString('Onaysız kural XYZ', ExpenseContext::build());

        $pending->update(['status' => 'approved']);
        AssistantLesson::forgetCache();
        $this->assertStringContainsString('Onaysız kural XYZ', ExpenseContext::build());

        $pending->update(['status' => 'retired']);
        AssistantLesson::forgetCache();
        $this->assertStringNotContainsString('Onaysız kural XYZ', ExpenseContext::build());
    }

    public function test_hub_page_approves(): void
    {
        if (! isset(\Filament\Facades\Filament::getPanels()['hub'])) {
            $this->app->register(\App\Providers\Filament\HubPanelProvider::class);
        }
        \Filament\Facades\Filament::setCurrentPanel('hub');
        $this->actingAs(\App\Models\HubUser::forceCreate(['name' => 'Hub', 'email' => 'hub-' . uniqid() . '@example.com', 'password' => 'x']), 'hub');
        $l = AssistantLesson::create(['problem' => 'Hub testi sorun', 'rule' => 'Hub testi kural', 'status' => 'pending']);

        \Livewire\Livewire::test(\App\Filament\Hub\AssistantLessons\Pages\ManageAssistantLessons::class)
            ->assertOk()->assertSee('Hub testi kural')
            ->callTableAction('approve', $l);

        $this->assertSame('approved', $l->fresh()->status);
    }
}
