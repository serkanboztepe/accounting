<?php

namespace App\Filament\Hub\AssistantLessons;

use App\Filament\Hub\AssistantLessons\Pages\ManageAssistantLessons;
use App\Models\AssistantLesson;
use App\Models\HubFirm;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Hub → Dersler (Faz 3): gece konuşma analizi asistanın hatalarından kural önerir; yönetici onaylar / düzeltir / reddeder.
 * Onaylı kural asistanın talimatına girer (firma dersi o firmaya, genel ders herkese). Sessiz öğrenme yok.
 */
class AssistantLessonResource extends Resource
{
    protected static ?string $model = AssistantLesson::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $navigationLabel = 'Dersler';

    protected static ?string $modelLabel = 'Ders';

    protected static ?string $pluralModelLabel = 'Dersler';

    protected static ?int $navigationSort = 5;

    public static function getNavigationBadge(): ?string
    {
        $n = AssistantLesson::where('status', 'pending')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('status')->label('Durum')->badge()
                    ->formatStateUsing(fn (string $state) => AssistantLesson::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => ['pending' => 'warning', 'approved' => 'success'][$state] ?? 'gray'),
                TextColumn::make('firm_name')->label('Kapsam')
                    ->formatStateUsing(fn ($state, AssistantLesson $r) => $r->hub_firm_id ? ($r->firm?->name ?? $state) : 'Genel (tüm firmalar)')
                    ->default('Genel (tüm firmalar)'),
                TextColumn::make('problem')->label('Ne oldu')->wrap()->limit(220)
                    ->description(fn (AssistantLesson $r) => $r->cause ? 'Neden: ' . $r->cause : null),
                TextColumn::make('rule')->label('Önerilen kural')->wrap()->weight('medium')
                    ->description(fn (AssistantLesson $r) => $r->example_message
                        ? 'Deneme: "' . $r->example_message . '" → ' . ($r->expected ?? '') : null),
                TextColumn::make('created_at')->label('Tarih')->date('d.m.Y'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Durum')->options(AssistantLesson::STATUSES)->default('pending'),
            ])
            ->recordActions([
                Action::make('approve')->label('Onayla')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (AssistantLesson $r) => $r->status === 'pending')
                    ->requiresConfirmation()->modalDescription(fn (AssistantLesson $r) => 'Asistan bundan sonra şuna uyacak: ' . $r->rule)
                    ->action(fn (AssistantLesson $r) => self::decide($r, 'approved')),
                Action::make('edit_approve')->label('Düzelt ve onayla')->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (AssistantLesson $r) => in_array($r->status, ['pending', 'approved'], true))
                    ->fillForm(fn (AssistantLesson $r) => ['rule' => $r->rule, 'hub_firm_id' => $r->hub_firm_id])
                    ->schema([
                        Textarea::make('rule')->label('Kural (asistana tek cümle)')->required()->rows(3),
                        Select::make('hub_firm_id')->label('Kapsam')->placeholder('Genel (tüm firmalar)')
                            ->options(fn () => HubFirm::orderBy('name')->pluck('name', 'id')),
                    ])
                    ->action(function (AssistantLesson $r, array $data) {
                        $r->update(['rule' => trim($data['rule']), 'hub_firm_id' => $data['hub_firm_id'] ?: null]);
                        self::decide($r, 'approved');
                    }),
                Action::make('reject')->label('Reddet')->icon(Heroicon::OutlinedXMark)->color('gray')
                    ->visible(fn (AssistantLesson $r) => $r->status === 'pending')
                    ->action(fn (AssistantLesson $r) => self::decide($r, 'rejected')),
                Action::make('retire')->label('Kaldır')->icon(Heroicon::OutlinedArchiveBox)->color('danger')
                    ->visible(fn (AssistantLesson $r) => $r->status === 'approved')
                    ->requiresConfirmation()->modalDescription('Asistan artık bu kurala uymayacak.')
                    ->action(fn (AssistantLesson $r) => self::decide($r, 'retired')),
            ]);
    }

    public static function decide(AssistantLesson $lesson, string $status): void
    {
        $lesson->update(['status' => $status, 'decided_at' => now()]);
        AssistantLesson::forgetCache();
        Notification::make()->title(AssistantLesson::STATUSES[$status] ?? $status)->success()->send();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ManageAssistantLessons::route('/')];
    }
}
