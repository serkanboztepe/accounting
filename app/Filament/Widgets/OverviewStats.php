<?php

namespace App\Filament\Widgets;

use App\Models\Check;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\Project;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class OverviewStats extends StatsOverviewWidget
{
    protected ?string $heading = 'Genel Durum';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $today = Carbon::today();

        $activeProjects = Project::query()
            ->where('status', 'active')
            ->count();

        $contracts = Contract::query()
            ->with(['items'])
            ->whereIn('status', ['active', 'completed'])
            ->get();

        // Tüm dashboard rapor sayfasıyla aynı modeli kullanır:
        //   Gerçek Ödenen = nakit/EFT + tahsil edilmiş çek (paid/collected)
        //   Bekleyen çek  = verilmiş ama tahsil edilmemiş (issued) — ayrı gösterilir
        //   Kalan bakiye  = max(0, sözleşme tutarı − gerçek ödenen); bekleyen çek Kalan'ın içindedir
        $contractsTotal = (float) $contracts->sum(fn (Contract $c) => $c->reportableTotal());
        $cashPaid       = (float) $contracts->sum(fn (Contract $c) => $c->cashPaidAmount());
        $unpaidBalance  = (float) $contracts->sum(
            fn (Contract $c) => max(0, $c->reportableTotal() - $c->cashPaidAmount()),
        );

        $expensesTotal = (float) Expense::query()->sum('amount');

        // Toplam çıkış = fiilen cepten çıkan para (gerçek ödenen sözleşme + direkt gider).
        // Bekleyen çekler henüz tahsil edilmediği için buraya girmez.
        $totalCashOut = $cashPaid + $expensesTotal;

        // Bekleyen çek kartı: tüm 'issued' çekler (verilmiş, tahsil bekliyor).
        $pendingChecks = Check::query()->where('status', 'issued');
        $pendingAmount = (float) (clone $pendingChecks)->sum('amount');
        $pendingCount  = (clone $pendingChecks)->count();

        $overdueChecks = Check::query()
            ->where('status', 'issued')
            ->whereDate('due_date', '<', $today);
        $overdueAmount = (float) (clone $overdueChecks)->sum('amount');
        $overdueCount  = (clone $overdueChecks)->count();

        $pendingDescription = $pendingCount . ' adet · tahsil bekliyor';
        if ($overdueCount > 0) {
            $pendingDescription .= ' · ' . Money::format($overdueAmount) . ' ₺ vadesi geçmiş (' . $overdueCount . ' adet)';
        }

        return [
            Stat::make('Aktif Projeler', (string) $activeProjects)
                ->description('Durumu aktif olan projeler')
                ->color('primary'),

            Stat::make('Toplam Sözleşme', Money::format($contractsTotal) . ' ₺')
                ->description('Aktif + tamamlanmış sözleşmeler')
                ->color('info'),

            Stat::make('Gerçek Ödenen', Money::format($cashPaid) . ' ₺')
                ->description('Nakit/EFT + tahsil edilmiş çek')
                ->color('success'),

            Stat::make('Bekleyen Çek', Money::format($pendingAmount) . ' ₺')
                ->description($pendingDescription)
                ->color($overdueCount > 0 ? 'danger' : 'warning'),

            Stat::make('Ödenmemiş Sözleşme Bakiyesi', Money::format($unpaidBalance) . ' ₺')
                ->description('Sözleşme tutarı − gerçek ödenen (bekleyen çek dahil)')
                ->color('warning'),

            Stat::make('Toplam Çıkış', Money::format($totalCashOut) . ' ₺')
                ->description('Gerçek ödenen sözleşme + ' . Money::format($expensesTotal) . ' ₺ direkt gider')
                ->color('info'),
        ];
    }
}
