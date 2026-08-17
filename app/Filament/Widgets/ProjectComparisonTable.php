<?php

namespace App\Filament\Widgets;

use App\Models\Contract;
use App\Models\Project;
use App\Support\Money;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class ProjectComparisonTable extends TableWidget
{
    protected static ?string $heading = 'Proje Karşılaştırma';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Project::query()
                    ->with(['contracts.items', 'contracts.payments', 'contracts.deliveries', 'expenses'])
                    ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'draft' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END")
                    ->orderBy('name')
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Proje')
                    ->searchable()
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Durum')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'draft'     => 'Taslak',
                        'active'    => 'Aktif',
                        'completed' => 'Tamamlandı',
                        'cancelled' => 'İptal',
                        default     => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'active'    => 'success',
                        'completed' => 'gray',
                        'cancelled' => 'danger',
                        default     => 'warning',
                    }),

                Tables\Columns\TextColumn::make('contracts_total')
                    ->label('Sözleşme')
                    ->getStateUsing(fn (Project $p): float => $p->contracts->sum(fn (Contract $c) => $c->reportableTotal()))
                    ->formatStateUsing(fn (float $state): string => Money::format($state) . ' ₺')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('cash_paid')
                    ->label('Gerçek Ödenen')
                    ->getStateUsing(fn (Project $p): float => $p->contracts->sum(fn (Contract $c) => $c->cashPaidAmount()))
                    ->formatStateUsing(function (float $state, Project $p): string {
                        $total = $p->contracts->sum(fn (Contract $c) => $c->reportableTotal());
                        $percent = $total > 0 ? round($state / $total * 100) : 0;

                        return Money::format($state) . " ₺ ({$percent}%)";
                    })
                    ->alignEnd()
                    ->color(function (Project $p): string {
                        $total = $p->contracts->sum(fn (Contract $c) => $c->reportableTotal());
                        $cash  = $p->contracts->sum(fn (Contract $c) => $c->cashPaidAmount());
                        $percent = $total > 0 ? $cash / $total * 100 : 0;

                        return match (true) {
                            $percent >= 90 => 'success',
                            $percent >= 50 => 'warning',
                            default        => 'gray',
                        };
                    }),

                Tables\Columns\TextColumn::make('pending_checks')
                    ->label('Açık Çek')
                    ->getStateUsing(fn (Project $p): float => $p->contracts->sum(fn (Contract $c) => $c->pendingCheckAmount()))
                    ->formatStateUsing(fn (float $state): string => Money::format($state) . ' ₺')
                    ->alignEnd()
                    ->color('warning'),

                Tables\Columns\TextColumn::make('delivery_total')
                    ->label('Teslimat')
                    ->getStateUsing(fn (Project $p): float => (float) $p->contractDeliveries()->sum('amount'))
                    ->formatStateUsing(fn (float $state): string => Money::format($state) . ' ₺')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('expenses_total')
                    ->label('Direkt Gider')
                    ->getStateUsing(fn (Project $p): float => (float) $p->expenses->sum('amount'))
                    ->formatStateUsing(fn (float $state): string => Money::format($state) . ' ₺')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('remaining_balance')
                    ->label('Kalan Bakiye')
                    // Rapor sayfasıyla aynı: Kalan = max(0, tutar − gerçek ödenen).
                    // Bekleyen çek Kalan'ın içindedir; ayrıca "Açık Çek" kolonunda detaylanır.
                    ->getStateUsing(fn (Project $p): float => $p->contracts->sum(
                        fn (Contract $c) => max(0, $c->reportableTotal() - $c->cashPaidAmount()),
                    ))
                    ->formatStateUsing(fn (float $state): string => Money::format($state) . ' ₺')
                    ->alignEnd()
                    ->color('danger'),
            ])
            ->paginated(false)
            ->emptyStateHeading('Henüz proje yok');
    }
}
