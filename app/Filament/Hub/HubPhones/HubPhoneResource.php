<?php

namespace App\Filament\Hub\HubPhones;

use App\Filament\Hub\HubPhones\Pages\ManageHubPhones;
use App\Models\HubPhone;
use App\Support\Phone;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Hub: hangi telefon hangi firmaya yazar. Burada olmayan numara "kayıtlı değil" cevabı alır. */
class HubPhoneResource extends Resource
{
    protected static ?string $model = HubPhone::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static ?string $navigationLabel = 'Telefonlar';

    protected static ?string $modelLabel = 'Telefon';

    protected static ?string $pluralModelLabel = 'Telefonlar';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('phone')
                ->label('WhatsApp numarası')
                ->placeholder('0532 123 45 67')
                ->helperText('0532…, +90 532…, 532… — hepsi olur. Yurt dışı numarayı ülke koduyla yaz.')
                ->required()
                ->rule(fn (?HubPhone $record) => function (string $attribute, $value, Closure $fail) use ($record) {
                    $normalized = Phone::normalize((string) $value);
                    if (strlen($normalized) < 10) {
                        $fail('Geçerli bir numara gir.');

                        return;
                    }
                    $taken = HubPhone::where('phone', $normalized)
                        ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                        ->exists();
                    if ($taken) {
                        $fail('Bu numara zaten kayıtlı (bir telefon tek firmaya bağlanır).');
                    }
                }),
            Select::make('hub_firm_id')
                ->label('Firma')
                ->relationship('firm', 'name')
                ->required()
                ->preload(),
            TextInput::make('name')->label('Kimin telefonu')->placeholder('Ferhat Yıldız')->maxLength(255),
            Toggle::make('is_active')->label('Aktif')->default(true),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('phone')->label('Numara')->searchable()
                    ->formatStateUsing(fn (string $state) => '+' . $state),
                TextColumn::make('name')->label('Kişi')->searchable(),
                TextColumn::make('firm.name')->label('Firma')->sortable(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->defaultSort('hub_firm_id')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageHubPhones::route('/')];
    }
}
