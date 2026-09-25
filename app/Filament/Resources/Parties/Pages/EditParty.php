<?php

namespace App\Filament\Resources\Parties\Pages;

use App\Filament\Resources\Parties\PartyResource;
use App\Models\Check;
use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\ContractPayment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Support\PartyStatement;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\On;

class EditParty extends EditRecord
{
    protected static string $resource = PartyResource::class;

    /**
     * Cari ledger'a satış/tahsilat/ödeme eklenince (relation manager'dan gelen event),
     * bu boş metot sayfayı yeniden render eder → getFooter() tekrar çalışır → Cari Ekstresi canlı güncellenir.
     */
    #[On('ledgerUpdated')]
    public function refreshLedgerFooter(): void
    {
    }

    public ?string $statementDateFrom = null;

    public ?string $statementDateTo = null;

    public ?string $statementProjectId = null;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Filtreli ekstre yazdırma URL'i (footer'daki butona verilir).
     */
    public function statementPrintUrl(): string
    {
        return route('party.statement.print', array_merge(
            ['party' => $this->record],
            $this->statementFilters(),
        ));
    }

    /**
     * Ekstre filtreleri (boş olanlar elenir) — hem getFooter hem yazdır URL'i kullanır.
     */
    private function statementFilters(): array
    {
        return array_filter([
            'date_from'  => $this->statementDateFrom,
            'date_to'    => $this->statementDateTo,
            'project_id' => $this->statementProjectId,
        ], fn ($v) => filled($v));
    }

    public function clearStatementFilters(): void
    {
        $this->statementDateFrom = null;
        $this->statementDateTo = null;
        $this->statementProjectId = null;
    }

    public function getFooter(): ?\Illuminate\Contracts\View\View
    {
        if (! $this->record) {
            return null;
        }

        return view('filament.resources.parties.edit-footer', [
            'party'            => $this->record,
            'statement'        => PartyStatement::build($this->record, $this->statementFilters()),
            'statementProjects' => $this->getStatementProjects(),
            'statementDateFrom' => $this->statementDateFrom,
            'statementDateTo'   => $this->statementDateTo,
            'statementProjectId' => $this->statementProjectId,
            'timeline'         => $this->getTimelineEvents(),
            'contracts'        => $this->getContractRows(),
            'invoices'         => $this->getInvoiceRows(),
            'expenses'         => $this->getExpenseRows(),
            'checks'           => $this->getCheckRows(),
        ]);
    }

    /**
     * Bu carinin ekstresinde geçen projeler (sözleşme + gider + elle hareket) → [id => ad].
     */
    private function getStatementProjects(): array
    {
        $party = $this->record;

        $ids = collect()
            ->merge(Contract::withoutGlobalScope('purchase')->where('party_id', $party->id)->pluck('project_id'))
            ->merge(Expense::where('party_id', $party->id)->pluck('project_id'))
            ->merge(PartyLedgerEntry::where('party_id', $party->id)->pluck('project_id'))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Project::whereIn('id', $ids)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
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
