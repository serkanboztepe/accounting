<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use Filament\Widgets\Widget;

/**
 * Dashboard üstü zengin stat tile satırı (mockup tasarımı) — kendi scoped CSS'iyle,
 * global Filament temasına dokunmadan. Para rakamları PurchaseOverviewCard ile
 * AYNI hesaptan gelir (tutarlılık): nakit-açık + bekleyen çek sözleşme bazında.
 */
class DashboardStatTiles extends Widget
{
    protected string $view = 'filament.widgets.dashboard-stat-tiles';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -1;

    public function getData(): array
    {
        $overview = (new PurchaseOverviewCard())->getData();

        return [
            'active_projects' => $overview['active_projects'] ?? 0,
            'contracts_total' => $overview['contracts_total'] ?? 0,
            'cash_due'        => $overview['cash_due'] ?? 0,
            'pending_checks'  => $overview['pending_checks'] ?? 0,
            'overdue_count'   => $overview['overdue_count'] ?? 0,
            'project_names'   => Project::query()->where('status', 'active')
                ->orderBy('name')->limit(3)->pluck('name')->implode(' · '),
        ];
    }
}
