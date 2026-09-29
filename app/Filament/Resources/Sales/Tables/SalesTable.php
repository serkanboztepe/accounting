<?php

namespace App\Filament\Resources\Sales\Tables;

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
                    ->searchable()
                    ->sortable(),

                TextColumn::make('project.name')
                    ->label('Şantiye')
                    ->toggleable(),

                TextColumn::make('lines_count')
                    ->label('Kalem')
                    ->counts('lines')
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
            ->filters([
                SelectFilter::make('party_id')
                    ->label('Müşteri')
                    ->relationship('party', 'name')
                    ->searchable()
                    ->preload(),
            ])
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
