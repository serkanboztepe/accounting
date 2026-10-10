<?php

namespace App\Filament\Hub\ConversationReports;

use App\Filament\Hub\ConversationReports\Pages\ListConversationReports;
use App\Filament\Hub\ConversationReports\Pages\ViewConversationReport;
use App\Models\ConversationReport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Hub → Günlük Analiz: her gece yazılan numaralı konuşma raporları (yalnız okuma). Karar düğmesi
 * yok — yönetici Claude Code oturumunda "Rapor 4: 1'i şöyle yapalım" diye yorumlar.
 */
class ConversationReportResource extends Resource
{
    protected static ?string $model = ConversationReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static ?string $navigationLabel = 'Günlük Analiz';

    protected static ?string $modelLabel = 'Rapor';

    protected static ?string $pluralModelLabel = 'Günlük Analiz';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Rapor')->formatStateUsing(fn ($state) => "#{$state}")->weight('semibold'),
                TextColumn::make('report_date')->label('Gün')->date('d.m.Y'),
                TextColumn::make('summary')->label('Özet')->wrap(),
                TextColumn::make('message_count')->label('Mesaj'),
                TextColumn::make('firm_count')->label('Firma'),
            ])
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (ConversationReport $record) => static::getUrl('view', ['record' => $record]));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConversationReports::route('/'),
            'view' => ViewConversationReport::route('/{record}'),
        ];
    }
}
