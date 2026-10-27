<?php

namespace App\Filament\Resources\Parties;

use App\Filament\Resources\Parties\Pages\EditParty;
use App\Filament\Resources\Parties\Pages\ListParties;
use App\Filament\Resources\Parties\Schemas\PartyForm;
use App\Filament\Resources\Parties\Tables\PartiesTable;
use App\Http\Middleware\RequireCariUnlock;
use App\Models\Party;
use App\Support\CariLock;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PartyResource extends Resource
{
    protected static ?string $model = Party::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    // Kendi başına ayrı bir bölüm. 27.10: "Cari" yerine "Kişiler ve Firmalar" (kullanıcı — muhasebe dili değil, personel de burada).
    protected static string|UnitEnum|null $navigationGroup = 'Kişiler ve Firmalar';

    protected static ?string $navigationLabel = 'Kişi ve firma listesi';

    protected static ?string $modelLabel = 'Kişi / Firma';

    protected static ?string $pluralModelLabel = 'Kişiler ve Firmalar';

    protected static ?int $navigationSort = 0;

    protected static ?string $recordTitleAttribute = 'name';

    // Cari kilidi açıksa (CARI_LOCK) tüm Cariler sayfaları şifre ister.
    protected static string|array $routeMiddleware = [RequireCariUnlock::class];

    // Kilitliyken üstteki aramada cariler çıkmasın.
    public static function canGloballySearch(): bool
    {
        return parent::canGloballySearch() && CariLock::isOpen();
    }

    public static function form(Schema $schema): Schema
    {
        return PartyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PartiesTable::configure($table);
    }

    public static function getRelations(): array
    {
        // Cari hareketleri grid'i kaldırıldı — tek liste artık footer'daki "Cari Ekstresi"
        // (yürüyen bakiyeli). Yeni kayıt üstteki butonlardan, düzenle/sil satıra tıklayınca.
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListParties::route('/'),
            'edit' => EditParty::route('/{record}/edit'),
        ];
    }
}
