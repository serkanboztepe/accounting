<?php

namespace Tests\Feature\Features;

use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\ModuleProfiles;
use Tests\TestCase;

/**
 * Özellik düzeni (plan optimized-kindling-rain, Faz A): modül kontrolleri tek kapıya taşınırken davranış
 * birebir aynı kalmalı. Bugünkü kodun çıktısı (menü erişimi, WhatsApp işlem türleri, yardımcı kapılar)
 * paketler + canlı firma ayarları (26.10 okundu) + rastgele kombinasyonlar için dosyaya yazılır; sonraki
 * her çalıştırma aynısını bekler.
 *
 * Fotoğrafı yenilemek (yalnız bilinçli davranış değişikliğinde): FEATURE_SNAPSHOT_WRITE=1 ile çalıştır.
 */
class GateSnapshotTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/feature_gates_snapshot.json';

    /** Canlı firmaların 26.10 ayarları (hub_firms.settings: profile + modules ezmeleri). */
    private const LIVE = [
        'firm_1'  => ['mimar', ['smart_assistant' => true, 'cari_supplier' => true, 'natural_voice' => true, 'proactive_alerts' => true, 'checks' => true, 'assistant_pilot' => true]],
        'firm_2'  => ['muteahhit', ['smart_assistant' => true, 'natural_voice' => true, 'proactive_alerts' => true, 'contracts' => true, 'purchase_contracts' => true, 'dashboard' => true]],
        'firm_3'  => ['trade', ['projects' => true, 'smart_assistant' => true, 'natural_voice' => true, 'proactive_alerts' => true]],
        'firm_4'  => ['alacak_verecek', ['smart_assistant' => true, 'natural_voice' => true, 'proactive_alerts' => true]],
        'firm_7'  => ['muteahhit', ['smart_assistant' => true, 'natural_voice' => true, 'proactive_alerts' => true, 'fast_save' => true, 'agreements' => true, 'assistant_pilot' => true]],
        'firm_8'  => ['alacak_verecek', ['expenses' => false, 'smart_assistant' => true, 'natural_voice' => true, 'proactive_alerts' => true]],
        'firm_9'  => ['trade', ['smart_assistant' => true, 'report_monthly' => false, 'natural_voice' => true, 'proactive_alerts' => true, 'assistant_pilot' => true]],
        'firm_11' => ['alacak_verecek', ['smart_assistant' => true, 'natural_voice' => true, 'proactive_alerts' => true, 'checks' => true, 'assistant_pilot' => true]],
        'firm_12' => ['trade', ['smart_assistant' => true, 'projects' => true, 'proactive_alerts' => true, 'natural_voice' => true, 'assistant_pilot' => true]],
    ];

    /** canAccess (ve menü kaydı) modüle bağlı olan panel sınıfları. */
    private const PANEL = [
        \App\Filament\Pages\MonthlyResultReport::class,
        \App\Filament\Pages\ProjectReports::class,
        \App\Filament\Pages\SalesSummary::class,
        \App\Filament\Pages\StockReport::class,
        \App\Filament\Pages\Dashboard::class,
        \App\Filament\Resources\Agreements\AgreementResource::class,
        \App\Filament\Resources\AssistantMemories\AssistantMemoryResource::class,
        \App\Filament\Resources\Checks\CheckResource::class,
        \App\Filament\Resources\ContractPayments\ContractPaymentResource::class,
        \App\Filament\Resources\Contracts\ContractResource::class,
        \App\Filament\Resources\ExpenseCategories\ExpenseCategoryResource::class,
        \App\Filament\Resources\Expenses\ExpenseResource::class,
        \App\Filament\Resources\LandShareStudies\LandShareStudyResource::class,
        \App\Filament\Resources\Products\ProductResource::class,
        \App\Filament\Resources\Projects\ProjectResource::class,
        \App\Filament\Resources\PropertyTaxBlocks\PropertyTaxBlockResource::class,
        \App\Filament\Resources\PropertyTaxProjects\PropertyTaxProjectResource::class,
        \App\Filament\Resources\Purchases\PurchaseResource::class,
        \App\Filament\Resources\Quotes\QuoteResource::class,
        \App\Filament\Resources\Sales\SaleResource::class,
        \App\Filament\Resources\StockMovements\StockMovementResource::class,
        \App\Filament\Resources\Units\UnitResource::class,
    ];

    public function test_gates_match_snapshot(): void
    {
        $actual = [];
        foreach ($this->cases() as $name => [$profile, $overrides]) {
            $actual[$name] = $this->observe($profile, $overrides);
        }

        if (getenv('FEATURE_SNAPSHOT_WRITE') === '1' || ! file_exists(self::FIXTURE)) {
            @mkdir(dirname(self::FIXTURE), 0755, true);
            file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
            $this->markTestIncomplete('Fotoğraf yazıldı: ' . count($actual) . ' durum. Tekrar çalıştır.');
        }

        $expected = json_decode((string) file_get_contents(self::FIXTURE), true);
        $this->assertSame(array_keys($expected), array_keys($actual), 'durum listesi değişti');
        foreach ($expected as $name => $exp) {
            $this->assertSame($exp, $actual[$name], "durum: {$name}");
        }
    }

    /** Pozitif kontrol: bir anahtar değişince gözlem de değişmeli (test kör değil). */
    public function test_observation_sees_a_single_key_change(): void
    {
        $on = $this->observe('trade', []);
        $off = $this->observe('trade', ['purchases' => false]);

        $this->assertNotSame($on, $off);
        $this->assertTrue($on['panel'][\App\Filament\Resources\Purchases\PurchaseResource::class]['access']);
        $this->assertFalse($off['panel'][\App\Filament\Resources\Purchases\PurchaseResource::class]['access']);
    }

    /** @return array<string, array{0:?string, 1:array<string,bool>}> */
    private function cases(): array
    {
        $cases = ['none' => [null, []]];
        foreach (array_keys(ModuleProfiles::PROFILES) as $profile) {
            $cases["profile_{$profile}"] = [$profile, []];
        }
        $cases += self::LIVE;

        // Rastgele ama sabit (tohumlu) kombinasyonlar: iç içe koşullar ("alış VE stok") da yakalansın.
        mt_srand(20261026);
        for ($i = 0; $i < 60; $i++) {
            $overrides = [];
            foreach (ModuleProfiles::KEYS as $key) {
                $overrides[$key] = mt_rand(0, 1) === 1;
            }
            $cases["random_{$i}"] = [null, $overrides];
        }
        mt_srand();

        return $cases;
    }

    private function observe(?string $profile, array $overrides): array
    {
        config([
            'modules' => ModuleProfiles::resolve($profile, $overrides),
            'app.profile' => $profile,
            'services.anthropic.chat_assistant' => false,
            'services.anthropic.api_key' => 'test',
        ]);

        $panel = [];
        foreach (self::PANEL as $class) {
            $panel[$class] = [
                'access' => (bool) $class::canAccess(),
                'nav' => method_exists($class, 'shouldRegisterNavigation') ? (bool) $class::shouldRegisterNavigation() : null,
            ];
        }

        $tradeStock = new \ReflectionMethod(ExpenseExtractor::class, 'tradeStock');

        return [
            'panel' => $panel,
            'kinds' => ExpenseExtractor::allowedKinds(),
            'helpers' => [
                'tradeEnabled' => ExpenseExtractor::tradeEnabled(),
                'tradeStock' => (bool) $tradeStock->invoke(null),
                'chatAssistant' => \App\Services\Whatsapp\ChatAssistant::enabled(),
                'naturalVoice' => \App\Services\Whatsapp\NaturalVoice::enabled(),
            ],
        ];
    }
}
