<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Models\Project;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProjectsTable
{
    public const STATUSES = [
        'draft'     => 'Taslak',
        'active'    => 'Aktif',
        'completed' => 'Tamamlandı',
        'cancelled' => 'İptal',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Ad')->searchable()->sortable(),
                // Çiftlik (alışı yere yaz, 26.10): yerin sezon sonucu (satış − alış − personel − gider, yalnız bu yere yazılanlar).
                TextColumn::make('season_result')
                    ->label('Sezon sonucu')
                    ->state(fn (\App\Models\Project $record) => \App\Support\MonthlyResult::forPlace($record)['result'])
                    ->formatStateUsing(fn ($state) => ($state < 0 ? '−' : '') . \App\Support\Money::format(abs((float) $state)) . ' ₺')
                    ->color(fn ($state) => $state < 0 ? 'danger' : 'success')
                    ->visible(fn () => \App\Support\Features::placeCost()),
                TextColumn::make('party.name')
                    ->label('Sahip müşteri')
                    ->placeholder('—')
                    // Formla aynı kural (ProjectForm): şube/yer olarak kullanan firmada sahip ve ada/parsel yok.
                    ->visible(fn () => ! in_array(config('app.profile'), ['muteahhit', 'trade', 'alacak_verecek'], true))
                    ->toggleable(),
                TextColumn::make('cadastral_parcel')->label('Ada / Parsel')->placeholder('—')->searchable()
                    ->visible(fn () => ! in_array(config('app.profile'), ['trade', 'alacak_verecek'], true)),
                TextColumn::make('address')->label('Adres')->placeholder('—')->limit(40)->tooltip(fn ($state) => $state)->searchable()->toggleable(),
                TextColumn::make('status')
                    ->label('Durum')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'active'    => 'success',
                        'completed' => 'info',
                        'cancelled' => 'danger',
                        default     => 'gray',
                    }),
                TextColumn::make('start_date')->label('Başlangıç')->date()->sortable(),
                TextColumn::make('end_date')->label('Bitiş')->date()->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Durum')
                    ->options(self::STATUSES)
                    ->default('active'),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('complete')
                        ->label('Tamamlandı')
                        ->icon('heroicon-o-check-circle')
                        ->color('info')
                        ->visible(fn (Project $record) => $record->status !== 'completed')
                        ->requiresConfirmation()
                        ->modalHeading('Proje tamamlandı olarak işaretlensin mi?')
                        ->modalDescription('Bitiş tarihi bugün olarak kaydedilir. Proje listede "Tamamlandı" filtresiyle görünür.')
                        ->action(fn (Project $record) => $record->update([
                            'status'   => 'completed',
                            'end_date' => now()->toDateString(),
                        ])),
                    Action::make('reopen')
                        ->label('Yeniden Aç')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->visible(fn (Project $record) => $record->status === 'completed')
                        ->requiresConfirmation()
                        ->action(fn (Project $record) => $record->update([
                            'status'   => 'active',
                            'end_date' => null,
                        ])),
                    DeleteAction::make(),
                ]),
            ]);
    }
}
