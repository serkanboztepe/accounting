<?php

namespace App\Filament\Hub\HubFirms;

use App\Filament\Hub\HubFirms\Pages\ManageHubFirms;
use App\Models\HubFirm;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Hub: mesajların iletileceği firma kurulumları (sadece APP_ROLE=hub panelinde). */
class HubFirmResource extends Resource
{
    protected static ?string $model = HubFirm::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Firmalar';

    protected static ?string $modelLabel = 'Firma';

    protected static ?string $pluralModelLabel = 'Firmalar';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Firma')->required()->maxLength(255),
            TextInput::make('url')
                ->label('Kurulum adresi')
                ->placeholder('https://yildiz.boztepeler.com')
                ->helperText('Mesajlar bu adresin /whatsapp/webhook ucuna iletilir.')
                ->required()->url()->maxLength(255),
            Toggle::make('is_active')->label('Aktif')->default(true),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Firma')->searchable()->sortable(),
                TextColumn::make('url')->label('Adres'),
                TextColumn::make('phones_count')->label('Telefon')->counts('phones'),
                TextColumn::make('secret')
                    ->label('Gizli anahtar (HUB_SECRET)')
                    ->state(fn (HubFirm $record) => substr($record->secret, 0, 6) . '…')
                    ->copyable()
                    ->copyableState(fn (HubFirm $record) => $record->secret)
                    ->copyMessage('Kopyalandı — firmanın .env HUB_SECRET satırına yapıştır')
                    ->tooltip('Tıkla, kopyala'),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageHubFirms::route('/')];
    }
}
