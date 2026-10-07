<?php

namespace Tests\Feature;

use App\Services\Whatsapp\ExpenseExtractor;
use Tests\TestCase;

/**
 * APP_PROFILE (mimar / muteahhit / toptanci) — config/modules.php'nin ürettiği değerler,
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

    /** Listelenen anahtarlar kapalı, gerisi açık olmalı. */
    private function assertOnlyDisabled(array $disabled, array $modules): void
    {
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

    public function test_muteahhit_matches_muhasebe(): void
    {
        $this->assertOnlyDisabled([
            'land_share', 'property_tax', 'direct_sales', 'stock', 'sales_contracts', 'quotes',
            'report_stock', 'report_sales',
        ], $this->modulesFor(['APP_PROFILE' => 'muteahhit']));
    }

    public function test_toptanci_matches_yenyapi(): void
    {
        $this->assertOnlyDisabled([
            'land_share', 'property_tax', 'purchase_contracts', 'cari_supplier',
        ], $this->modulesFor(['APP_PROFILE' => 'toptanci']));
    }

    public function test_single_mod_line_overrides_profile(): void
    {
        $modules = $this->modulesFor(['APP_PROFILE' => 'toptanci', 'MOD_QUOTES' => 'false', 'MOD_STOCK' => 'true']);

        $this->assertFalse($modules['quotes']);
        $this->assertTrue($modules['stock']);
    }

    public function test_whatsapp_kinds_per_mode(): void
    {
        $expected = [
            'mimar' => ['sale', 'collection', 'balance_query', 'statement', 'totals_query'],
            'muteahhit' => ['expense', 'payment', 'sale', 'collection', 'balance_query', 'statement', 'totals_query'],
            'toptanci' => ['expense', 'collection', 'balance_query', 'statement', 'totals_query'],
        ];

        foreach ($expected as $profile => $kinds) {
            config(['modules' => $this->modulesFor(['APP_PROFILE' => $profile])]);
            $this->assertSame($kinds, ExpenseExtractor::allowedKinds(), $profile);
        }
    }
}
