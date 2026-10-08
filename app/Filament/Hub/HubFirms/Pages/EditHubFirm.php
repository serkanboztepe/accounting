<?php

namespace App\Filament\Hub\HubFirms\Pages;

use App\Filament\Hub\HubFirms\HubFirmResource;
use App\Models\HubFirm;
use Filament\Actions\Action;
use App\Support\ModuleProfiles;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
            $this->modulesAction(),
            DeleteAction::make()
                ->modalDescription(fn () => $this->record->isLocal()
                    ? "Firma hub'dan kaldırılır; veritabanı ({$this->record->database}) SİLİNMEZ, sunucuda kalır."
                    : null),
        ];
    }

    /**
     * Tek panel: firmanın modülleri + ayarları (eskiden .env: APP_PROFILE, MOD_*, CARI_LOCK,
     * CHECK_REMINDER_DAYS). Modül: "Sektöre göre" = profilin varsayılanı, ya da açık/kapalı zorla.
     */
    private function modulesAction(): Action
    {
        $choice = ['' => 'Sektöre göre', '1' => 'Açık', '0' => 'Kapalı'];

        return Action::make('modules')
            ->label('Modüller')
            ->icon(Heroicon::OutlinedSquares2x2)
            ->color('gray')
            ->visible(fn () => $this->record->isLocal())
            ->modalHeading('Modüller ve ayarlar')
            ->modalSubmitActionLabel('Kaydet')
            ->fillForm(function () {
                $s = $this->record->settingsWithDefaults();
                $data = [
                    'profile'             => $s['profile'],
                    'cari_lock'           => $s['cari_lock'],
                    'cari_lock_minutes'   => $s['cari_lock_minutes'],
                    'check_reminder_days' => implode(',', $s['check_reminder_days']),
                ];
                foreach (ModuleProfiles::KEYS as $key) {
                    $data["mod_{$key}"] = array_key_exists($key, $s['modules']) ? ($s['modules'][$key] ? '1' : '0') : '';
                }

                return $data;
            })
            ->schema(fn () => [
                Select::make('profile')->label('Sektör')->options(HubFirmResource::PROFILE_OPTIONS)
                    ->placeholder('Hepsi açık')->live(),
                Section::make('Modüller')
                    ->description('"Sektöre göre" seçili olan, yukarıdaki sektörün varsayılanını kullanır.')
                    ->columns(2)
                    ->schema(array_map(
                        fn (string $key) => Select::make("mod_{$key}")
                            ->label(ModuleProfiles::LABELS[$key] ?? $key)
                            ->options($choice)
                            ->selectablePlaceholder(false)
                            ->default('')
                            ->hint(fn (Get $get) => (ModuleProfiles::defaults($get('profile'))[$key] ?? true) ? 'sektörde açık' : 'sektörde kapalı'),
                        ModuleProfiles::KEYS,
                    )),
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
                $this->record->saveSettingsForm($data);

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

    /** Bu ay: hub'daki mesaj sayıları + firmadan çekilen AI/şablon maliyeti (5 dk önbellek). */
    private function usageData(): array
    {
        $month = now()->format('Y-m');
        $logs = $this->record->messageLogs()->where('created_at', '>=', now()->startOfMonth());
        $in = (clone $logs)->where('direction', 'in')->count();
        $out = (clone $logs)->where('direction', 'out')->count();

        $usage = Cache::remember("hub-usage:{$this->record->id}:{$month}", 300, fn () => $this->record->fetchUsage($month));

        $templates = (int) ($usage['templates'] ?? 0);
        $waCost = ($in + $out) * config('costs.twilio_per_message') + (float) ($usage['template_cost_usd'] ?? 0);

        // Numara kirası tüm firmalara ortak → aktif firma sayısına bölünür.
        $activeFirms = max(1, HubFirm::where('is_active', true)->count());
        $numberShare = config('costs.twilio_number_monthly') / $activeFirms;

        return [
            'monthLabel' => now()->locale('tr')->translatedFormat('F Y'),
            'in' => $in,
            'out' => $out,
            'templates' => $templates,
            'usage' => $usage,
            'waCost' => $waCost,
            'numberShare' => $numberShare,
            'activeFirms' => $activeFirms,
            'total' => $waCost + $numberShare + (float) ($usage['ai_cost_usd'] ?? 0),
        ];
    }
}
