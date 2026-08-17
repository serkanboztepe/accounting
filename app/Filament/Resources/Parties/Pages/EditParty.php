<?php

namespace App\Filament\Resources\Parties\Pages;

use App\Filament\Resources\Parties\PartyResource;
use App\Models\Check;
use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\ContractPayment;
use App\Models\Expense;
use App\Models\Invoice;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditParty extends EditRecord
{
    protected static string $resource = PartyResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    public function getFooter(): ?\Illuminate\Contracts\View\View
    {
        if (! $this->record) {
            return null;
        }

        return view('filament.resources.parties.edit-footer', [
            'party'     => $this->record,
            'summary'   => $this->getPartySummary(),
            'timeline'  => $this->getTimelineEvents(),
            'contracts' => $this->getContractRows(),
            'invoices'  => $this->getInvoiceRows(),
            'expenses'  => $this->getExpenseRows(),
            'checks'    => $this->getCheckRows(),
        ]);
    }

    private function getTimelineEvents(): array
    {
        $party = $this->record;
        $events = [];

        foreach (Contract::query()->where('party_id', $party->id)->with(['project', 'items'])->get() as $contract) {
            $date = $contract->contract_date ?? $contract->created_at;
            $events[] = [
                'sort'     => $date,
                'date'     => optional($date)->format('d.m.Y') ?? '-',
                'type'     => 'contract',
                'label'    => 'Sözleşme',
                'color'    => 'gray',
                'title'    => $contract->title,
                'subtitle' => $contract->project?->name,
                'amount'   => $contract->reportableTotal(),
            ];
        }

        foreach (ContractDelivery::query()
            ->whereHas('contract', fn ($q) => $q->where('party_id', $party->id))
            ->with(['contract', 'project'])
            ->get() as $delivery) {
            $isSub = $delivery->contract?->isSubcontract();
            $events[] = [
                'sort'     => $delivery->delivery_date,
                'date'     => optional($delivery->delivery_date)->format('d.m.Y') ?? '-',
                'type'     => $isSub ? 'progress' : 'delivery',
                'label'    => $isSub ? 'Hakediş' : 'Teslimat',
                'color'    => 'info',
                'title'    => $delivery->contract?->title,
                'subtitle' => $delivery->project?->name,
                'amount'   => (float) $delivery->amount,
            ];
        }

        foreach (Invoice::query()
            ->whereHas('contract', fn ($q) => $q->where('party_id', $party->id))
            ->with('contract')
            ->get() as $invoice) {
            $events[] = [
                'sort'     => $invoice->invoice_date,
                'date'     => optional($invoice->invoice_date)->format('d.m.Y') ?? '-',
                'type'     => 'invoice',
                'label'    => 'Fatura',
                'color'    => 'warning',
                'title'    => $invoice->invoice_number ? 'No: ' . $invoice->invoice_number : 'Fatura',
                'subtitle' => $invoice->contract?->title,
                'amount'   => (float) $invoice->total_amount,
            ];
        }

        foreach (ContractPayment::query()
            ->whereHas('contract', fn ($q) => $q->where('party_id', $party->id))
            ->with('contract')
            ->get() as $payment) {
            $date = $payment->payment_date ?? $payment->created_at;
            $events[] = [
                'sort'     => $date,
                'date'     => optional($date)->format('d.m.Y') ?? '-',
                'type'     => 'payment',
                'label'    => 'Ödeme',
                'color'    => 'success',
                'title'    => $payment->description ?: 'Sözleşme ödemesi',
                'subtitle' => $payment->contract?->title,
                'amount'   => (float) $payment->amount,
            ];
        }

        foreach (Check::query()->where('party_id', $party->id)->get() as $check) {
            $date = $check->issue_date ?? $check->due_date;
            $events[] = [
                'sort'     => $date,
                'date'     => optional($date)->format('d.m.Y') ?? '-',
                'type'     => 'check',
                'label'    => 'Çek',
                'color'    => 'amber',
                'title'    => $check->check_number ? 'Çek No: ' . $check->check_number : 'Çek',
                'subtitle' => 'Vade: ' . (optional($check->due_date)->format('d.m.Y') ?? '-'),
                'amount'   => (float) $check->amount,
            ];
        }

        foreach (Expense::query()->where('party_id', $party->id)->with(['project', 'category'])->get() as $expense) {
            $events[] = [
                'sort'     => $expense->expense_date,
                'date'     => optional($expense->expense_date)->format('d.m.Y') ?? '-',
                'type'     => 'expense',
                'label'    => 'Direkt Gider',
                'color'    => 'rose',
                'title'    => $expense->description ?: ($expense->category?->name ?? 'Gider'),
                'subtitle' => $expense->project?->name,
                'amount'   => (float) $expense->amount,
            ];
        }

        usort($events, function ($a, $b) {
            $ax = $a['sort']?->timestamp ?? 0;
            $bx = $b['sort']?->timestamp ?? 0;

            return $bx <=> $ax;
        });

        return array_map(function ($event) {
            unset($event['sort']);

            return $event;
        }, $events);
    }

    private function getPartySummary(): array
    {
        $party = $this->record;

        $contractsTotal = (float) Contract::query()
            ->where('party_id', $party->id)
            ->with('items')
            ->get()
            ->sum(fn (Contract $c) => $c->reportableTotal());

        $paidTotal = (float) ContractPayment::query()
            ->whereHas('contract', fn ($q) => $q->where('party_id', $party->id))
            ->sum('amount');

        $expensesTotal = (float) Expense::query()
            ->where('party_id', $party->id)
            ->sum('amount');

        $checksTotal = (float) Check::query()
            ->where('party_id', $party->id)
            ->sum('amount');

        $invoicedTotal = (float) Invoice::query()
            ->whereHas('contract', fn ($q) => $q->where('party_id', $party->id))
            ->sum('total_amount');

        return [
            'contracts_total' => $contractsTotal,
            'paid_total'      => $paidTotal,
            'remaining_total' => max(0, $contractsTotal - $paidTotal),
            'invoiced_total'  => $invoicedTotal,
            'expenses_total'  => $expensesTotal,
            'checks_total'    => $checksTotal,
        ];
    }

    private function getContractRows(): array
    {
        $party = $this->record;

        return Contract::query()
            ->with(['project', 'payments', 'invoices', 'items', 'deliveries.project', 'deliveries.unit', 'deliveries.contractItem.unit'])
            ->where('party_id', $party->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (Contract $contract) {
                $paid     = (float) $contract->payments->sum('amount');
                $invoiced = (float) $contract->invoices->sum('total_amount');
                $total    = $contract->reportableTotal();

                if ($contract->invoices->count() === 0) {
                    $invoiceStatus = 'none';
                } elseif ($invoiced >= $total - 0.01) {
                    $invoiceStatus = 'complete';
                } else {
                    $invoiceStatus = 'partial';
                }

                $deliveryByProject = [];
                foreach ($contract->deliveries as $delivery) {
                    $projectId   = $delivery->project_id ?? 0;
                    $projectName = $delivery->project?->name ?? 'Projesiz';

                    if (! isset($deliveryByProject[$projectId])) {
                        $deliveryByProject[$projectId] = [
                            'project'  => $projectName,
                            'quantity' => 0,
                            'amount'   => 0,
                            'unit'     => $delivery->unit?->code ?? $delivery->contractItem?->unit?->code,
                        ];
                    }

                    $deliveryByProject[$projectId]['quantity'] += (float) $delivery->quantity;
                    $deliveryByProject[$projectId]['amount']   += (float) $delivery->amount;
                }

                return [
                    'title'               => $contract->title,
                    'project'             => $contract->project?->name,
                    'status'              => $contract->status,
                    'total'               => $total,
                    'paid'                => $paid,
                    'remaining'           => max(0, $total - $paid),
                    'invoiced'            => $invoiced,
                    'uninvoiced'          => max(0, $total - $invoiced),
                    'invoice_status'      => $invoiceStatus,
                    'delivery_by_project' => array_values($deliveryByProject),
                    'has_deliveries'      => count($deliveryByProject) > 0,
                ];
            })
            ->all();
    }

    private function getInvoiceRows(): array
    {
        $party = $this->record;

        return Invoice::query()
            ->with('contract')
            ->whereHas('contract', fn ($q) => $q->where('party_id', $party->id))
            ->orderBy('invoice_date', 'desc')
            ->get()
            ->map(fn (Invoice $invoice) => [
                'date'           => optional($invoice->invoice_date)->format('d.m.Y'),
                'invoice_number' => $invoice->invoice_number,
                'contract_title' => $invoice->contract?->title ?? '—',
                'amount'         => (float) $invoice->total_amount,
                'has_pdf'        => filled($invoice->invoice_path),
                'pdf_url'        => filled($invoice->invoice_path)
                    ? route('invoice.file', ['filename' => basename($invoice->invoice_path)])
                    : null,
            ])
            ->all();
    }

    private function getExpenseRows(): array
    {
        $party = $this->record;

        $expenses = Expense::query()
            ->with(['project', 'category'])
            ->where('party_id', $party->id)
            ->orderBy('expense_date')
            ->get();

        $grouped = [];
        foreach ($expenses as $expense) {
            $key  = $expense->project_id ?? 0;
            $name = $expense->project?->name ?? 'Projesiz';

            if (! isset($grouped[$key])) {
                $grouped[$key] = ['project' => $name, 'total' => 0, 'items' => []];
            }

            $grouped[$key]['total']   += (float) $expense->amount;
            $grouped[$key]['items'][]  = [
                'date'        => optional($expense->expense_date)->format('d.m.Y'),
                'category'    => $expense->category?->name,
                'description' => $expense->description,
                'amount'      => (float) $expense->amount,
            ];
        }

        return array_values($grouped);
    }

    private function getCheckRows(): array
    {
        $party = $this->record;

        return Check::query()
            ->with('project')
            ->where('party_id', $party->id)
            ->orderBy('due_date')
            ->get()
            ->map(fn (Check $check) => [
                'project'  => $check->project?->name ?? 'Projesiz',
                'due_date' => optional($check->due_date)->format('d.m.Y'),
                'amount'   => (float) $check->amount,
                'status'   => $check->status,
            ])
            ->all();
    }
}
