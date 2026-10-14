<?php

namespace App\Filament\Hub\Pages;

use App\Models\AiCredit;
use App\Support\HubCosts;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Toplu maliyet: tüm firmalar + sabah analizi + yerel denemeler (aynı Anthropic anahtarı) — bu ay. */
class CostOverview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Toplu Maliyet';

    protected static ?string $title = 'Toplu Maliyet';

    protected static ?string $slug = 'maliyet';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.hub.pages.cost-overview';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('credit')
                ->label('Bakiye gir')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->modalDescription('console.anthropic.com → Settings → Billing sayfasındaki güncel bakiyeyi yaz. Bundan sonraki harcama bundan düşülür.')
                ->schema([
                    TextInput::make('balance_usd')->label('Bakiye ($)')->numeric()->minValue(0)->required(),
                    TextInput::make('note')->label('Not')->placeholder('ör. 20 $ yüklendi'),
                ])
                ->action(function (array $data) {
                    AiCredit::create(['balance_usd' => (float) $data['balance_usd'], 'note' => $data['note'] ?? null]);
                    Notification::make()->title('Bakiye kaydedildi')->success()->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        return HubCosts::overview();
    }
}
