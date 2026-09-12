<?php

namespace App\Filament\Resources\Contracts\Widgets;

use App\Models\Contract;
use Filament\Widgets\Widget;

class ContractSummary extends Widget
{
    protected string $view = 'filament.widgets.contract-summary-card';

    protected int|string|array $columnSpan = 'full';

    public ?Contract $record = null;

    public function getData(): array
    {
        $contract = $this->record;

        if (! $contract) {
            return [];
        }

        $isSub = $contract->isSubcontract();

        $total     = $contract->reportableTotal();
        $delivered = $contract->deliveredAmount();
        $paid      = $contract->paidAmount();

        // Teslimat sözleşmeyi aşarsa aşımı gizleme
        $deliveryDiff = $delivered - $total;      // + aşım, − kalan

        // İki farklı borç:
        //  1) Teslim alınana göre: gelen mala karşılık şu an gerçekten borçlu olunan
        //  2) Sözleşmeye göre: sözleşme bitene kadar toplam kalan taahhüt
        $dueForDelivered   = $delivered - $paid;  // + borç, − fazla ödeme
        $contractRemaining = $total - $paid;      // + kalan, − fazla ödeme

        return [
            'is_sub'              => $isSub,
            'type_label'          => Contract::TYPES[$contract->contract_type] ?? '',
            'party_name'          => $contract->party?->name,
            'is_manual_total'     => (float) $contract->total_amount > 0,
            'total'               => $total,
            'delivered'           => $delivered,
            'paid'                => $paid,
            'delivery_diff'       => $deliveryDiff,
            'due_for_delivered'   => $dueForDelivered,
            'contract_remaining'  => $contractRemaining,
        ];
    }
}
