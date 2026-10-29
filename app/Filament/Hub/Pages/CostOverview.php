<?php

namespace App\Filament\Hub\Pages;

use App\Models\AiCredit;
use App\Models\HubLedgerEntry;
use App\Support\HubCosts;
use App\Support\HubReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Rapor (29.10, eski "Toplu Maliyet"): üstte bakiyeler (Anthropic tahmini, Twilio gerçek) + kredi yükleme; altta ay ay
 * gelir / gider / kâr — aya tıklayınca firma firma ayrıntı. Sabit giderler buradan girilir. Hesap: HubReport.
 */
class CostOverview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Rapor';

    protected static ?string $title = 'Rapor';

    protected static ?string $slug = 'rapor';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.hub.pages.cost-overview';

    /** Seçili ay ("2026-11"); boş = ay listesi. */
    #[Url]
    public ?string $ay = null;

    public function getTitle(): string
    {
        return $this->month() ? HubReport::month($this->month())['label'] . ' — ayrıntı' : 'Rapor';
    }

    private function month(): ?string
    {
        return $this->ay !== null && in_array($this->ay, HubReport::months(), true) ? $this->ay : null;
    }

    public function anthropicTopupAction(): Action
    {
        return $this->topupAction('anthropicTopup', 'anthropic', 'Anthropic kredisi yükledim');
    }

    public function twilioTopupAction(): Action
    {
        return $this->topupAction('twilioTopup', 'twilio', 'Twilio kredisi yükledim');
    }

    private function topupAction(string $name, string $provider, string $heading): Action
    {
        return Action::make($name)
            ->label('Kredi yükledim')
            ->link()->size('sm')->icon(Heroicon::OutlinedPlusCircle)
            ->modalHeading($heading)
            ->modalDescription('Yüklenen para gider sayılmaz (harcama zaten firma satırlarında) — bakiye ve "bu ay yüklenen" bilgisi için.')
            ->schema([
                TextInput::make('amount')->label('Tutar ($)')->numeric()->minValue(0.01)->required(),
                DatePicker::make('date')->label('Tarih')->default(now('Europe/Istanbul')->toDateString())->required(),
                TextInput::make('note')->label('Not')->placeholder('ör. kartla'),
            ])
            ->action(function (array $data) use ($provider) {
                HubLedgerEntry::create(['kind' => HubLedgerEntry::TOPUP, 'provider' => $provider, 'amount' => (float) $data['amount'],
                    'currency' => 'USD', 'starts_on' => $data['date'], 'note' => $data['note'] ?? null]);
                Notification::make()->title('Yükleme kaydedildi')->success()->send();
            });
    }

    /** Eski "Güncelle": Anthropic Billing'deki gerçek bakiye — tahmin kaydıysa düzeltir, hesap oradan yeniden başlar. */
    public function creditAction(): Action
    {
        return Action::make('credit')
            ->label('Bakiye düzelt')
            ->link()->size('sm')->color('gray')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalDescription('console.anthropic.com → Settings → Billing sayfasındaki güncel bakiyeyi yaz. Tahmin buradan yeniden başlar (ayda bir yeter).')
            ->schema([
                TextInput::make('balance_usd')->label('Bakiye ($)')->numeric()->minValue(0)->required(),
                TextInput::make('note')->label('Not'),
            ])
            ->action(function (array $data) {
                AiCredit::create(['balance_usd' => (float) $data['balance_usd'], 'note' => $data['note'] ?? null]);
                Notification::make()->title('Bakiye kaydedildi')->success()->send();
            });
    }

    public function fixedCostsAction(): Action
    {
        return Action::make('fixedCosts')
            ->label('Sabit giderler')
            ->icon(Heroicon::OutlinedServerStack)
            ->color('gray')
            ->modalHeading('Sabit giderler')
            ->modalDescription('Her ay aktif firmalara eşit bölünür; yıllık tutar 12\'ye bölünür. Fiyat değişince eskisine bitiş, yenisine başlangıç yaz — eski aylar bozulmaz.')
            ->modalWidth('5xl')
            ->fillForm(fn () => ['rows' => HubLedgerEntry::where('kind', HubLedgerEntry::FIXED)->orderBy('starts_on')->get()
                ->map(fn (HubLedgerEntry $e) => ['id' => $e->id, 'title' => $e->title, 'amount' => $e->amount, 'currency' => $e->currency,
                    'period' => $e->period, 'starts_on' => $e->starts_on?->toDateString(), 'ends_on' => $e->ends_on?->toDateString()])->all()])
            ->schema([
                Repeater::make('rows')->hiddenLabel()->addActionLabel('Gider ekle')->defaultItems(0)->columns(6)->schema([
                    Hidden::make('id'),
                    TextInput::make('title')->label('Ne')->placeholder('Sunucu (Hetzner)')->required()->columnSpan(2),
                    TextInput::make('amount')->label('Tutar')->numeric()->minValue(0)->required(),
                    Select::make('currency')->label('Para')->options(HubLedgerEntry::CURRENCIES)->default('TRY')->required()->native(false),
                    Select::make('period')->label('Sıklık')->options(['monthly' => 'Aylık', 'yearly' => 'Yıllık'])->default('monthly')->required()->native(false),
                    DatePicker::make('starts_on')->label('Başlangıç')->default(now()->startOfMonth()->toDateString())->required(),
                    DatePicker::make('ends_on')->label('Bitiş')->afterOrEqual('starts_on')->columnStart(6),
                ]),
            ])
            ->action(function (array $data) {
                $keep = [];
                foreach ($data['rows'] ?? [] as $row) {
                    $values = ['kind' => HubLedgerEntry::FIXED, 'title' => trim($row['title']), 'amount' => (float) $row['amount'],
                        'currency' => $row['currency'], 'period' => $row['period'], 'starts_on' => $row['starts_on'], 'ends_on' => $row['ends_on'] ?: null];
                    $entry = ! empty($row['id']) ? HubLedgerEntry::where('kind', HubLedgerEntry::FIXED)->find($row['id']) : null;
                    $entry ? $entry->update($values) : $entry = HubLedgerEntry::create($values);
                    $keep[] = $entry->id;
                }
                HubLedgerEntry::where('kind', HubLedgerEntry::FIXED)->whereNotIn('id', $keep)->get()->each->delete();
                Notification::make()->title('Sabit giderler kaydedildi')->success()->send();
            });
    }

    protected function getViewData(): array
    {
        $month = $this->month();

        return [
            'credit' => HubCosts::credit(),
            'twilio' => HubCosts::twilioBalance(),
            'detail' => $month ? HubReport::month($month) : null,
            'summary' => $month ? [] : HubReport::summary(),
            'start' => HubReport::START,
        ];
    }
}
