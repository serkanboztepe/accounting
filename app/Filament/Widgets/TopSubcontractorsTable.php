<?php

namespace App\Filament\Widgets;

use App\Models\Contract;
use App\Models\Party;
use App\Support\Money;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class TopSubcontractorsTable extends TableWidget
{
    protected static ?string $heading = 'Taşeron Top 5';

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        $contractTotalSql = 'CASE WHEN contracts.total_amount > 0 THEN contracts.total_amount '
            . 'ELSE COALESCE((SELECT SUM(amount) FROM contract_items WHERE contract_id = contracts.id), 0) END';

        // Gerçek ödenen = nakit/EFT ödemeler + tahsil edilmiş çek (paid/collected).
        // Yazılmış ama tahsil edilmemiş çekler burada sayılmaz (dashboard modeliyle aynı).
        $cashPaidSql = "("
            . "(select coalesce(sum(cp.amount), 0) from contract_payments cp join contracts c on c.id = cp.contract_id where c.party_id = parties.id and c.contract_type = '" . Contract::TYPE_SUBCONTRACT . "' and cp.payment_type <> 'check')"
            . " + (select coalesce(sum(ck.amount), 0) from checks ck join contract_payments cp on cp.id = ck.contract_payment_id join contracts c on c.id = cp.contract_id where c.party_id = parties.id and c.contract_type = '" . Contract::TYPE_SUBCONTRACT . "' and ck.status in ('paid', 'collected'))"
            . ")";

        return $table
            ->query(
                Party::query()
                    ->select('parties.*')
                    ->selectSub(
                        Contract::query()
                            ->selectRaw("COALESCE(SUM($contractTotalSql), 0)")
                            ->whereColumn('party_id', 'parties.id')
                            ->where('contract_type', Contract::TYPE_SUBCONTRACT),
                        'total_subcontract'
                    )
                    ->selectRaw("$cashPaidSql as paid_total")
                    ->whereHas('contracts', fn (Builder $q) => $q->where('contract_type', Contract::TYPE_SUBCONTRACT))
                    ->orderByDesc('total_subcontract')
                    ->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Cari')
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('total_subcontract')
                    ->label('Sözleşme')
                    ->formatStateUsing(fn ($state): string => Money::format((float) $state) . ' ₺')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('paid_total')
                    ->label('Gerçek Ödenen')
                    ->formatStateUsing(fn ($state): string => Money::format((float) $state) . ' ₺')
                    ->alignEnd()
                    ->color('success'),
            ])
            ->paginated(false)
            ->emptyStateHeading('Henüz taşeron sözleşmesi yok');
    }
}
