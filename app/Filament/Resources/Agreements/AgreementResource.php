<?php

namespace App\Filament\Resources\Agreements;

use App\Filament\Resources\Agreements\Pages\ListAgreements;
use App\Filament\Resources\Agreements\Tables\AgreementsTable;
use App\Models\Agreement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Anlaşmalar (Faz 6, 21.10) — tüm carilerin anlaşmaları tek listede (yalnız liste; iş cari sayfasında yapılır:
 * kalem ekle, teslim aldım/ettim, fiyat değiştir). Satıra tıklayınca carinin sayfası açılır.
 */
class AgreementResource extends Resource
{
    protected static ?string $model = Agreement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static string|UnitEnum|null $navigationGroup = 'Kişiler ve Firmalar';

    protected static ?string $navigationLabel = 'Anlaşmalar';

    protected static ?string $modelLabel = 'Anlaşma';

    protected static ?string $pluralModelLabel = 'Anlaşmalar';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return (bool) \App\Support\Features::agreements();
    }

    public static function table(Table $table): Table
    {
        return AgreementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgreements::route('/'),
        ];
    }
}
