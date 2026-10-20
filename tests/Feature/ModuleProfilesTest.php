<?php

namespace Tests\Feature;

use App\Support\ModuleProfiles;

use App\Services\Whatsapp\ExpenseExtractor;
use Tests\TestCase;

/**
 * APP_PROFILE (mimar / muteahhit / trade / alacak_verecek) — config/modules.php'nin ürettiği değerler,
 * canlı kurulumların (yildiz / muhasebe / yenyapi) bugünkü elle yazılmış MOD_* setiyle birebir aynı olmalı.
 * Ayrıca WhatsApp asistanının her modda kabul ettiği işlem türleri.
 */
class ModuleProfilesTest extends TestCase
{
    private const ENV_KEYS = ['APP_PROFILE', 'MOD_QUOTES', 'MOD_STOCK'];

    /** Test öncesi değerler — silmek yerine geri yüklenir (yoksa sonraki testler .env profiline düşer). */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::ENV_KEYS as $key) {
            $this->originalEnv[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $_SERVER[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
        parent::tearDown();
    }

    private function modulesFor(array $env): array
    {
        foreach ($env as $key => $value) {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }

        return require config_path('modules.php');
    }

    /** Listelenen anahtarlar (+ varsayılanı kapalı olanlar, ör. akıllı asistan) kapalı, gerisi açık olmalı. */
    private function assertOnlyDisabled(array $disabled, array $modules): void
    {
        $disabled = [...$disabled, ...\App\Support\ModuleProfiles::DEFAULT_OFF];
        foreach ($modules as $key => $value) {
            $this->assertSame(! in_array($key, $disabled, true), $value, "modules.{$key}");
        }
    }

    public function test_mimar_matches_yildiz(): void
    {
        $this->assertOnlyDisabled([
            'direct_sales', 'stock', 'purchase_contracts', 'sales_contracts', 'quotes', 'contracts',
            'checks', 'expenses', 'dashboard', 'cari_supplier', 'report_project', 'report_stock', 'report_sales',
        ], $this->modulesFor(['APP_PROFILE' => 'mimar']));
    }

    /** Sade müteahhit (20.10): sözleşme yok; projeler + proje raporu + çek + gider + cari iki yön açık. */
    public function test_muteahhit_is_simple_contractor(): void
    {
        $this->assertOnlyDisabled([
            'land_share', 'property_tax', 'direct_sales', 'stock', 'purchase_contracts', 'sales_contracts', 'quotes',
            'contracts', 'report_stock', 'report_sales',
        ], $this->modulesFor(['APP_PROFILE' => 'muteahhit']));
    }

    /** Muhasebe (firma 2) hub ezmesiyle eski sözleşmeli müteahhit seti aynen kalır. */
    public function test_muhasebe_override_keeps_old_contractor_set(): void
    {
        $old = ['land_share', 'property_tax', 'direct_sales', 'stock', 'sales_contracts', 'quotes', 'report_stock', 'report_sales'];
        $modules = ModuleProfiles::resolve('muteahhit', ['contracts' => true, 'purchase_contracts' => true, 'dashboard' => true]);

        foreach (ModuleProfiles::KEYS as $key) {
            if (in_array($key, ModuleProfiles::DEFAULT_OFF, true)) {
                continue;
            }
            $this->assertSame(! in_array($key, $old, true), $modules[$key], $key);
        }
    }

    public function test_legacy_toptanci_resolves_to_trade(): void
    {
        $this->assertSame($this->modulesFor(['APP_PROFILE' => 'trade']), $this->modulesFor(['APP_PROFILE' => 'toptanci']));
    }

    public function test_single_mod_line_overrides_profile(): void
    {
        $modules = $this->modulesFor(['APP_PROFILE' => 'trade', 'MOD_QUOTES' => 'true', 'MOD_STOCK' => 'false']);

        $this->assertTrue($modules['quotes']);
        $this->assertFalse($modules['stock']);
    }

    public function test_whatsapp_kinds_per_mode(): void
    {
        $expected = [
            'mimar' => ['sale', 'collection', 'balance_query', 'statement', 'totals_query', 'debt_note', 'party_list', 'help', 'reminder', 'agenda', 'remember', 'forget', 'memory_list'],
            'muteahhit' => ['expense', 'payment', 'sale', 'collection', 'balance_query', 'statement', 'totals_query', 'expense_summary', 'debt_note', 'party_list', 'help', 'reminder', 'agenda', 'remember', 'forget', 'memory_list', 'recurring_expense', 'recurring_list', 'recurring_stop', 'employee_setting', 'salary', 'purchase'],
            'alacak_verecek' => ['expense', 'payment', 'sale', 'collection', 'balance_query', 'statement', 'totals_query', 'expense_summary', 'debt_note', 'party_list', 'help', 'reminder', 'agenda', 'remember', 'forget', 'memory_list', 'recurring_expense', 'recurring_list', 'recurring_stop', 'employee_setting', 'salary', 'purchase'],
        ];

        foreach ($expected as $profile => $kinds) {
            config(['modules' => $this->modulesFor(['APP_PROFILE' => $profile])]);
            $this->assertSame($kinds, ExpenseExtractor::allowedKinds(), $profile);
        }
    }
}
