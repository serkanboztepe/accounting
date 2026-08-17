<?php

namespace App\Filament\Resources\Contracts\Widgets;

use App\Models\Contract;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ContractSummary extends StatsOverviewWidget
{
    public ?Contract $record = null;

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $contract = $this->record;

        if (! $contract) {
            return [];
        }

        $isSub = $contract->isSubcontract();

        $total     = $contract->reportableTotal();
        $delivered = $contract->deliveredAmount();
        $paid      = $contract->paidAmount();

        $remainingDelivery = max(0, $total - $delivered);
        $remainingPayment  = max(0, $total - $paid);

        return [
            Stat::make('Sözleşme Tutarı', Money::format($total) . ' ₺')
                ->description((float) $contract->total_amount > 0 ? 'Manuel götürü bedel' : 'Kalemlerden hesaplandı')
                ->color('info'),

            Stat::make($isSub ? 'Hakediş Tutarı' : 'Teslimat Tutarı', Money::format($delivered) . ' ₺')
                ->description('Kalan (teslimat): ' . Money::format($remainingDelivery) . ' ₺')
                ->color('success'),

            Stat::make('Ödenen', Money::format($paid) . ' ₺')
                ->description('Kalan (ödeme): ' . Money::format($remainingPayment) . ' ₺')
                ->color($remainingPayment > 0 ? 'warning' : 'success'),
        ];
    }
}
