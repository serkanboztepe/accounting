<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\MonthlyCheckPaymentsChart;
use App\Filament\Widgets\OverdueChecksTable;
use App\Filament\Widgets\OverviewStats;
use App\Filament\Widgets\ProjectComparisonTable;
use App\Filament\Widgets\TopSubcontractorsTable;
use App\Filament\Widgets\TopSuppliersTable;
use App\Filament\Widgets\UnpaidContractBalancesTable;
use App\Filament\Widgets\UpcomingChecksTable;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function getTitle(): string
    {
        return 'Genel Rapor';
    }

    public function getHeaderWidgets(): array
    {
        return [
            OverviewStats::class,
            UnpaidContractBalancesTable::class,
            MonthlyCheckPaymentsChart::class,
            ProjectComparisonTable::class,
            TopSuppliersTable::class,
            TopSubcontractorsTable::class,
            UpcomingChecksTable::class,
            OverdueChecksTable::class,
        ];
    }

    public function getWidgets(): array
    {
        return [];
    }
}
