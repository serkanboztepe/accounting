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
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
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
     * Tek panelde (TENANCY) oluştururken üç yol: yeni veritabanı / mevcut veritabanını bağla /
     * ayrı kurulum (adres). Eski hub'da (APP_ROLE=hub) yalnız adres.
     */
    public static function components(bool $creating = false, ?HubFirm $record = null): array
    {
        $kind = fn (Get $get): string => (string) ($get('kind') ?? '');

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

        return [
            TextInput::make('name')->label('Firma')->placeholder('Yıldız Mimarlık')->required()->maxLength(255),
            Radio::make('kind')
                ->label('Kurulum')
                ->options([
                    'new'      => 'Yeni firma (boş veritabanı açılır)',
                    'existing' => 'Mevcut veritabanını bağla (eski ayrı kurulum — veri kopyalanmaz)',
                    'remote'   => 'Ayrı sunucuda (mesajlar adrese iletilir)',
                ])
                ->default('new')
                ->live()
                ->required(),

            // Yeni
            TextInput::make('code')
                ->label('Kısa ad')
                ->placeholder('yildiz')
                ->helperText(fn (?string $state) => 'Veritabanı: ' . ($state ? self::safeDbName($state) : config('tenancy.database_prefix') . '…'))
                ->live(onBlur: true)
                ->visible(fn (Get $get) => $kind($get) === 'new')
                ->required(fn (Get $get) => $kind($get) === 'new')
                ->regex('/^[A-Za-z0-9_-]+$/')
                ->maxLength(40),
            Select::make('profile')
                ->label('Sektör (modül seti)')
                ->options(self::PROFILE_OPTIONS)
                ->placeholder('Hepsi açık')
                ->visible(fn (Get $get) => $kind($get) !== 'remote'),
            TextInput::make('user_name')->label('İlk kullanıcı — ad soyad')
                ->visible(fn (Get $get) => $kind($get) === 'new')->required(fn (Get $get) => $kind($get) === 'new'),
            TextInput::make('user_email')->label('E-posta (giriş)')->email()
                ->visible(fn (Get $get) => $kind($get) === 'new')->required(fn (Get $get) => $kind($get) === 'new'),
            TextInput::make('user_password')->label('Geçici şifre')->password()->revealable()->minLength(8)
                ->visible(fn (Get $get) => $kind($get) === 'new')->required(fn (Get $get) => $kind($get) === 'new'),

            // Mevcut veritabanı
            TextInput::make('database')->label('Veritabanı adı')->placeholder('yildiz_mgmt')
                ->visible(fn (Get $get) => $kind($get) === 'existing')->required(fn (Get $get) => $kind($get) === 'existing')
                ->regex('/^[A-Za-z0-9_]+$/')
                ->unique(HubFirm::class, 'database'),
            TextInput::make('db_username')->label('Veritabanı kullanıcısı')
                ->helperText('Boş = panelin kendi veritabanı kullanıcısı (o veritabanına yetkisi olmalı).')
                ->visible(fn (Get $get) => $kind($get) === 'existing'),
            TextInput::make('db_password')->label('Veritabanı şifresi')->password()->revealable()
                ->visible(fn (Get $get) => $kind($get) === 'existing'),

            // Ayrı kurulum
            self::urlField()->visible(fn (Get $get) => $kind($get) === 'remote')->required(fn (Get $get) => $kind($get) === 'remote'),

            Toggle::make('is_active')->label('Aktif')->default(true),
        ];
    }

    public const PROFILE_OPTIONS = [
        'mimar'     => 'Mimar',
        'muteahhit' => 'Müteahhit',
        'toptanci'  => 'Toptancı / nalbur',
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
