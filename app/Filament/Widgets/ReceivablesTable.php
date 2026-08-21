<?php

namespace App\Filament\Widgets;

use App\Models\Contract;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class ReceivablesTable extends TableWidget
{
    protected static ?string $heading = 'Alacaklar';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        // Ödenmemiş Sözleşme Bakiyeleri tablosunun SATIŞ aynası:
        //   Satış tutarı  = manuel götürü (total_amount) > 0 ise o, değilse kalemler toplamı.
        //   Tahsil edilen = nakit/EFT + tahsil edilmiş çek (paid/collected).
        //   Kalan alacak  = satış tutarı − tahsil edilen.
        $totalExpr = "COALESCE(NULLIF(contracts.total_amount, 0), (select coalesce(sum(amount), 0) from contract_items where contract_items.contract_id = contracts.id))";

        $collectedExpr = "("
            . "(select coalesce(sum(cp.amount), 0) from contract_payments cp where cp.contract_id = contracts.id and cp.payment_type <> 'check')"
            . " + (select coalesce(sum(ck.amount), 0) from checks ck join contract_payments cp2 on cp2.id = ck.contract_payment_id where cp2.contract_id = contracts.id and ck.status in ('paid', 'collected'))"
            . ")";

        $remainingExpr = "($totalExpr) - ($collectedExpr)";

        return $table
            ->query(
                Contract::query()
                    ->withoutGlobalScope('purchase')
                    ->where('contracts.direction', Contract::DIRECTION_SALE)
                    ->with(['party', 'project'])
                    ->whereIn('status', ['active', 'completed'])
                    ->select('contracts.*')
                    ->selectRaw("$totalExpr as computed_total")
                    ->selectRaw("$collectedExpr as computed_collected")
                    ->selectRaw("$remainingExpr as computed_remaining")
                    ->orderByRaw('computed_remaining desc')
            )
            ->columns([
                Tables\Columns\TextColumn::make('party.name')
                    ->label('Müşteri / Cari')
                    ->weight('medium')
                    ->searchable(),
                Tables\Columns\TextColumn::make('title')
                    ->label('Satış')
                    ->limit(30)
                    ->tooltip(fn ($state) => $state)
                    ->searchable(),
                Tables\Columns\TextColumn::make('project.name')
                    ->label('Proje')
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('computed_total')
                    ->label('Satış Tutarı')
                    ->money('TRY')
                    ->alignRight(),
                Tables\Columns\TextColumn::make('computed_collected')
                    ->label('Tahsil Edilen')
                    ->money('TRY')
                    ->alignRight()
                    ->color('success'),
                Tables\Columns\TextColumn::make('computed_remaining')
                    ->label('Kalan Alacak')
                    ->money('TRY')
                    ->alignRight()
                    ->weight('bold')
                    ->color('danger'),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('Açık alacak yok');
    }
}
