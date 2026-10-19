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

    /** Menü sayısı: bakılması gerekenler — yeni bulunanlar + cevabı gelenler. */
    public static function getNavigationBadge(): ?string
    {
        $n = RecordReview::where('status', 'pending')->count() + RecordReview::answered()->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('firm_name')->label('Firma · kişi · tarih')
                    ->description(fn (RecordReview $r) => trim(($r->user_name ?? '') . ($r->happened_at ? ' · ' . $r->happened_at : '')
                        . ' · #' . $r->id)),
                TextColumn::make('problem')->label('Şüpheli kayıt')->wrap()->limit(260)
                    ->description(fn (RecordReview $r) => $r->fix ? 'Doğrusu büyük ihtimalle: ' . $r->fix : null),
                TextColumn::make('question')->label('Soru → cevap')->wrap()->placeholder('— sorulmadı')
                    ->formatStateUsing(fn ($state) => '🙋 ' . $state)
                    ->description(fn (RecordReview $r) => match (true) {
                        (bool) $r->customer_answer => '💬 ' . $r->customer_answer . ' (' . $r->answered_at?->timezone('Europe/Istanbul')->format('d.m H:i') . ')',
                        (bool) $r->asked_at => 'Soruldu ' . $r->asked_at->timezone('Europe/Istanbul')->format('d.m H:i') . ' — cevap bekleniyor'
                            . ($r->asked_announcement_id ? ' (müşteri yazınca iletilecek)' : ''),
                        default => null,
                    }),
                TextColumn::make('status')->label('Durum')->badge()
                    ->formatStateUsing(fn (string $state, RecordReview $r) => $state === 'asked' && $r->answered_at ? 'Cevap geldi' : (RecordReview::STATUSES[$state] ?? $state))
                    ->color(fn (string $state, RecordReview $r) => match (true) {
                        $state === 'asked' && (bool) $r->answered_at => 'warning',
                        $state === 'pending' => 'warning',
                        $state === 'asked' => 'info',
                        $state === 'resolved' => 'success',
                        default => 'gray',
                    })
                    ->description(fn (RecordReview $r) => $r->decision_note ? '📝 ' . $r->decision_note : null),
                TextColumn::make('created_at')->label('Bulundu')->date('d.m.Y')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('ask')->label('Müşteriye sor')->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)->color('info')
                    ->visible(fn (RecordReview $r) => in_array($r->status, ['pending', 'asked'], true) && $r->hub_firm_id)
                    ->modalHeading('Müşteriye sor')
                    ->modalDescription('Kutudaki metin müşteriye BİREBİR gider (son 24 saatte yazmadıysa bir sonraki mesajında). İstediğin gibi düzenle; cevabı "Cevap geldi"de görünür.')
                    ->modalSubmitActionLabel('Gönder')
                    ->fillForm(fn (RecordReview $r) => ['phone' => $r->phone ?? self::phones($r)->keys()->first(), 'message' => self::draftMessage($r)])
                    ->schema([
                        Select::make('phone')->label('Kime')->options(fn (RecordReview $r) => self::phones($r))->required(),
                        Textarea::make('message')->label('Gidecek mesaj')->required()->rows(7),
                    ])
                    ->action(function (RecordReview $r, array $data) {
                        $text = trim($data['message']);
                        $out = LessonQuestion::deliver($r->firm, $data['phone'], $text, 'record_review');
                        $r->update(['status' => 'asked', 'phone' => $data['phone'], 'question' => $text, 'asked_at' => now(),
                            'asked_announcement_id' => $out['announcement_id'], 'customer_answer' => null, 'answered_at' => null]);
                        Notification::make()->success()->title($out['sent'] ? 'Soru gönderildi; cevabı burada görünecek.'
                            : 'Son 24 saatte yazmadığı için soru bekletiliyor — bir sonraki mesajından sonra iletilecek.')->send();
                    }),
                Action::make('close')->label('Kapat')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (RecordReview $r) => in_array($r->status, ['pending', 'asked'], true))
                    ->modalHeading('Kontrolü kapat')
                    ->modalDescription('Kayda dokunulmaz; yalnız bu kontrol kapanır. Not, olayın nasıl çözüldüğünü sonradan görmek için (asistan da ileride bu notlardan öğrenir).')
                    ->schema([
                        Select::make('status')->label('Sonuç')->options(RecordReview::CLOSED)->required()
                            ->default(fn (RecordReview $r) => $r->customer_answer ? null : 'dismissed'),
                        Textarea::make('decision_note')->label('Not (isteğe bağlı)')->rows(2)
                            ->placeholder('Ör. "Panelden 18.261\'i alışa çevirdim" / "Emre doğru dedi"'),
                    ])
                    ->action(function (RecordReview $r, array $data) {
                        $r->update(['status' => $data['status'], 'decision_note' => trim((string) ($data['decision_note'] ?? '')) ?: null, 'decided_at' => now()]);
                        Notification::make()->title('Kapatıldı: ' . RecordReview::CLOSED[$data['status']])->success()->send();
                    }),
                Action::make('reopen')->label('Yeniden aç')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                    ->visible(fn (RecordReview $r) => array_key_exists($r->status, RecordReview::CLOSED))
                    ->requiresConfirmation()
                    ->action(fn (RecordReview $r) => $r->update(['status' => $r->asked_at ? 'asked' : 'pending', 'decided_at' => null])),
            ]);
    }

    /** Gidecek mesajın taslağı: analizin önerdiği soru (yoksa şüpheli kayıt) + kısa giriş/kapanış — yönetici kutuda düzenler. */
    private static function draftMessage(RecordReview $r): string
    {
        $q = trim((string) $r->question);
        if ($q !== '' && $r->asked_at) {
            return $q; // daha önce gönderilmiş tam metin
        }

        return "Merhaba 👋 Bir kaydınızı kontrol etmek istiyorum:\n\n" . ($q !== '' ? $q : trim((string) $r->problem) . ' Doğru mu?')
            . "\n\nCevabınızı buraya yazmanız yeterli; yanlışsa düzeltmeyi size onaylatırım. 🙏";
    }

    private static function phones(RecordReview $r): \Illuminate\Support\Collection
    {
        return HubPhone::where('hub_firm_id', $r->hub_firm_id)->where('is_active', true)
            ->get()->mapWithKeys(fn ($p) => [$p->phone => trim(($p->name ?: 'İsimsiz') . ' · ' . $p->phone)]);
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
