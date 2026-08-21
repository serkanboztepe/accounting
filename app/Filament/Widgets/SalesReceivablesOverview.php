<?php

namespace App\Filament\Widgets;

use App\Models\Contract;
use Filament\Widgets\Widget;

class SalesReceivablesOverview extends Widget
{
    protected string $view = 'filament.widgets.sales-receivables-overview';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    /**
     * Kart yalnız satış (alacak) sözleşmesi varken görünür.
     */
    public static function canView(): bool
    {
        return Contract::withoutGlobalScope('purchase')
            ->where('direction', Contract::DIRECTION_SALE)
            ->whereIn('status', ['active', 'completed'])
            ->exists();
    }

    public function getData(): array
    {
        $sales = Contract::withoutGlobalScope('purchase')
            ->where('direction', Contract::DIRECTION_SALE)
            ->whereIn('status', ['active', 'completed'])
            ->with('items')
            ->get();

        $total     = (float) $sales->sum(fn (Contract $c) => $c->reportableTotal());
        $collected = (float) $sales->sum(fn (Contract $c) => $c->cashPaidAmount());
        $pending   = (float) $sales->sum(fn (Contract $c) => $c->pendingCheckAmount());
        $remaining = (float) $sales->sum(
            fn (Contract $c) => max(0, $c->reportableTotal() - $c->cashPaidAmount()),
        );

        return [
            'count'          => $sales->count(),
            'sales_total'    => $total,
            'collected'      => $collected,
            'pending_checks' => $pending,
            'remaining'      => $remaining,
        ];
    }
}
