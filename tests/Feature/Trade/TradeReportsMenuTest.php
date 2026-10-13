<?php

namespace Tests\Feature\Trade;

use App\Filament\Pages\SalesSummary;
use App\Filament\Pages\StockReport;
use App\Support\ModuleProfiles;
use Tests\TestCase;

/** Rapor menüsü: Stoklu satışta Stok Raporu menüde, Satış Özeti yok (Ay Sonucu ile çelişmesin); eski paketler aynen. */
class TradeReportsMenuTest extends TestCase
{
    private function useProfile(string $profile, array $overrides = []): void
    {
        config(['modules' => ModuleProfiles::resolve($profile, $overrides)]);
    }

    public function test_trade_shows_stock_report_in_menu_and_hides_sales_summary(): void
    {
        $this->useProfile('trade');

        $this->assertTrue(StockReport::canAccess());
        $this->assertTrue(StockReport::shouldRegisterNavigation());
        $this->assertFalse(SalesSummary::canAccess());
    }

    public function test_project_reports_firms_keep_tabs_not_menu(): void
    {
        // Proje Raporları açık + stok açık: eskisi gibi sekmeden açılır, menüde ayrıca yok.
        $this->useProfile('muteahhit', ['stock' => true, 'report_stock' => true, 'direct_sales' => true, 'report_sales' => true]);

        $this->assertTrue(StockReport::canAccess());
        $this->assertFalse(StockReport::shouldRegisterNavigation());
        $this->assertTrue(SalesSummary::canAccess());
    }

    public function test_old_profiles_unchanged(): void
    {
        foreach (['mimar', 'muteahhit', 'alacak_verecek'] as $profile) {
            $this->useProfile($profile);
            $this->assertFalse(StockReport::shouldRegisterNavigation(), $profile);
            $this->assertFalse(SalesSummary::canAccess(), $profile);
        }
    }
}
