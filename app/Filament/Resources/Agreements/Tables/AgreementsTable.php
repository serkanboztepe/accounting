<?php

namespace App\Filament\Resources\Agreements\Tables;

use App\Filament\Resources\Parties\PartyResource;
use App\Models\Agreement;
use App\Models\AgreementItem;
use App\Support\Labels;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AgreementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('agreement_date', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['party', 'project', 'items.product.unit', 'items.movements']))
            ->recordUrl(fn (Agreement $record) => PartyResource::getUrl('edit', ['record' => $record->party_id]))
            ->deferFilters(false)
            ->columns([
                TextColumn::make('title')->label('Ad')->searchable()->weight('medium'),

                TextColumn::make('party.name')->label('Cari')->searchable(),

                TextColumn::make('direction')
                    ->label('Yön')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === Agreement::DIRECTION_SALE ? '📤 Satış' : '📥 Alış')
                    ->color(fn (string $state) => $state === Agreement::DIRECTION_SALE ? 'info' : 'warning'),

                TextColumn::make('agreed')
                    ->label('Anlaşılan')
                    ->state(fn (Agreement $record) => self::agreedText($record))
                    ->alignEnd(),

                TextColumn::make('delivered')
                    ->label('Gelen')
                    ->state(fn (Agreement $record) => Money::format($record->deliveredTotal()))
                    ->alignEnd(),

                TextColumn::make('remaining')
                    ->label('Kalan')
                    ->state(fn (Agreement $record) => $record->agreedTotal() > 0 ? Money::format($record->remainingTotal()) : '—')
                    ->alignEnd(),

                TextColumn::make('progress')
                    ->label('İlerleme')
                    ->state(fn (Agreement $record) => ($p = $record->progress()) === null ? '—' : '%' . $p)
                    ->alignEnd(),

                TextColumn::make('project.name')
                    ->label(fn () => Labels::project())
                    ->default('—')
                    ->visible(fn () => (bool) \App\Support\Features::places())
                    ->toggleable(),

                TextColumn::make('agreement_date')->label('Tarih')->date('d.m.Y')->sortable()->toggleable(),

                TextColumn::make('status')
                    ->label('Durum')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Agreement::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Agreement::STATUS_OPEN => 'primary',
                        Agreement::STATUS_DONE => 'success',
                        default                => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Durum')
                    ->options(Agreement::STATUSES)
                    ->default(Agreement::STATUS_OPEN),
                SelectFilter::make('direction')
                    ->label('Yön')
                    ->options(Agreement::DIRECTIONS),
                SelectFilter::make('party_id')
                    ->label('Cari')
                    ->relationship('party', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('project_id')
                    ->label(fn () => Labels::project())
                    ->relationship('project', 'name')
                    ->visible(fn () => (bool) \App\Support\Features::places()),
            ])
            ->emptyStateHeading('Anlaşma yok')
            ->emptyStateDescription('Carinin sayfasında "🤝 Anlaşma yap" ya da yukarıdaki "Yeni anlaşma" ile eklersin.');
    }

    /** Anlaşılan tutar; yalnız fiyat listesiyse "liste · 120/m²" (+ bütçe). */
    private static function agreedText(Agreement $record): string
    {
        $agreed = $record->agreedTotal();
        if ($agreed > 0) {
            return Money::format($agreed);
        }
        $list = $record->items->first(fn (AgreementItem $i) => $i->isList() && ! $i->isClosed());
        $text = $list ? 'liste · ' . Money::format((float) $list->unit_price) . ($list->unitName() ? '/' . $list->unitName() : '') : '—';

        return $text . ($record->budget !== null ? ' · bütçe ' . Money::format((float) $record->budget) : '');
    }
}
