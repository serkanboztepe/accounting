<?php

namespace App\Filament\Resources\AssistantMemories;

use App\Filament\Resources\AssistantMemories\Pages\ManageAssistantMemories;
use App\Models\AssistantMemory;
use App\Models\Party;
use App\Services\Whatsapp\ChatAssistant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Asistan hafızası (Faz 2): WhatsApp asistanına öğretilenler — müşteri görür, düzeltir, unutturur.
 * "Unut" kaydı silmez, durumunu deleted yapar (geçmiş kalır); değişen bilgi eskisini "replaced" yapar.
 */
class AssistantMemoryResource extends Resource
{
    protected static ?string $model = AssistantMemory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'Sistem';

    /** Sol menüde değil, sağ üstteki profil menüsünde (AdminPanelProvider::userMenuItems) — menü kalabalık etmesin. */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Asistan hafızası';

    protected static ?string $modelLabel = 'Hatırlanan bilgi';

    protected static ?string $pluralModelLabel = 'Asistan hafızası';

    public static function canAccess(): bool
    {
        return ChatAssistant::enabled();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('kind')->label('Tür')->options(AssistantMemory::KINDS)->required()->default('note'),
            TextInput::make('subject')->label('Konu')->placeholder('ör. kalfa, Recep peynir fiyatı')->required()->maxLength(120),
            Textarea::make('fact')->label('Bilgi')->placeholder('ör. Kalfa = Mehmet Kaya')->required()->rows(2)->columnSpanFull(),
            Select::make('party_id')->label('İlgili kişi / firma')->options(fn () => Party::orderBy('name')->pluck('name', 'id'))->searchable(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('kind')->label('Tür')->badge()
                    ->formatStateUsing(fn (string $state) => (AssistantMemory::ICONS[$state] ?? '') . ' ' . (AssistantMemory::KINDS[$state] ?? $state)),
                TextColumn::make('fact')->label('Bilgi')->wrap()->searchable(),
                TextColumn::make('party.name')->label('Kişi / Firma')->placeholder('—'),
                TextColumn::make('phone')->label('Kime')->formatStateUsing(fn ($state) => $state ? 'yalnız ' . $state : 'herkes')
                    ->default('herkes'),
                TextColumn::make('status')->label('Durum')->badge()
                    ->formatStateUsing(fn (string $state) => ['active' => 'Geçerli', 'replaced' => 'Eskidi', 'deleted' => 'Unutuldu'][$state] ?? $state)
                    ->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
                TextColumn::make('updated_at')->label('Tarih')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Durum')->default('active')
                    ->options(['active' => 'Geçerli', 'replaced' => 'Eskidi', 'deleted' => 'Unutuldu']),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (AssistantMemory $r) => $r->status === 'active'),
                Action::make('forget')->label('Unut')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->requiresConfirmation()->modalHeading('Bu bilgiyi unutsun mu?')
                    ->visible(fn (AssistantMemory $r) => $r->status === 'active')
                    ->action(function (AssistantMemory $r) {
                        $r->update(['status' => 'deleted']);
                        Notification::make()->title('Unutuldu')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAssistantMemories::route('/')];
    }
}
