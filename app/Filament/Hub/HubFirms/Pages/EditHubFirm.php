<?php

namespace App\Filament\Hub\HubFirms\Pages;

use App\Filament\Hub\HubFirms\HubFirmResource;
use App\Models\HubFirm;
use App\Models\WhatsappMessage;
use App\Support\Phone;
use App\Tenancy\Tenancy;
use Filament\Actions\Action;
use App\Filament\Hub\HubFirms\FeatureFields;
use App\Support\FeatureSettings;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Filament\Support\Icons\Heroicon;

/** Firma sayfası: gövde = numaralar; firma bilgileri ⚙ Ayarlar modalında. */
class EditHubFirm extends EditRecord
{
    protected static string $resource = HubFirmResource::class;

    public function getTitle(): string
    {
        return $this->record->name;
    }

    public function getBreadcrumbs(): array
    {
        return [HubFirmResource::getUrl('index') => 'Firmalar', $this->record->name];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('copySecret')
                ->label('Gizli anahtar')
                ->icon(Heroicon::OutlinedKey)
                ->color('gray')
                ->modalHeading('Gizli anahtar (HUB_SECRET)')
                ->modalDescription('Bu anahtar firmanın kurulumundaki .env dosyasında HUB_SECRET satırında olmalı.')
                ->visible(fn () => ! $this->record->isLocal())
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Kapat')
                ->schema([
                    \Filament\Forms\Components\TextInput::make('secret')
                        ->label('Anahtar')
                        ->default(fn () => $this->record->secret)
                        ->readOnly()
                        ->copyable(copyMessage: 'Kopyalandı'),
                ]),
            Action::make('settings')
                ->label('Ayarlar')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->modalHeading('Firma Bilgileri')
                ->modalSubmitActionLabel('Kaydet')
                ->fillForm(fn (): array => $this->record->only(['name', 'url', 'is_active']))
                ->schema(fn () => HubFirmResource::components(record: $this->record))
                ->action(function (array $data) {
                    $this->record->update($data);
                    Notification::make()->success()->title('Kaydedildi')->send();
                }),
            $this->unclearMessagesAction(),
            $this->modulesAction(),
            DeleteAction::make()
                ->modalDescription(fn () => $this->record->isLocal()
                    ? "Firma hub'dan kaldırılır; veritabanı ({$this->record->database}) SİLİNMEZ, sunucuda kalır."
                    : null),
        ];
    }

    /**
     * Asistanın anlayamadığı / takıldığı mesajlar (firmanın konuşma kaydından, son 30 gün).
     * Ürünü geliştirmek için: Arda vakası ancak Twilio geçmişi kazınınca görülebilmişti.
     */
    private function unclearMessagesAction(): Action
    {
        return Action::make('unclearMessages')
            ->label('Anlaşılamayanlar')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->color('gray')
            ->visible(fn () => $this->record->isLocal())
            ->modalHeading('Asistanın anlayamadığı mesajlar (son 30 gün)')
            ->modalDescription('Yardım cevabı verilen, hata alan ya da taslak yokken onay/sayı yazılan mesajlar ve verilen cevap.')
            ->modalWidth('3xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Kapat')
            ->modalContent(fn () => view('filament.hub.firm-unclear-messages', ['rows' => $this->unclearMessages()]));
    }

    /** @return list<array{at:string,who:string,body:string,kind:string,reply:?string}> */
    private function unclearMessages(): array
    {
        $names = $this->record->phones()->pluck('name', 'phone');

        return Tenancy::run($this->record, function () use ($names) {
            return WhatsappMessage::query()
                ->where('direction', 'in')
                ->whereIn('kind', WhatsappMessage::UNCLEAR_KINDS)
                ->where('created_at', '>=', now()->subDays(30))
                ->latest('id')
                ->take(50)
                ->get()
                ->map(fn (WhatsappMessage $m) => [
                    'at' => $m->created_at->format('d.m H:i'),
                    'who' => $names[$m->phone] ?? Phone::display($m->phone),
                    'body' => (string) $m->body,
                    'kind' => $m->kind,
                    // Bu mesaja verilen cevap: aynı telefondan sonraki ilk giden kayıt.
                    'reply' => WhatsappMessage::where('phone', $m->phone)->where('direction', 'out')
                        ->where('id', '>', $m->id)->orderBy('id')->value('body'),
                ])
                ->all();
        });
    }

    /**
     * Tek panel: firmanın özellikleri + ayarları (plan 26.10: Çekirdek + 7 kutu, firma başına saklı liste).
     * Henüz yeni düzene çevrilmemiş firmada pencere bugünkü durumu gösterir; kaydedince firma çevrilmiş olur.
     */
    private function modulesAction(): Action
    {
        return Action::make('modules')
            ->label('Özellikler')
            ->icon(Heroicon::OutlinedSquares2x2)
            ->color('gray')
            ->visible(fn () => $this->record->isLocal())
            ->modalHeading('Özellikler ve ayarlar')
            ->modalDescription(fn () => FeatureSettings::converted($this->record->settingsWithDefaults()) ? null
                : 'Bu firma henüz yeni düzene çevrilmedi. Kaydedince çevrilir (çekler açılır; Stok açıksa Ay Sonucu da gelir).')
            ->modalSubmitActionLabel('Kaydet')
            ->fillForm(fn () => $this->record->featuresFormData())
            ->schema(fn () => [
                ...FeatureFields::schema(),
                Section::make('Cari kilidi')
                    ->columns(2)
                    ->schema([
                        Toggle::make('cari_lock')->label('Cariler şifre/PIN ile kilitli'),
                        TextInput::make('cari_lock_minutes')->label('Kaç dk işlemsizlikte kilitlensin')->numeric()->minValue(1)->default(5),
                    ]),
                TextInput::make('check_reminder_days')
                    ->label('Çek hatırlatması: vadeden kaç gün önce')
                    ->helperText('Virgülle: 3,0 = 3 gün önce ve vade günü. Hatırlatmayı alacak numaralar "Numaralar"da işaretlenir.')
                    ->regex('/^\s*\d+(\s*,\s*\d+)*\s*$/'),
            ])
            ->action(function (array $data) {
                $this->record->saveFeaturesForm($data);

                Notification::make()->success()->title('Kaydedildi')->body('Firma bir sonraki sayfa açılışında yeni ayarlarla çalışır.')->send();
            });
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.hub.firm-usage')->viewData(fn () => $this->usageData()),
            $this->getRelationManagersContentComponent(),
        ]);
    }

    private function usageData(): array
    {
        return \App\Support\HubCosts::firmMonth($this->record);
    }
}
