<?php

namespace App\Filament\Resources\ActivityLogs;

use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\ActivityLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * İşlem Geçmişi (profil menüsü): firmadaki her iş günlük gibi, yeniden eskiye — kim, hangi kanaldan, ne yaptı; silinen kaydın
 * ayrıntısı satıra tıklayınca. Yalnız okunur (satırlar hiç değişmez/silinmez). Cari silinse de geçmişi burada.
 */
class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    /** Sol menüde değil, profil menüsünde (AdminPanelProvider::userMenuItems). */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationLabel = 'İşlem Geçmişi';

    protected static ?string $modelLabel = 'İşlem';

    protected static ?string $pluralModelLabel = 'İşlem Geçmişi';

    protected static ?string $slug = 'activity';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('happened_at', 'desc')
            ->columns([
                TextColumn::make('happened_at')->label('Zaman')->dateTime('d.m.Y H:i'),
                TextColumn::make('event')->label('')->badge()
                    ->formatStateUsing(fn (string $state) => ActivityLog::EVENTS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'created' => 'success',
                        'updated' => 'info',
                        'deleted' => 'danger',
                        default   => 'gray',
                    }),
                TextColumn::make('action')->label('İşlem')->searchable()
                    ->description(fn (ActivityLog $r) => $r->note),
                TextColumn::make('party_name')->label('Kişi / Firma')->searchable()->placeholder('—'),
                TextColumn::make('summary')->label('Açıklama')->searchable()->limit(60)->placeholder('—'),
                TextColumn::make('amount')->label('Tutar')->alignEnd()->placeholder('')
                    ->formatStateUsing(fn ($state, ActivityLog $r) => (float) $state > 0
                        ? ($r->old_amount !== null ? \App\Support\Money::format($r->old_amount) . ' → ' : '') . \App\Support\Money::format($state) . ' ₺'
                        : ''),
                TextColumn::make('channel')->label('Kanal')
                    ->formatStateUsing(fn ($state, ActivityLog $r) => $r->channelLabel())
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('event')->label('Ne oldu')
                    ->options(['created' => 'Eklendi', 'updated' => 'Düzenlendi', 'deleted' => 'Silindi']),
                SelectFilter::make('channel')->label('Kanal')->options(ActivityLog::CHANNELS),
                SelectFilter::make('action')->label('İşlem türü')
                    ->options(fn () => ActivityLog::query()->distinct()->orderBy('action')->pluck('action', 'action')->all()),
                Filter::make('dates')
                    ->schema([
                        DatePicker::make('from')->label('Başlangıç'),
                        DatePicker::make('until')->label('Bitiş'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('happened_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('happened_at', '<=', $d))),
                // İlk kurulumda yazılan "önceden vardı" satırları kalabalık etmesin — istenirse açılır.
                TernaryFilter::make('existing')->label('Önceden var olan kayıtlar')
                    ->placeholder('Gizle')->trueLabel('Göster')->falseLabel('Gizle')
                    ->queries(
                        true: fn (Builder $query) => $query,
                        false: fn (Builder $query) => $query->where('event', '!=', 'existing'),
                        blank: fn (Builder $query) => $query->where('event', '!=', 'existing'),
                    ),
            ])
            ->recordAction('details')
            ->recordActions([
                Action::make('details')->label('Ayrıntı')->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (ActivityLog $r) => $r->action . ($r->party_name ? ' · ' . $r->party_name : ''))
                    ->modalContent(fn (ActivityLog $r) => view('filament.activity.row', ['log' => $r, 'showParty' => true, 'open' => true]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Kapat'),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityLogs::route('/'),
        ];
    }
}
