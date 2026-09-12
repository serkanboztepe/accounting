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
                    ->schema([
                        DatePicker::make('return_date')
                            ->label('İade Tarihi')
                            ->required(),

                        Repeater::make('lines')
                            ->label('İade edilecek ürünler')
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columns(5)
                            ->columnSpanFull()
                            ->schema([
                                Hidden::make('product_id'),
                                Hidden::make('unit_price'),
                                Hidden::make('remaining'),

                                TextInput::make('product_label')
                                    ->label('Ürün')
                                    ->disabled()
                                    ->columnSpan(2),

                                TextInput::make('sold')
                                    ->label('Satılan')
                                    ->disabled(),

                                TextInput::make('returned_before')
                                    ->label('Önce iade')
                                    ->disabled(),

                                TextInput::make('return_qty')
                                    ->label('İade')
                                    ->numeric()
                                    ->step(0.01)
                                    ->default(0)
                                    ->minValue(0)
                                    ->maxValue(fn (Get $get) => (float) $get('remaining'))
                                    ->helperText(fn (Get $get) => 'Kalan: ' . number_format((float) $get('remaining'), 2, ',', '.')),
                            ]),

                        Textarea::make('return_notes')
                            ->label('İade notu')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
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
