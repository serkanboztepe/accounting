<?php

namespace Tests\Feature\Trade;

use App\Filament\Pages\SalesSummary;
use App\Filament\Pages\StockReport;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Models\StockMovement;
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

    public function test_trade_stock_movements_are_read_only_under_products(): void
    {
        $this->useProfile('trade');
        $row = new StockMovement();

        $this->assertTrue(StockMovementResource::canAccess());
        $this->assertFalse(StockMovementResource::canCreate());
        $this->assertFalse(StockMovementResource::canEdit($row));
        $this->assertFalse(StockMovementResource::canDelete($row));
        $this->assertSame('Alış-Satış', StockMovementResource::getNavigationGroup());
        $this->assertSame('Ürünler', StockMovementResource::getNavigationParentItem());
    }

    public function test_stock_without_trade_package_keeps_manual_movements(): void
    {
        // Stok açık ama Alışlar ekranı kapalı firma: Mal Girişi / Düzeltme eskisi gibi.
        $this->useProfile('alacak_verecek', ['stock' => true, 'direct_sales' => true]);
        $row = new StockMovement();

        $this->assertTrue(StockMovementResource::canCreate());
        $this->assertTrue(StockMovementResource::canEdit($row));
        $this->assertSame('Ürün & Stok', StockMovementResource::getNavigationGroup());
        $this->assertNull(StockMovementResource::getNavigationParentItem());
    }
}
