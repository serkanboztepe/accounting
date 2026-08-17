<?php

namespace App\Filament\Widgets;

use App\Models\Check;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Carbon;

class OverdueChecksTable extends TableWidget
{
    protected static ?string $heading = 'Vadesi Geçmiş Çekler';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Check::query()
                    ->with(['party', 'project'])
                    ->whereIn('status', ['portfolio', 'issued'])
                    ->whereDate('due_date', '<', Carbon::today())
                    ->orderBy('due_date')
            )
            ->columns([
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Vade')
                    ->date('d.m.Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('party.name')
                    ->label('Cari')
                    ->searchable(),
                Tables\Columns\TextColumn::make('project.name')
                    ->label('Proje')
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Tutar')
                    ->money('TRY')
                    ->sortable(),
                Tables\Columns\TextColumn::make('check_number')
                    ->label('Çek No')
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Durum')
                    ->badge(),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('Vadesi geçmiş çek yok');
    }
}
