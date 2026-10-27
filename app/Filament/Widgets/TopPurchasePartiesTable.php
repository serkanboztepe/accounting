<?php

namespace App\Filament\Widgets;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Support\Money;
use App\Support\PartyStatement;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Sözleşmesiz firmada Tedarikçi/Taşeron Top 5'in yerine: cari alışına (iş / malzeme, iade düşer) göre ilk 5 cari +
 * ekstredeki açık borcumuz. Sözleşme ayrımı olmadığı için tedarikçi ve usta tek listede.
 */
class TopPurchasePartiesTable extends TableWidget
{
    protected static ?string $heading = 'En Çok Alış Yapılan 5 Cari';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $purchaseSql = "(select coalesce(sum(case when e.type = '" . PartyLedgerEntry::TYPE_PURCHASE . "' then e.amount else -e.amount end), 0)"
            . ' from party_ledger_entries e where e.party_id = parties.id'
            . " and e.type in ('" . PartyLedgerEntry::TYPE_PURCHASE . "', '" . PartyLedgerEntry::TYPE_PURCHASE_RETURN . "'))";

        return $table
            ->query(
                Party::query()
                    ->select('parties.*')
                    ->selectRaw("$purchaseSql as purchase_total")
                    ->whereHas('ledgerEntries', fn ($q) => $q->where('type', PartyLedgerEntry::TYPE_PURCHASE))
                    ->orderByDesc('purchase_total')
                    ->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Kişi / Firma')
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('purchase_total')
                    ->label('Toplam Alış')
                    ->formatStateUsing(fn ($state): string => Money::format((float) $state) . ' ₺')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('open_debt')
                    ->label('Açık Borcum')
                    // Ekstre ile aynı kaynak: bakiye < 0 → biz borçluyuz.
                    ->getStateUsing(fn (Party $p): float => max(0, -PartyStatement::build($p)['balance']))
                    ->formatStateUsing(fn (float $state): string => $state > 0 ? Money::format($state) . ' ₺' : 'Hesap kapalı')
                    ->alignEnd()
                    ->color(fn (float $state): string => $state > 0 ? 'danger' : 'success'),
            ])
            ->paginated(false)
            ->emptyStateHeading('Henüz cari alışı yok');
    }
}
