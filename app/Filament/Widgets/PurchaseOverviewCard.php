<?php

namespace App\Filament\Widgets;

use App\Models\Check;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\Project;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Filament\Widgets\Widget;

class PurchaseOverviewCard extends Widget
{
    protected string $view = 'filament.widgets.purchase-overview-card';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 0;

    public function getData(): array
    {
        $today = Carbon::today();

        $activeProjects = Project::query()->where('status', 'active')->count();

        // Satış sözleşmeleri global scope ile zaten hariç — bu kart salt maliyet/alım.
        $contracts = Contract::query()
            ->with(['items'])
            ->whereIn('status', ['active', 'completed'])
            ->get();

        $contractsTotal = (float) $contracts->sum(fn (Contract $c) => $c->reportableTotal());
        $cashPaid       = (float) $contracts->sum(fn (Contract $c) => $c->cashPaidAmount());
        $unpaidBalance  = (float) $contracts->sum(
            fn (Contract $c) => max(0, $c->reportableTotal() - $c->cashPaidAmount()),
        );

        // Nakit-açık ve çek-fazlası SÖZLEŞME BAZINDA hesaplanır, sonra toplanır.
        // (Global netleme yanlıştı: bir sözleşmenin fazla çeki, çek yazılmamış başka
        //  sözleşmenin nakit borcunu yutup "Ödenecek Nakit"i sıfırlıyordu — bkz Düzgün Yıldırım.)
        $cashDue = 0.0;
        $checkExcess = 0.0;
        foreach ($contracts as $c) {
            $remaining = max(0, $c->reportableTotal() - $c->cashPaidAmount());
            $contractChecks = $c->pendingCheckAmount();
            $cashDue     += max(0, $remaining - $contractChecks);   // çeki yazılmamış açık kısım
            $checkExcess += max(0, $contractChecks - $remaining);   // sözleşme borcunu aşan çek
        }

        $expensesTotal = (float) Expense::query()->sum('amount');
        $totalCashOut  = $cashPaid + $expensesTotal;

        $pendingAmount = (float) Check::query()->where('status', 'issued')->sum('amount');
        $overdue       = Check::query()->where('status', 'issued')->whereDate('due_date', '<', $today);
        $overdueAmount = (float) (clone $overdue)->sum('amount');
        $overdueCount  = (clone $overdue)->count();

        return [
            'active_projects' => $activeProjects,
            'contracts_total' => $contractsTotal,
            'cash_paid'       => $cashPaid,
            'unpaid_balance'  => $unpaidBalance,
            'pending_checks'  => $pendingAmount,
            // Ödenecek nakit = sözleşme bazında çeki yazılmamış açık kısımların toplamı
            'cash_due'        => $cashDue,
            // Toplam ödenecek = nakit-açık + bekleyen çek → denklem her zaman tutar
            'total_due'       => $cashDue + $pendingAmount,
            // Sözleşme borcunu aşan çeklerin toplamı (sözleşme tutarı eksik girilmiş sinyali)
            'check_excess'    => $checkExcess,
            'overdue_amount'  => $overdueAmount,
            'overdue_count'   => $overdueCount,
            'pending_note'    => $overdueCount > 0
                ? ' · ₺' . Money::format($overdueAmount) . ' vadesi geçmiş (' . $overdueCount . ')'
                : '',
            'expenses_total'  => $expensesTotal,
            'total_cash_out'  => $totalCashOut,
        ];
    }
}
