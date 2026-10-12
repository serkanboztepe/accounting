<?php

namespace App\Filament\Pages;

use App\Support\MonthlyResult;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Ay Sonucu (yaklaşık): ciro − alış − gider, son 12 ay. "Ay Sonucu raporu" modülüyle açılır. */
class MonthlyResultReport extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|\UnitEnum|null $navigationGroup = 'Raporlar';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Ay Sonucu';

    protected static ?string $navigationLabel = 'Ay Sonucu';

    protected static ?string $slug = 'ay-sonucu';

    protected string $view = 'filament.pages.monthly-result';

    public static function canAccess(): bool
    {
        return (bool) config('modules.report_monthly');
    }

    public function getMonths(): array
    {
        return MonthlyResult::lastMonths(12);
    }
}
