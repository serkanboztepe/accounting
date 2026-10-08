<?php

namespace App\Filament\Hub\HubFirms;

use App\Filament\Hub\HubFirms\Pages\EditHubFirm;
use App\Filament\Hub\HubFirms\Pages\ListHubFirms;
use App\Filament\Hub\HubFirms\RelationManagers\PhonesRelationManager;
use App\Models\HubFirm;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Hub (APP_ROLE=hub) tek ekranı: firmalar; firmaya girince altında numaraları.
 * Burada olmayan numara WhatsApp'ta tanıtım cevabı alır.
 */
class HubFirmResource extends Resource
{
    protected static ?string $model = HubFirm::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Firmalar';

    protected static ?string $modelLabel = 'Firma';

    protected static ?string $pluralModelLabel = 'Firmalar';

    /** ⚙ Ayarlar modalı ve "Firma Oluştur" ortak alanları. */
    public static function components(): array
    {
        return [
            TextInput::make('name')->label('Firma')->required()->maxLength(255),
            TextInput::make('url')
                ->label('Kurulum adresi')
                ->placeholder('https://yildiz.boztepeler.com')
                ->helperText('Mesajlar bu adresin /whatsapp/webhook ucuna iletilir.')
                ->required()->url()->maxLength(255),
            Toggle::make('is_active')->label('Aktif')->default(true),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::components())->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Firma')->searchable()->sortable()->weight('semibold'),
                TextColumn::make('phones_count')->label('Numara')->counts('phones')->badge(),
                TextColumn::make('message_logs_count')
                    ->label('Bu ay mesaj')
                    ->counts(['messageLogs' => fn ($q) => $q->where('created_at', '>=', now()->startOfMonth())]),
                TextColumn::make('url')->label('Adres')->color('gray'),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [PhonesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHubFirms::route('/'),
            'edit' => EditHubFirm::route('/{record}'),
        ];
    }
}
