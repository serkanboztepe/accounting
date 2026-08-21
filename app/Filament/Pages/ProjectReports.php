<?php

namespace App\Filament\Pages;

use App\Models\Check;
use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\ContractPayment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class ProjectReports extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Proje Raporları';

    protected static ?string $navigationLabel = 'Proje Raporları';

    protected string $view = 'filament.pages.project-reports';

    public ?int $projectId = null;

    public array $data = [];

    public function mount(): void
    {
        $this->projectId = Project::query()->orderBy('name')->value('id');
        $this->form->fill([
            'projectId' => $this->projectId,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('projectId')
                    ->label('Proje')
                    ->options(Project::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function ($state) {
                        $this->projectId = $state ? (int) $state : null;
                    })
                    ->required(),
            ])
            ->statePath('data');
    }

    public function getSelectedProject(): ?Project
    {
        if (! $this->projectId && isset($this->data['projectId'])) {
            $this->projectId = (int) $this->data['projectId'];
        }

        return $this->projectId ? Project::find($this->projectId) : null;
    }

    public function getSummaryStats(): array
    {
        $project = $this->getSelectedProject();

        if (! $project) {
            return [
                'direct_expenses' => 0,
                'contracts_total' => 0,
                'paid_payments' => 0,
                'pending_checks' => 0,
                'remaining_contract_balance' => 0,
                'checks_total' => 0,
            ];
        }

        $directExpenses = (float) Expense::query()
            ->where('project_id', $project->id)
            ->sum('amount');

        // Satış (alacak) sözleşmeleri Contract global scope'u tarafından zaten dışlanır.
        $contracts = Contract::query()
            ->where('project_id', $project->id)
            ->with('items')
            ->get();

        $contractsTotal = (float) $contracts->sum(fn (Contract $c) => $c->reportableTotal());

        // Gerçek ödenen = nakit/EFT + tahsil edilmiş çek (bekleyen çek dahil DEĞİL)
        $paidPayments  = (float) $contracts->sum(fn (Contract $c) => $c->cashPaidAmount());
        $pendingChecks = (float) $contracts->sum(fn (Contract $c) => $c->pendingCheckAmount());

        // İptal ve karşılıksız çekler gerçek borç değil — toplama katma.
        $checksTotal = (float) Check::query()
            ->where('project_id', $project->id)
            ->whereNotIn('status', ['cancelled', 'bounced'])
            ->sum('amount');

        $deliveryCost = (float) ContractDelivery::query()
            ->where('project_id', $project->id)
            ->sum('amount');

        // Teslimat bazlı faturalanan: delivery allocation'lardan gelen tutar
        $invoicedViaDeliveries = (float) DB::table('invoice_deliveries')
            ->join('contract_deliveries', 'contract_deliveries.id', '=', 'invoice_deliveries.delivery_id')
            ->where('contract_deliveries.project_id', $project->id)
            ->sum('invoice_deliveries.amount');

        // Teslimat bağlantısı olmayan eski faturalar: sözleşme üzerinden proje eşleşmesi
        $invoicedViaContract = (float) Invoice::query()
            ->whereDoesntHave('deliveryAllocations')
            ->whereHas('contract', fn ($q) => $q->where('project_id', $project->id))
            ->sum('total_amount');

        $invoicedTotal   = $invoicedViaDeliveries + $invoicedViaContract;
        $uninvoicedTotal = max(0, $deliveryCost - $invoicedTotal);

        // Kalan bakiye sözleşme-bazında kırpılır (fazla ödeme bir sözleşmenin
        // borcunu eksiye düşürüp başka sözleşmenin borcunu gizlemesin).
        // Aynı hesap sözleşme satırlarında da kullanılır → rakamlar birebir eşleşir.
        $remainingBalance = (float) $contracts->sum(
            fn (Contract $c) => max(0, $c->reportableTotal() - $c->cashPaidAmount()),
        );

        return [
            'direct_expenses' => $directExpenses,
            'contracts_total' => $contractsTotal,
            'paid_payments' => $paidPayments,
            'pending_checks' => $pendingChecks,
            'remaining_contract_balance' => $remainingBalance,
            'checks_total' => $checksTotal,
            'delivery_cost' => $deliveryCost,
            'total_cost' => $directExpenses + $deliveryCost,
            'invoiced_total' => $invoicedTotal,
            'uninvoiced_total' => $uninvoicedTotal,
        ];
    }

    /**
     * Satış / Alacak özeti — SADECE projenin satış sözleşmesi varsa döner (yoksa null).
     * Maliyet tarafıyla asla toplanmaz; ayrı gösterilir. Brüt = Satış − Toplam Maliyet.
     */
    public function getSalesStats(): ?array
    {
        $project = $this->getSelectedProject();

        if (! $project) {
            return null;
        }

        $sales = Contract::withoutGlobalScope('purchase')
            ->where('project_id', $project->id)
            ->where('direction', Contract::DIRECTION_SALE)
            ->with('items')
            ->get();

        if ($sales->isEmpty()) {
            return null;
        }

        $salesTotal = (float) $sales->sum(fn (Contract $c) => $c->reportableTotal());
        // Tahsil edilen = nakit/EFT + tahsil edilmiş çek (maliyet tarafıyla aynı semantik).
        $collected  = (float) $sales->sum(fn (Contract $c) => $c->cashPaidAmount());
        $pending    = (float) $sales->sum(fn (Contract $c) => $c->pendingCheckAmount());
        $remaining  = (float) $sales->sum(fn (Contract $c) => max(0, $c->reportableTotal() - $c->cashPaidAmount()));

        $cost = (float) ($this->getSummaryStats()['total_cost'] ?? 0);

        return [
            'sales_total'    => $salesTotal,
            'collected'      => $collected,
            'pending_checks' => $pending,
            'remaining'      => $remaining,
            'cost'           => $cost,
            'gross'          => $salesTotal - $cost,
        ];
    }

    public function getExpenseRows(): array
    {
        $project = $this->getSelectedProject();

        if (! $project) {
            return [];
        }

        $expenses = Expense::query()
            ->with('category', 'party')
            ->where('project_id', $project->id)
            ->orderBy('expense_date')
            ->get();

        $grouped = [];
        foreach ($expenses as $expense) {
            $key = $expense->expense_category_id ?? 0;
            $categoryName = $expense->category?->name ?? 'Kategorisiz';

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'category' => $categoryName,
                    'total'    => 0,
                    'items'    => [],
                ];
            }

            $grouped[$key]['total'] += (float) $expense->amount;
            $grouped[$key]['items'][] = [
                'date'        => optional($expense->expense_date)->format('d.m.Y'),
                'description' => $expense->description,
                'party'       => $expense->party?->name,
                'amount'      => (float) $expense->amount,
            ];
        }

        return array_values($grouped);
    }

    public function getContractRows(): array
    {
        $project = $this->getSelectedProject();

        if (! $project) {
            return [];
        }

        return Contract::query()
            ->with(['party', 'payments', 'deliveries', 'invoices.deliveryAllocations', 'items.unit'])
            ->where('project_id', $project->id)
            ->get()
            ->map(function (Contract $contract) {
                $total = $contract->reportableTotal();

                // Ödenen = nakit/EFT + tahsil edilmiş çek (özet kartla aynı semantik).
                // Bekleyen (verilmiş ama tahsil edilmemiş) çek ayrı gösterilir.
                $paid    = $contract->cashPaidAmount();
                $pending = $contract->pendingCheckAmount();

                // Faturalanan = teslimat tahsisi bazlı; tahsis yoksa (eski fatura)
                // faturanın tamamı. Özetteki hesapla birebir aynı mantık.
                $invoiced = (float) $contract->invoices->sum(
                    fn ($inv) => $inv->deliveryAllocations->isEmpty()
                        ? (float) $inv->total_amount
                        : (float) $inv->deliveryAllocations->sum('amount'),
                );

                // Faturasız = Teslimat maliyeti − faturalanan (CLAUDE.md tanımı),
                // sözleşme toplamı değil.
                $delivered  = (float) $contract->deliveries->sum('amount');
                $uninvoiced = max(0, $delivered - $invoiced);

                $invoiceStatus = match(true) {
                    $contract->invoices->isEmpty() => 'none',
                    $uninvoiced <= 0.01            => 'complete',
                    default                        => 'partial',
                };

                return [
                    'title'          => $contract->title,
                    'party'          => $contract->party?->name,
                    'status'         => $contract->status,
                    'total'          => $total,
                    'paid'           => $paid,
                    'pending'        => $pending,
                    'remaining'      => max(0, $total - $paid),
                    'invoiced'       => $invoiced,
                    'delivered'      => $delivered,
                    'uninvoiced'     => $uninvoiced,
                    'invoice_status' => $invoiceStatus,
                    'item_count'     => $contract->items->count(),
                ];
            })
            ->all();
    }

    public function getCheckRows(): array
    {
        $project = $this->getSelectedProject();

        if (! $project) {
            return [];
        }

        return Check::query()
            ->with('party')
            ->where('project_id', $project->id)
            ->orderBy('due_date')
            ->get()
            ->map(fn (Check $check) => [
                'party' => $check->party?->name,
                'check_number' => $check->check_number,
                'bank_name' => $check->bank_name,
                'due_date' => optional($check->due_date)->format('d.m.Y'),
                'amount' => (float) $check->amount,
                'status' => $check->status,
            ])
            ->all();
    }

    public function getDeliveryRows(): array
    {
        $project = $this->getSelectedProject();

        if (! $project) {
            return [];
        }

        $deliveries = ContractDelivery::query()
            ->with(['contract.party', 'unit', 'contractItem.unit'])
            ->where('project_id', $project->id)
            ->orderBy('delivery_date')
            ->get();

        $unitCode = fn (ContractDelivery $d): ?string =>
            $d->unit?->code ?? $d->contractItem?->unit?->code;

        $grouped = [];
        foreach ($deliveries as $delivery) {
            $contractId = $delivery->contract_id ?? 0;

            if (! isset($grouped[$contractId])) {
                $grouped[$contractId] = [
                    'contract_title' => $delivery->contract?->title ?? 'Sözleşmesiz',
                    'party'          => $delivery->contract?->party?->name,
                    'total_amount'   => 0,
                    'total_quantity' => 0,
                    'unit'           => $unitCode($delivery),
                    'items'          => [],
                ];
            }

            $grouped[$contractId]['total_amount']   += (float) $delivery->amount;
            $grouped[$contractId]['total_quantity']  += (float) $delivery->quantity;
            $grouped[$contractId]['items'][] = [
                'delivery_date' => optional($delivery->delivery_date)->format('d.m.Y'),
                'quantity'      => (float) $delivery->quantity,
                'unit'          => $unitCode($delivery),
                'unit_price'    => (float) $delivery->unit_price,
                'amount'        => (float) $delivery->amount,
            ];
        }

        foreach ($grouped as &$g) {
            $desc = '₺' . Money::format($g['total_amount']);
            if ($g['total_quantity'] > 0) {
                $desc .= ' · ' . Money::format($g['total_quantity']) . ' ' . $g['unit'];
            }
            $desc .= ' · ' . count($g['items']) . ' teslimat';
            if ($g['party']) {
                $desc .= ' · ' . $g['party'];
            }
            $g['description'] = $desc;
        }

        return array_values($grouped);
    }
}
