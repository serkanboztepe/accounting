<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Services\Whatsapp\ChatAssistant;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\Understanding;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WhatsappAssistantPilotTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('mimar', ['smart_assistant' => true]),
            'app.profile' => 'mimar', 'services.anthropic.api_key' => 'pilot-test-key']);
        $this->travelTo(now()->setDate(2026, 10, 23)->setTime(12, 0));
    }

    public static function modes(): array
    {
        return ['disabled' => [false, 'off'], 'enabled' => [true, 'on']];
    }

    /** Kapalı pilot 44f6222'yi, açık pilot gerçek modelle ölçülen 9b2eaff'ı aynen korur. */
    #[DataProvider('modes')]
    public function test_rules_schema_and_normalization_match_the_recorded_baselines(bool $enabled, string $mode): void
    {
        config(['modules.assistant_pilot' => $enabled]);
        $baseline = json_decode(file_get_contents(__DIR__ . '/../Fixtures/whatsapp-pilot-baseline.json'), true);

        $this->assertSame($baseline[$mode]['rules'], hash('sha256', Understanding::rules()), 'talimat baytları');
        $this->assertSame($baseline[$mode]['schema'], hash('sha256', json_encode(Understanding::schema())), 'şema alan sırası dahil');
        $this->assertSame($baseline[$mode]['normalized'], hash('sha256', json_encode(Understanding::normalize($baseline['input']))), 'çıktı alan sırası dahil');
    }

    public function test_pilot_is_disabled_by_default_for_every_profile(): void
    {
        foreach ([null, 'mimar', 'muteahhit', 'trade', 'alacak_verecek'] as $profile) {
            $this->assertFalse(ModuleProfiles::resolve($profile)['assistant_pilot']);
            $this->assertTrue(ModuleProfiles::resolve($profile, ['assistant_pilot' => true])['assistant_pilot']);
        }
    }

    #[DataProvider('modes')]
    public function test_both_behavior_changes_are_controlled_by_the_pilot(bool $enabled, string $mode): void
    {
        config(['modules.assistant_pilot' => $enabled]);
        $party = Party::create(['name' => 'Pilot Test Müşterisi']);
        PartyLedgerEntry::create(['party_id' => $party->id, 'type' => PartyLedgerEntry::TYPE_SALE,
            'amount' => 120000, 'entry_date' => now()->toDateString()]);
        $entry = ['kind' => ExpenseExtractor::KIND_COLLECTION, 'party_id' => $party->id, 'amount' => 25000,
            'question' => 'Bu ödeme hangi iş için?', 'question_about' => 'purpose', 'party_certain' => true];

        $this->assertSame($enabled, Understanding::purposeQuestionIsMoot($entry));
        $scale = Understanding::amountScaleQuestion(['kind' => ExpenseExtractor::KIND_COLLECTION, 'amount' => 50000],
            "Kalan 80 binin 50'sini gönderdi");
        $enabled ? $this->assertNull($scale) : $this->assertNotNull($scale);
    }

    public function test_disabling_pilot_restores_chat_and_extractor_requests_even_with_a_pilot_draft(): void
    {
        $off = $this->requests(false);
        $on = $this->requests(true);
        $offAgain = $this->requests(false);

        $this->assertCount(2, $off);
        $this->assertSame($off, $offAgain, 'pilot kapatılınca aynı istek gövdelerine dönülür');
        $this->assertStringNotContainsString('question_about', json_encode($off));
        $this->assertStringContainsString('question_about', json_encode($on));
    }

    private function requests(bool $enabled): array
    {
        config(['modules.assistant_pilot' => $enabled]);
        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $data = $request->data();
            $requests[] = $request->body();
            $extract = ($data['tools'][0]['name'] ?? null) === 'save_entry';

            return Http::response(['content' => $extract
                ? [['type' => 'tool_use', 'id' => 'tool-pilot', 'name' => 'save_entry', 'input' => [
                    'kind' => ExpenseExtractor::KIND_COLLECTION, 'amount' => 25000, 'party_name' => 'Pilot Müşteri']]]
                : [['type' => 'text', 'text' => 'Tamam.']]], 200);
        });
        $pending = ['kind' => ExpenseExtractor::KIND_COLLECTION, 'amount' => 25000, 'party_name' => 'Pilot Müşteri',
            'question' => 'Bu ödeme hangi iş için?', 'question_about' => 'purpose'];
        $extractor = app(ExpenseExtractor::class);
        app(ChatAssistant::class)->respond('905557770051', 'EFT geldi', $pending, fresh: true);
        $extractor->extract('EFT geldi', null, $pending);

        return $requests;
    }
}
