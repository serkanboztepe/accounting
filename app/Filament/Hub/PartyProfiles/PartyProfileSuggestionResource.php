<?php

namespace App\Filament\Hub\PartyProfiles;

use App\Filament\Hub\PartyProfiles\Pages\ManagePartyProfileSuggestions;
use App\Models\PartyProfileSuggestion;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Hub → Cari Tanımları (Faz 3.2): asistanın cari tanımı önerileri; onaylanınca firmanın asistan hafızasına girer. */
class PartyProfileSuggestionResource extends Resource
{
    protected static ?string $model = PartyProfileSuggestion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $navigationLabel = 'Cari Tanımları';

    protected static ?string $modelLabel = 'Cari tanımı';

    protected static ?string $pluralModelLabel = 'Cari Tanımları';

    protected static ?int $navigationSort = 7;

    public static function getNavigationBadge(): ?string
    {
        $n = PartyProfileSuggestion::where('status', 'pending')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('status')->label('Durum')->badge()
                    ->formatStateUsing(fn (string $state) => PartyProfileSuggestion::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => ['pending' => 'warning', 'approved' => 'success'][$state] ?? 'gray'),
                TextColumn::make('firm_name')->label('Firma'),
                TextColumn::make('party_name')->label('Cari')->weight('medium'),
                TextColumn::make('profile')->label('Önerilen tanım')->wrap(),
                TextColumn::make('created_at')->label('Tarih')->date('d.m.Y'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Durum')->options(PartyProfileSuggestion::STATUSES)->default('pending'),
            ])
            ->recordActions([
                Action::make('approve')->label('Onayla')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (PartyProfileSuggestion $r) => $r->status === 'pending')
                    ->action(function (PartyProfileSuggestion $r) {
                        $r->approve();
                        Notification::make()->success()->title('Asistan hafızasına yazıldı')->send();
                    }),
                Action::make('edit')->label('Düzelt')->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (PartyProfileSuggestion $r) => in_array($r->status, ['pending', 'approved'], true))
                    ->fillForm(fn (PartyProfileSuggestion $r) => ['profile' => $r->profile])
                    ->schema([Textarea::make('profile')->label('Tanım (tek satır)')->required()->rows(2)])
                    ->action(function (PartyProfileSuggestion $r, array $data) {
                        $r->approve($data['profile']);
                        Notification::make()->success()->title('Asistan hafızasına yazıldı')->send();
                    }),
                Action::make('reject')->label('Gerek yok')->icon(Heroicon::OutlinedXMark)->color('gray')
                    ->visible(fn (PartyProfileSuggestion $r) => $r->status === 'pending')
                    ->action(fn (PartyProfileSuggestion $r) => $r->update(['status' => 'rejected', 'decided_at' => now()])),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ManagePartyProfileSuggestions::route('/')];
    }
}
