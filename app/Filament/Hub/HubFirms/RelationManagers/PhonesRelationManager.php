<?php

namespace App\Filament\Hub\HubFirms\RelationManagers;

use App\Models\HubPhone;
use App\Support\Phone;
use App\Support\WelcomeMessage;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/** Firmanın WhatsApp numaraları — bu numaralardan gelen mesajlar bu firmaya gider. */
class PhonesRelationManager extends RelationManager
{
    protected static string $relationship = 'phones';

    protected static ?string $title = 'Numaralar';

    protected static ?string $modelLabel = 'Numara';

    public function form(Schema $schema): Schema
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
                    $other = HubPhone::with('firm')->where('phone', $normalized)
                        ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                        ->first();
                    if ($other) {
                        $fail("Bu numara zaten kayıtlı: {$other->firm?->name}. Bir numara tek firmaya bağlanır.");
                    }
                }),
            TextInput::make('name')->label('Ad Soyad')->placeholder('Ferhat Yıldız')->maxLength(255),
            Toggle::make('is_active')->label('Aktif')->default(true),
            Toggle::make('receives_reminders')->label('Sabah hatırlatmaları alsın (çek vadesi, ödeme günleri)')
                ->helperText('Firmada hiç işaretli numara yoksa ödeme günleri tüm numaralara gider.')
                ->visible(fn () => $this->getOwnerRecord()->isLocal()),
            Toggle::make('send_welcome')->label('Hoş geldin mesajı gönder')
                ->helperText('WhatsApp\'tan "hesabın hazır, nasıl yaz" mesajı gider.')
                ->default(true)
                ->visibleOn('create')
                ->visible(fn () => WelcomeMessage::configured()),
        ])->columns(1);
    }

    private bool $sendWelcomeAfterCreate = false;

    /** Onaylı değilse gönderme (teslim edilmez) ve bunu açıkça söyle; "gönderildi" = Twilio'ya iletildi. */
    private function sendWelcome(HubPhone $record): void
    {
        $status = WelcomeMessage::approvalStatus();
        if ($status !== 'approved') {
            Notification::make()->warning()
                ->title('Hoş geldin mesajı gönderilmedi')
                ->body($status === 'rejected'
                    ? 'Şablon Meta tarafından reddedildi.'
                    : 'Şablon henüz Meta onayında — onay gelince bu numarada "Hoş geldin gönder"e bas.')
                ->persistent()
                ->send();

            return;
        }

        WelcomeMessage::send($record)
            ? Notification::make()->success()->title('Hoş geldin mesajı gönderildi')->body('Birkaç saniye içinde WhatsApp\'ına düşer.')->send()
            : Notification::make()->danger()->title('Hoş geldin mesajı gönderilemedi')->body('Ayrıntı sunucu kaydında.')->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('phone')
            ->columns([
                TextColumn::make('phone')->label('Numara')->searchable()
                    ->formatStateUsing(fn (string $state) => Phone::display($state)),
                TextColumn::make('name')->label('Ad Soyad')->searchable(),
                ToggleColumn::make('is_active')->label('Aktif'),
                ToggleColumn::make('receives_reminders')->label('Sabah hatırlatması')
                    ->visible(fn () => $this->getOwnerRecord()->isLocal()),
                TextColumn::make('welcomed_at')->label('Hoş geldin')->since()->placeholder('—')
                    ->tooltip(fn (HubPhone $record) => $record->welcomed_at?->format('d.m.Y H:i'))
                    ->visible(fn () => WelcomeMessage::configured()),
            ])
            ->emptyStateHeading('Henüz numara yok')
            ->emptyStateDescription('Bu firmadan asistana yazacak kişilerin WhatsApp numaralarını ekle.')
            ->headerActions([
                CreateAction::make()->label('Numara Ekle')
                    // send_welcome kolon değil: kayıttan önce ayır, kayıttan sonra gönder.
                    ->mutateDataUsing(function (array $data): array {
                        $this->sendWelcomeAfterCreate = (bool) ($data['send_welcome'] ?? false);
                        unset($data['send_welcome']);

                        return $data;
                    })
                    ->after(function (HubPhone $record) {
                        if ($this->sendWelcomeAfterCreate && WelcomeMessage::configured()) {
                            $this->sendWelcome($record);
                        }
                    }),
            ])
            ->recordActions([
                Action::make('welcome')
                    ->label('Hoş geldin gönder')
                    ->icon(Heroicon::OutlinedHandRaised)
                    ->color('gray')
                    ->visible(fn (HubPhone $record) => WelcomeMessage::canSendTo($record))
                    ->requiresConfirmation()
                    ->modalHeading('Hoş geldin mesajı gönderilsin mi?')
                    ->modalDescription(fn (HubPhone $record) => ($record->name ?: Phone::display($record->phone)) . ' numarasına "hesabın hazır, nasıl yaz" mesajı gider.')
                    ->action(fn (HubPhone $record) => $this->sendWelcome($record)),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
