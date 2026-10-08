<?php

namespace App\Filament\Hub\HubFirms;

use App\Filament\Hub\HubFirms\Pages\EditHubFirm;
use App\Filament\Hub\HubFirms\Pages\ListHubFirms;
use App\Filament\Hub\HubFirms\RelationManagers\PhonesRelationManager;
use App\Filament\Hub\HubFirms\RelationManagers\UsersRelationManager;
use App\Tenancy\FirmProvisioner;
use App\Tenancy\Tenancy;
use App\Models\HubFirm;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Hub ekranı: firmalar; firmaya girince numaralar, kullanıcılar (tek panel), modüller, maliyet.
 * Burada olmayan numara WhatsApp'ta tanıtım cevabı alır.
 */
class HubFirmResource extends Resource
{
    protected static ?string $model = HubFirm::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Firmalar';

    protected static ?string $modelLabel = 'Firma';

    protected static ?string $pluralModelLabel = 'Firmalar';

    /**
     * ⚙ Ayarlar modalı ve "Firma Oluştur" ortak alanları.
     * Tek panelde (TENANCY) oluşturma = yeni veritabanı. Eski hub'da (APP_ROLE=hub) yalnız adres.
     */
    public static function components(bool $creating = false, ?HubFirm $record = null): array
    {
        if (! Tenancy::enabled()) {
            return [
                TextInput::make('name')->label('Firma')->required()->maxLength(255),
                self::urlField(),
                Toggle::make('is_active')->label('Aktif')->default(true),
            ];
        }

        if (! $creating) {
            return array_filter([
                TextInput::make('name')->label('Firma')->required()->maxLength(255),
                $record && ! $record->isLocal() ? self::urlField() : null,
                Toggle::make('is_active')->label('Aktif')
                    ->helperText('Pasif firma panele giremez, WhatsApp mesajı firmaya gitmez.'),
            ]);
        }

        // Tek panelde yeni firma = boş veritabanı + tablolar + ilk kullanıcı (FirmProvisioner).
        return [
            TextInput::make('name')->label('Firma')->placeholder('Kaya Yapı Market')->required()->maxLength(255),
            TextInput::make('code')
                ->label('Kısa ad')
                ->placeholder('kaya')
                ->helperText(fn (?string $state) => 'Veritabanı: ' . ($state ? self::safeDbName($state) : config('tenancy.database_prefix') . '…') . ' — sonradan değişmez.')
                ->live(onBlur: true)
                ->required()
                ->regex('/^[A-Za-z0-9_-]+$/')
                ->maxLength(40),
            Select::make('profile')
                ->label('Sektör (modül seti)')
                ->options(self::PROFILE_OPTIONS)
                ->placeholder('Hepsi açık'),
            TextInput::make('user_name')->label('İlk kullanıcı — ad soyad')->required(),
            TextInput::make('user_email')->label('E-posta (giriş)')->email()->required(),
            TextInput::make('user_password')->label('Geçici şifre')->password()->revealable()->minLength(8)->required(),
            Toggle::make('is_active')->label('Aktif')->default(true),
        ];
    }

    public const PROFILE_OPTIONS = [
        'mimar'     => 'Mimar',
        'muteahhit' => 'Müteahhit',
        'toptanci'  => 'Toptancı / nalbur',
        'alacak_verecek' => 'Alacak-verecek (esnaf: cari + gider, projesiz)',
    ];

    private static function urlField(): TextInput
    {
        return TextInput::make('url')
            ->label('Kurulum adresi')
            ->placeholder('https://yildiz.boztepeler.com')
            ->helperText('Mesajlar bu adresin /whatsapp/webhook ucuna iletilir.')
            ->required()->url()->maxLength(255);
    }

    private static function safeDbName(string $code): string
    {
        try {
            return FirmProvisioner::databaseNameFor($code);
        } catch (\Throwable) {
            return '—';
        }
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
                TextColumn::make('location')
                    ->label(Tenancy::enabled() ? 'Veritabanı / adres' : 'Adres')
                    ->state(fn (HubFirm $record) => $record->database ?: $record->url)
                    ->color('gray'),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [PhonesRelationManager::class, UsersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHubFirms::route('/'),
            'edit' => EditHubFirm::route('/{record}'),
        ];
    }
}
