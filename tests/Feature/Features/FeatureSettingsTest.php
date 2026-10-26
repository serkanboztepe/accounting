<?php

namespace Tests\Feature\Features;

use App\Exceptions\FeatureDisabled;
use App\Models\Agreement;
use App\Models\Expense;
use App\Models\Party;
use App\Models\Reminder;
use App\Support\FeatureSettings;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Özellik düzeni Faz B (plan 26.10): saklı kutular ↔ modül anahtarları, canlı firmaların dönüşümü, yazma engeli.
 */
class FeatureSettingsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Canlı 11 firmanın 26.10 ayarları + dönüşümde BEKLENEN farklar (kullanıcı kararları: çekler herkese, Ay Sonucu
     * Stok'la birlikte). Firma 6 = 4, firma 10 = 8 ile aynı ayarda.
     */
    private const LIVE = [
        1  => ['mimar', ['smart_assistant' => true, 'cari_supplier' => true, 'natural_voice' => true, 'proactive_alerts' => true, 'checks' => true, 'assistant_pilot' => true], []],
        2  => ['muteahhit', ['smart_assistant' => true, 'natural_voice' => true, 'proactive_alerts' => true, 'contracts' => true, 'purchase_contracts' => true, 'dashboard' => true], []],
        3  => ['trade', ['projects' => true, 'smart_assistant' => true, 'natural_voice' => true, 'proactive_alerts' => true], ['checks']],
        4  => ['alacak_verecek', ['smart_assistant' => true, 'natural_voice' => true, 'proactive_alerts' => true], ['checks']],
        7  => ['muteahhit', ['smart_assistant' => true, 'natural_voice' => true, 'proactive_alerts' => true, 'fast_save' => true, 'agreements' => true, 'assistant_pilot' => true], []],
        8  => ['alacak_verecek', ['expenses' => false, 'smart_assistant' => true, 'natural_voice' => true, 'proactive_alerts' => true], ['checks']],
        9  => ['trade', ['smart_assistant' => true, 'report_monthly' => false, 'natural_voice' => true, 'proactive_alerts' => true, 'assistant_pilot' => true], ['checks', 'report_monthly']],
        11 => ['alacak_verecek', ['smart_assistant' => true, 'natural_voice' => true, 'proactive_alerts' => true, 'checks' => true, 'assistant_pilot' => true], []],
        12 => ['trade', ['smart_assistant' => true, 'projects' => true, 'proactive_alerts' => true, 'natural_voice' => true, 'assistant_pilot' => true], ['checks']],
    ];

    private function convert(?string $profile, array $overrides): array
    {
        $before = ModuleProfiles::resolve($profile, $overrides);
        $label = FeatureSettings::legacyPlaceLabel($profile);
        $settings = FeatureSettings::fromModules($before) + ['project_label' => $label];

        return [$before, $settings, FeatureSettings::toModules($settings), $label];
    }

    public function test_live_firms_convert_with_only_the_decided_differences(): void
    {
        foreach (self::LIVE as $id => [$profile, $overrides, $expectedKeys]) {
            [$before, $settings, $after, $label] = $this->convert($profile, $overrides);
            $diff = FeatureSettings::diff($before, $after);

            $this->assertSame($expectedKeys, array_keys($diff), "firma {$id}: fark listesi");
            foreach ($diff as $key => [$b, $a, $expected]) {
                $this->assertTrue($expected, "firma {$id}: {$key} beklenen fark sayılmalı");
                $this->assertFalse($b, "firma {$id}: {$key} yalnız açılır, kapanmaz");
            }
            $this->assertSame($profile, FeatureSettings::deriveProfile($settings['features'], $label), "firma {$id}: paket adı (üslup) aynı kalmalı");
        }
    }

    public function test_conversion_keeps_firm_specific_choices(): void
    {
        [, $ferhat] = $this->convert('mimar', self::LIVE[1][1]);
        $this->assertFalse($ferhat['features']['expenses'], 'Ferhat: gider kapalı kalır');
        $this->assertFalse($ferhat['features']['place_reports'], 'Ferhat: Raporlar + ana sayfa kapalı kalır');
        $this->assertTrue($ferhat['features']['land_share']);

        [, $muhasebe] = $this->convert('muteahhit', self::LIVE[2][1]);
        $this->assertTrue($muhasebe['legacy']['contracts'], 'firma 2: eski sözleşmeler açık kalır');
        $this->assertTrue($muhasebe['features']['place_reports']);

        [, $akkus, , $label] = $this->convert('trade', self::LIVE[12][1]);
        $this->assertSame('Şube', $label, 'Akkuş: görünen ad değişmez');
        $this->assertFalse($akkus['features']['place_reports'], 'Akkuş: Stok Raporu menüde kalır (Raporlar açılmaz)');

        [, $burak] = $this->convert('alacak_verecek', self::LIVE[8][1]);
        $this->assertFalse($burak['features']['expenses'], 'Burak: gider kapalı kalır');
    }

    public function test_land_share_and_property_tax_lock_places_on(): void
    {
        $f = FeatureSettings::normalizeFeatures(['land_share' => true, 'places' => false]);
        $this->assertTrue($f['places']);
        $f = FeatureSettings::normalizeFeatures(['property_tax' => true]);
        $this->assertTrue($f['places']);

        $f = FeatureSettings::normalizeFeatures(['places' => false, 'place_reports' => true]);
        $this->assertFalse($f['place_reports'], 'Yerler kapalıysa alt kutusu da kapalı');
    }

    public function test_stock_brings_everything_with_it(): void
    {
        $m = FeatureSettings::toModules(['features' => ['stock' => true]]);
        foreach (FeatureSettings::STOCK_KEYS as $key) {
            $this->assertTrue($m[$key], $key);
        }
        $m = FeatureSettings::toModules(['features' => ['stock' => false]]);
        foreach (FeatureSettings::STOCK_KEYS as $key) {
            $this->assertFalse($m[$key], $key);
        }
        $this->assertTrue($m['checks'] && $m['cari_supplier'], 'çekirdek her zaman açık');
    }

    public function test_new_feature_is_off_unless_listed(): void
    {
        $m = FeatureSettings::toModules(['features' => []]);
        $this->assertFalse($m['agreements']);
        $this->assertFalse($m['expenses']);
        $this->assertFalse($m['contracts']);
        $this->assertEqualsCanonicalizing(ModuleProfiles::KEYS, array_keys($m), 'her anahtar açıkça yazılı');
    }

    public function test_profile_is_derived_from_boxes(): void
    {
        $this->assertSame('mimar', FeatureSettings::deriveProfile(['land_share' => true], 'Proje'));
        $this->assertSame('trade', FeatureSettings::deriveProfile(['stock' => true, 'places' => true], 'Şube'));
        $this->assertSame('muteahhit', FeatureSettings::deriveProfile(['places' => true, 'expenses' => true], 'Şantiye'));
        $this->assertSame('alacak_verecek', FeatureSettings::deriveProfile(['places' => true], 'Şube'));
        $this->assertSame('alacak_verecek', FeatureSettings::deriveProfile(['expenses' => true], null));
    }

    public function test_unexpected_difference_is_flagged(): void
    {
        $diff = FeatureSettings::diff(['expenses' => true], ['expenses' => false]);
        $this->assertFalse($diff['expenses'][2], 'gider kapanması beklenmeyen fark');
    }

    // ── Yazma engeli ─────────────────────────────────────────────────

    public function test_expense_cannot_be_written_when_expenses_off(): void
    {
        config(['modules.expenses' => false]);

        $this->expectException(FeatureDisabled::class);
        Expense::create(['expense_date' => now()->toDateString(), 'amount' => 100, 'payment_status' => 'paid', 'description' => 'mazot']);
    }

    public function test_expense_is_written_when_expenses_on(): void
    {
        config(['modules.expenses' => true]);

        $e = Expense::create(['expense_date' => now()->toDateString(), 'amount' => 100, 'payment_status' => 'paid', 'description' => 'mazot']);
        $this->assertTrue($e->exists);
    }

    public function test_agreement_cannot_be_written_when_agreements_off(): void
    {
        config(['modules.agreements' => false]);
        $party = Party::create(['name' => 'Engel Deneme']);

        $this->expectException(FeatureDisabled::class);
        Agreement::create(['party_id' => $party->id, 'direction' => 'purchase', 'agreement_date' => now()->toDateString()]);
    }

    public function test_recurring_expense_reminder_does_not_write_when_expenses_off(): void
    {
        config(['modules.expenses' => false]);
        $r = new Reminder(['phone' => 'whatsapp:+905550000001', 'text' => 'ekmek', 'amount' => 34.60, 'repeat' => 'daily',
            'event_date' => now()->toDateString(), 'status' => 'active']);
        $r->save();

        $this->assertNull($r->postExpense(Carbon::now()));
        $this->assertSame(0, Expense::where('description', 'ekmek')->count());
    }
}
