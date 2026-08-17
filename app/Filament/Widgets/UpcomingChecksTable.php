<?php

namespace App\Filament\Widgets;

use App\Models\Check;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class UpcomingChecksTable extends TableWidget
{
    protected static ?string $heading = 'Yaklaşan Çekler';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $today = Carbon::today();

        return $table
            ->query(
                Check::query()
                    ->with(['party', 'project'])
                    ->whereIn('status', ['portfolio', 'issued'])
                    ->whereDate('due_date', '>=', $today)
                    ->whereDate('due_date', '<=', $today->copy()->addDays(14))
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
                Tables\Columns\TextColumn::make('bank_name')
                    ->label('Banka')
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Durum')
                    ->badge(),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('Yaklaşan çek yok');
    }
}
