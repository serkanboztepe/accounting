<?php

namespace App\Filament\Hub\HubFirms\Pages;

use App\Filament\Hub\HubFirms\HubFirmResource;
use App\Models\HubFirm;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
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
                ->schema(HubFirmResource::components())
                ->action(function (array $data) {
                    $this->record->update($data);
                    Notification::make()->success()->title('Kaydedildi')->send();
                }),
            DeleteAction::make(),
        ];
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
