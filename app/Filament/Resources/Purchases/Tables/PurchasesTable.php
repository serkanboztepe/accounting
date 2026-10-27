<?php

namespace App\Filament\Resources\Purchases\Tables;

use App\Models\Purchase;
use App\Support\Money;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PurchasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('purchase_date', 'desc')
            ->recordClasses(fn (Purchase $record, $livewire) => ($livewire->lastSavedId ?? null) === $record->id
                ? 'bg-primary-50 dark:bg-primary-500/10'
                : null)
            ->columns([
                TextColumn::make('purchase_date')->label('Tarih')->date('d.m.Y')->sortable(),

                TextColumn::make('party.name')
                    ->label('Kimden')
                    ->default('—')
                    ->searchable(),

                TextColumn::make('paid')
                    ->label('Ödeme')
                    ->state(fn (Purchase $record) => $record->paid
                        ? 'Peşin · ' . (Purchase::PAYMENT_TYPES[$record->payment_type] ?? 'Nakit')
                        : 'Veresiye')
                    ->badge()
                    ->color(fn (Purchase $record) => $record->paid ? 'success' : 'warning'),

                TextColumn::make('lines_summary')
                    ->label('Ürünler')
                    ->state(fn (Purchase $record) => $record->lines->map(fn ($m) => $m->product?->name . ' ' . rtrim(rtrim(number_format((float) $m->quantity, 2, ',', '.'), '0'), ','))->implode(', ')
                        ?: ((float) $record->manual_amount > 0 ? 'Ürünsüz' : '—'))
                    ->limit(50),

                TextColumn::make('total_amount')
                    ->label('Tutar')
                    ->formatStateUsing(fn ($state) => Money::format((float) $state) . ' ₺')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('notes')->label('Açıklama')->limit(30)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with(['party', 'lines.product']))
            ->filters([
                SelectFilter::make('period')
                    ->label('Dönem')
                    ->options(['today' => 'Bugün', 'month' => 'Bu ay', 'all' => 'Tümü'])
                    ->default('today')
                    ->selectablePlaceholder(false)
                    ->query(fn ($query, array $data) => match ($data['value'] ?? 'today') {
                        'today' => $query->whereDate('purchase_date', now()->toDateString()),
                        'month' => $query->whereBetween('purchase_date', [now()->startOfMonth()->toDateString(), now()->toDateString()]),
                        default => $query,
                    }),
                SelectFilter::make('party_id')->label('Kişi / Firma')->relationship('party', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
