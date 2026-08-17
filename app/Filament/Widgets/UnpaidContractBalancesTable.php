<?php

namespace App\Filament\Widgets;

use App\Models\Contract;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class UnpaidContractBalancesTable extends TableWidget
{
    protected static ?string $heading = 'Ödenmemiş Sözleşme Bakiyeleri';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        // Rapor sayfasıyla aynı model:
        //   Sözleşme tutarı = manuel götürü (total_amount) > 0 ise o, değilse kalemler toplamı.
        //   Gerçek ödenen   = nakit/EFT ödemeler + tahsil edilmiş çek (paid/collected).
        //   Bekleyen çek    = verilmiş ama tahsil edilmemiş çek (issued) — Kalan'ın içinde.
        //   Kalan           = tutar − gerçek ödenen. (Çek yazılması Kalan'ı düşürmez.)
        $totalExpr = "COALESCE(NULLIF(contracts.total_amount, 0), (select coalesce(sum(amount), 0) from contract_items where contract_items.contract_id = contracts.id))";

        $cashPaidExpr = "("
            . "(select coalesce(sum(cp.amount), 0) from contract_payments cp where cp.contract_id = contracts.id and cp.payment_type <> 'check')"
            . " + (select coalesce(sum(ck.amount), 0) from checks ck join contract_payments cp2 on cp2.id = ck.contract_payment_id where cp2.contract_id = contracts.id and ck.status in ('paid', 'collected'))"
            . ")";

        $pendingExpr = "(select coalesce(sum(ck.amount), 0) from checks ck join contract_payments cp2 on cp2.id = ck.contract_payment_id where cp2.contract_id = contracts.id and ck.status = 'issued')";

        $remainingExpr = "($totalExpr) - ($cashPaidExpr)";

        return $table
            ->query(
                Contract::query()
                    ->with(['party', 'project'])
                    ->whereIn('status', ['active', 'completed'])
                    ->select('contracts.*')
                    ->selectRaw("$totalExpr as computed_total")
                    ->selectRaw("$cashPaidExpr as computed_paid")
                    ->selectRaw("$pendingExpr as computed_pending")
                    ->selectRaw("$remainingExpr as computed_remaining")
                    ->whereRaw("$remainingExpr > 0")
                    ->orderByRaw('computed_remaining desc')
            )
            ->columns([
                Tables\Columns\TextColumn::make('party.name')
                    ->label('Cari')
                    ->weight('medium')
                    ->searchable(),
                Tables\Columns\TextColumn::make('title')
                    ->label('Sözleşme')
                    ->limit(30)
                    ->tooltip(fn ($state) => $state)
                    ->searchable(),
                Tables\Columns\TextColumn::make('project.name')
                    ->label('Proje')
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('computed_total')
                    ->label('Sözleşme')
                    ->money('TRY')
                    ->alignRight(),
                Tables\Columns\TextColumn::make('computed_paid')
                    ->label('Gerçek Ödenen')
                    ->money('TRY')
                    ->alignRight()
                    ->color('success'),
                Tables\Columns\TextColumn::make('computed_pending')
                    ->label('Bekleyen Çek')
                    ->money('TRY')
                    ->alignRight()
                    ->color('warning')
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('computed_remaining')
                    ->label('Kalan')
                    ->money('TRY')
                    ->alignRight()
                    ->weight('bold')
                    ->color('danger'),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('Ödenmemiş sözleşme bakiyesi yok');
    }
}
