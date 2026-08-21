<?php

namespace App\Filament\Widgets;

use App\Models\Contract;
use App\Models\ContractPayment;
use Illuminate\Support\Carbon;
use Filament\Widgets\Widget;

class UpcomingCollectionsTable extends Widget
{
    protected string $view = 'filament.widgets.upcoming-collections-table';

    protected int|string|array $columnSpan = 1;

    /**
     * Satış sözleşmelerinin ödeme planından (JSON) bugün ve sonrası tarihli
     * satırları düzleştirir. Plan = beklenen tahsilat; gerçek tahsilat değil.
     */
    public function getRows(): array
    {
        $today = Carbon::today();

        $sales = Contract::withoutGlobalScope('purchase')
            ->where('direction', Contract::DIRECTION_SALE)
            ->whereIn('status', ['active', 'completed'])
            ->with('party')
            ->get();

        $rows = [];
        foreach ($sales as $contract) {
            foreach ((array) $contract->payment_plan as $line) {
                if (empty($line['date'])) {
                    continue;
                }
                $date = Carbon::parse($line['date']);
                if ($date->lt($today)) {
                    continue;
                }
                $rows[] = [
                    'date'   => $date,
                    'party'  => $contract->party?->name ?? '-',
                    'title'  => $contract->title,
                    'type'   => ContractPayment::PAYMENT_TYPES[$line['payment_type'] ?? ''] ?? ($line['payment_type'] ?? ''),
                    'amount' => \App\Support\Money::parse($line['amount'] ?? 0),
                ];
            }
        }

        usort($rows, fn ($a, $b) => $a['date'] <=> $b['date']);

        return array_slice($rows, 0, 8);
    }
}
