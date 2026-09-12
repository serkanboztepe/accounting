<?php

namespace App\Filament\Pages;

use App\Support\StockReporting;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class StockReport extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'Genel';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Stok Raporu';

    protected static ?string $navigationLabel = 'Stok Raporu';

    protected string $view = 'filament.pages.stock-report';

    public function getRows(): array
    {
        return StockReporting::stockRows();
    }
}
