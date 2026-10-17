<?php

namespace App\Filament\Hub\RecordReviews;

use App\Filament\Hub\RecordReviews\Pages\ManageRecordReviews;
use App\Models\HubPhone;
use App\Models\RecordReview;
use App\Support\LessonQuestion;
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
 * Hub → Kayıt Kontrolleri (Faz 3.1): gece analizi kayda geçmiş ama büyük ihtimalle yanlış kayıtları buraya düşürür (Hazal
 * hatası 13.10 raporda yazıyordu, kimse görmedi). Yönetici "Müşteriye sor" der → soru gerçek adla gider; cevabı burada
 * görünür, düzeltmeyi asistan müşterinin onayıyla yapar. Müşteriye kendiliğinden hiçbir şey gitmez; veriye dokunulmaz.
 */
class RecordReviewResource extends Resource
{
    protected static ?string $model = RecordReview::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlassCircle;

    protected static ?string $navigationLabel = 'Kayıt Kontrolleri';

    protected static ?string $modelLabel = 'Kayıt kontrolü';

    protected static ?string $pluralModelLabel = 'Kayıt Kontrolleri';

    protected static ?int $navigationSort = 6;

    public static function getNavigationBadge(): ?string
    {
        $n = RecordReview::where('status', 'pending')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('status')->label('Durum')->badge()
                    ->formatStateUsing(fn (string $state) => RecordReview::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => ['pending' => 'warning', 'asked' => 'info', 'resolved' => 'success'][$state] ?? 'gray'),
                TextColumn::make('firm_name')->label('Firma')
                    ->description(fn (RecordReview $r) => trim(($r->user_name ?? '') . ($r->happened_at ? ' · ' . $r->happened_at : ''))),
                TextColumn::make('problem')->label('Şüpheli kayıt')->wrap()->limit(260)
                    ->description(fn (RecordReview $r) => $r->fix ? 'Doğrusu büyük ihtimalle: ' . $r->fix : null),
                TextColumn::make('question')->label('Müşteriye soru')->wrap()->placeholder('—'),
                TextColumn::make('customer_answer')->label('Cevap')->wrap()->placeholder('—')
                    ->formatStateUsing(fn ($state) => '💬 ' . $state)
                    ->description(fn (RecordReview $r) => $r->asked_at && ! $r->answered_at
                        ? 'Soruldu ' . $r->asked_at->timezone('Europe/Istanbul')->format('d.m H:i') . ' — cevap bekleniyor'
                            . ($r->asked_announcement_id ? ' (sonraki mesajında iletilecek)' : '') : null),
                TextColumn::make('created_at')->label('Tarih')->date('d.m.Y'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Durum')->options(RecordReview::STATUSES)->default('pending'),
            ])
            ->recordActions([
                Action::make('ask')->label('Müşteriye sor')->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)->color('info')
                    ->visible(fn (RecordReview $r) => in_array($r->status, ['pending', 'asked'], true) && $r->hub_firm_id)
                    ->modalDescription('Soru müşteriye WhatsApp\'tan gider; cevabına göre asistan düzeltmeyi müşterinin onayına sunar.')
                    ->fillForm(fn (RecordReview $r) => ['phone' => $r->phone ?? self::phones($r)->keys()->first(), 'question' => $r->question])
                    ->schema([
                        Select::make('phone')->label('Kime')->options(fn (RecordReview $r) => self::phones($r))->required(),
                        Textarea::make('question')->label('Soru')->required()->rows(3),
                    ])
                    ->action(function (RecordReview $r, array $data) {
                        $text = '🙏 Bir kaydınızı kontrol etmek istiyorum: ' . trim($data['question'])
                            . "\n\nCevabınızı buraya yazmanız yeterli; gerekirse düzeltmeyi size onaylatırım.";
                        $out = LessonQuestion::deliver($r->firm, $data['phone'], $text, 'record_review');
                        $r->update(['status' => 'asked', 'phone' => $data['phone'], 'question' => trim($data['question']), 'asked_at' => now(),
                            'asked_announcement_id' => $out['announcement_id'], 'customer_answer' => null, 'answered_at' => null]);
                        Notification::make()->success()->title($out['sent'] ? 'Soru gönderildi; cevabı burada görünecek.'
                            : 'Son 24 saatte yazmadığı için soru bekletiliyor — bir sonraki mesajından sonra iletilecek.')->send();
                    }),
                Action::make('resolved')->label('Düzeltildi')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (RecordReview $r) => in_array($r->status, ['pending', 'asked'], true))
                    ->action(fn (RecordReview $r) => self::decide($r, 'resolved')),
                Action::make('dismiss')->label('Gerek yok')->icon(Heroicon::OutlinedXMark)->color('gray')
                    ->visible(fn (RecordReview $r) => in_array($r->status, ['pending', 'asked'], true))
                    ->action(fn (RecordReview $r) => self::decide($r, 'dismissed')),
            ]);
    }

    private static function phones(RecordReview $r): \Illuminate\Support\Collection
    {
        return HubPhone::where('hub_firm_id', $r->hub_firm_id)->where('is_active', true)
            ->get()->mapWithKeys(fn ($p) => [$p->phone => trim(($p->name ?: 'İsimsiz') . ' · ' . $p->phone)]);
    }

    private static function decide(RecordReview $r, string $status): void
    {
        $r->update(['status' => $status, 'decided_at' => now()]);
        Notification::make()->title(RecordReview::STATUSES[$status] ?? $status)->success()->send();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ManageRecordReviews::route('/')];
    }
}
