<?php

namespace App\Filament\Widgets;

use App\Models\Contract;
use App\Models\Party;
use App\Support\Money;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class TopCustomersTable extends TableWidget
{
    protected static ?string $heading = 'Müşteri Top 5';

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        $contractTotalSql = 'CASE WHEN contracts.total_amount > 0 THEN contracts.total_amount '
            . 'ELSE COALESCE((SELECT SUM(amount) FROM contract_items WHERE contract_id = contracts.id), 0) END';

        // Tahsil edilen = nakit/EFT + tahsil edilmiş çek. Satış tarafı → direction=satis.
        $collectedSql = "("
            . "(select coalesce(sum(cp.amount), 0) from contract_payments cp join contracts c on c.id = cp.contract_id where c.party_id = parties.id and c.direction = '" . Contract::DIRECTION_SALE . "' and cp.payment_type <> 'check')"
            . " + (select coalesce(sum(ck.amount), 0) from checks ck join contract_payments cp on cp.id = ck.contract_payment_id join contracts c on c.id = cp.contract_id where c.party_id = parties.id and c.direction = '" . Contract::DIRECTION_SALE . "' and ck.status in ('paid', 'collected'))"
            . ")";

        return $table
            ->query(
                Party::query()
                    ->select('parties.*')
                    ->selectSub(
                        Contract::query()
                            ->withoutGlobalScope('purchase')
                            ->selectRaw("COALESCE(SUM($contractTotalSql), 0)")
                            ->whereColumn('party_id', 'parties.id')
                            ->where('direction', Contract::DIRECTION_SALE),
                        'total_sales'
                    )
                    ->selectRaw("$collectedSql as collected_total")
                    ->whereHas('contracts', fn (Builder $q) => $q->withoutGlobalScope('purchase')->where('direction', Contract::DIRECTION_SALE))
                    ->orderByDesc('total_sales')
                    ->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Müşteri / Cari')
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('total_sales')
                    ->label('Satış')
                    ->formatStateUsing(fn ($state): string => Money::format((float) $state) . ' ₺')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('collected_total')
                    ->label('Tahsil Edilen')
                    ->formatStateUsing(fn ($state): string => Money::format((float) $state) . ' ₺')
                    ->alignEnd()
                    ->color('success'),
            ])
            ->paginated(false)
            ->emptyStateHeading('Henüz satış sözleşmesi yok');
    }
}
