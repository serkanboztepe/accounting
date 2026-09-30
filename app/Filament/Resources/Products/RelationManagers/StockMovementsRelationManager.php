<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\StockMovement;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Ürünün stok hareketleri — ürün düzenleme sayfasında salt-okunur liste.
 * eMuhasebe'deki "ürün → hareketler" gibi; ayrı Stok Hareketleri menüsüne
 * gitmeden o ürünün giriş/çıkışları burada görünür. Salt-okunur: hareketler
 * satış/iade/mal girişinden türer, stok bütünlüğü için buradan değiştirilmez.
 */
class StockMovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'stockMovements';

    protected static ?string $title = 'Stok Hareketleri';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) config('modules.stock');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('movement_date', 'desc')
            ->columns([
                TextColumn::make('movement_date')
                    ->label('Tarih')
                    ->date('d.m.Y')
                    ->sortable(),

                TextColumn::make('direction')
                    ->label('Yön')
                    ->badge()
                    ->formatStateUsing(fn (?string $s) => StockMovement::DIRECTION_LABELS[$s] ?? $s)
                    ->color(fn (?string $s) => $s === StockMovement::DIRECTION_IN ? 'success' : 'danger'),

                TextColumn::make('reason')
                    ->label('Sebep')
                    ->badge()
                    ->formatStateUsing(fn (?string $s) => StockMovement::REASON_LABELS[$s] ?? $s),

                TextColumn::make('quantity')
                    ->label('Miktar')
                    ->numeric(2),

                TextColumn::make('unit_price')
                    ->label('Birim Fiyat')
                    ->formatStateUsing(fn ($state) => $state !== null ? Money::format($state) . ' ₺' : '—'),

                TextColumn::make('party.name')
                    ->label('Cari')
                    ->placeholder('—'),

                TextColumn::make('project.name')
                    ->label('Proje')
                    ->placeholder('—'),

                TextColumn::make('notes')
                    ->label('Not')
                    ->placeholder('—')
                    ->limit(30),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Bu ürün için hareket yok');
    }
}
