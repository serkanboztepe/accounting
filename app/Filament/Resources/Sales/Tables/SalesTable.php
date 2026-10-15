<?php

namespace App\Filament\Resources\Sales\Tables;

use App\Support\Labels;
use App\Models\Sale;
use App\Support\Money;
use App\Support\Printing;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SalesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sale_date', 'desc')
            ->columns([
                TextColumn::make('sale_date')
                    ->label('Tarih')
                    ->date('d.m.Y')
                    ->sortable(),

                TextColumn::make('party.name')
                    ->label('Müşteri')
                    // Peşin (carisiz) satış — yalnız Satışlar tezgahında oluşur.
                    ->default('Peşin müşteri')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('payment')
                    ->label('Ödeme')
                    ->state(fn (Sale $record) => $record->paymentLabel() ?? 'Veresiye')
                    ->badge()
                    ->color(fn (Sale $record) => $record->isCash() ? 'success' : 'warning')
                    ->visible(fn () => (bool) config('modules.sales_desk')),

                TextColumn::make('project.name')
                    ->label(Labels::project() === 'Proje' ? 'Şantiye' : Labels::project())
                    ->visible(fn () => (bool) config('modules.projects'))
                    ->toggleable(),

                TextColumn::make('lines_count')
                    ->label('Kalem')
                    ->counts('lines')
                    ->formatStateUsing(fn ($state, Sale $record) => $state ?: ((float) $record->manual_amount > 0 ? 'Ürünsüz' : '0'))
                    ->alignEnd(),

                TextColumn::make('total_amount')
                    ->label('Tutar')
                    ->formatStateUsing(fn ($state) => Money::format((float) $state) . ' ₺')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('notes')
                    ->label('Not')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Tezgahta az önce kaydedilen satır vurgulanır.
            ->recordClasses(fn (Sale $record, $livewire) => ($livewire->lastSavedId ?? null) === $record->id
                ? 'bg-primary-50 dark:bg-primary-500/10'
                : null)
            ->filters(array_values(array_filter([
                config('modules.sales_desk') ? SelectFilter::make('period')
                    ->label('Dönem')
                    ->options(['today' => 'Bugün', 'month' => 'Bu ay', 'all' => 'Tümü'])
                    ->default('today')
                    ->selectablePlaceholder(false)
                    ->query(fn ($query, array $data) => match ($data['value'] ?? 'today') {
                        'today' => $query->whereDate('sale_date', now()->toDateString()),
                        'month' => $query->whereBetween('sale_date', [now()->startOfMonth()->toDateString(), now()->toDateString()]),
                        default => $query,
                    }) : null,
                SelectFilter::make('party_id')
                    ->label('Müşteri')
                    ->relationship('party', 'name')
                    ->searchable()
                    ->preload(),
            ])))
            ->recordActions([
                Action::make('return')
                    ->label('İade Al')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('warning')
                    ->visible(fn (Sale $record) => $record->hasReturnableItems())
                    ->modalHeading('İade Al')
                    ->modalSubmitActionLabel('İadeyi Kaydet')
                    ->fillForm(fn (Sale $record) => [
                        'return_date' => now()->toDateString(),
                        'lines' => $record->returnFormLines(),
                    ])
                    ->schema(\App\Filament\Resources\Sales\Schemas\SaleReturnForm::components())
                    ->action(function (array $data, Sale $record) {
                        $record->processReturn(
                            $data['lines'] ?? [],
                            $data['return_date'],
                            $data['return_notes'] ?? null,
                        );
                    }),

                Action::make('print')
                    ->label('Yazdır')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->url(fn (Sale $record) => route('sale.print', $record), shouldOpenInNewTab: true)
                    ->extraAttributes(fn (Sale $record) => Printing::iframeAttributes(route('sale.print', $record))),

                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
