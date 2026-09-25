<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\MonthlyCheckPaymentsChart;
use App\Filament\Widgets\OverdueChecksTable;
use App\Filament\Widgets\ProjectComparisonTable;
use App\Filament\Widgets\PurchaseOverviewCard;
use App\Filament\Widgets\ReceivablesTable;
use App\Filament\Widgets\SalesReceivablesOverview;
use App\Filament\Widgets\TopCustomersTable;
use App\Filament\Widgets\TopSubcontractorsTable;
use App\Filament\Widgets\TopSuppliersTable;
use App\Filament\Widgets\UnpaidContractBalancesTable;
use App\Filament\Widgets\UpcomingChecksTable;
use App\Filament\Widgets\UpcomingCollectionsTable;
use App\Models\Contract;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static string|\UnitEnum|null $navigationGroup = 'Genel';

    protected static ?int $navigationSort = 1;

    /**
     * Dashboard modülü kapalıysa navigasyonda gizle — ama route'u 403'lemeyiz
     * (kök /admin buraya gelir). Erişimi mount()'ta Cariler'e yönlendirerek
     * çözüyoruz, böylece giriş sonrası kökte 403 alınmaz.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return config('modules.dashboard');
    }

    public function mount(): void
    {
        if (! config('modules.dashboard')) {
            $this->redirect(\App\Filament\Resources\Parties\PartyResource::getUrl('index'));

            return;
        }

        if (method_exists(get_parent_class($this), 'mount')) {
            parent::mount();
        }
    }

    public function getTitle(): string
    {
        return 'Genel Rapor';
    }

    /**
     * Alım/Satış seçici — yalnız satış sözleşmesi varsa görünür.
     * Satış yoksa hiç filtre çıkmaz, sade Alım Raporu kalır.
     */
    public function filtersForm(Schema $schema): Schema
    {
        if (! $this->salesExist()) {
            return $schema->components([]);
        }

        return $schema->components([
            Select::make('report')
                ->label('Rapor')
                ->options([
                    'alim'  => 'Alım Raporu',
                    'satis' => 'Satış Raporu',
                ])
                ->default('alim')
                ->selectablePlaceholder(false)
                ->native(false)
                ->columnSpan(1),
        ]);
    }

    /**
     * Seçili rapora göre widget seti. Header widget kullanılmaz (içerik gridine taşındı).
     */
    public function getWidgets(): array
    {
        $report = $this->filters['report'] ?? 'alim';

        if ($report === 'satis' && $this->salesExist()) {
            return [
                SalesReceivablesOverview::class,
                ReceivablesTable::class,
                UpcomingCollectionsTable::class,
                TopCustomersTable::class,
            ];
        }

        return [
            PurchaseOverviewCard::class,
            UnpaidContractBalancesTable::class,
            MonthlyCheckPaymentsChart::class,
            ProjectComparisonTable::class,
            TopSuppliersTable::class,
            TopSubcontractorsTable::class,
            UpcomingChecksTable::class,
            OverdueChecksTable::class,
        ];
    }

    public function getHeaderWidgets(): array
    {
        return [];
    }

    protected function salesExist(): bool
    {
        return Contract::withoutGlobalScope('purchase')
            ->where('direction', Contract::DIRECTION_SALE)
            ->whereIn('status', ['active', 'completed'])
            ->exists();
    }
}
