<?php

namespace App\Filament\Pages;

use App\Support\StockReporting;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class SalesSummary extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Raporlar';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Satış Özeti';

    protected static ?string $navigationLabel = 'Satış Özeti';

    protected string $view = 'filament.pages.sales-summary';

    /** 'party' | 'project' */
    public string $groupBy = 'party';

    public static function canAccess(): bool
    {
        return config('modules.direct_sales') && config('modules.report_sales');
    }

    public function getRows(): array
    {
        return StockReporting::salesSummary($this->groupBy);
    }
}
