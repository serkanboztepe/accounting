<?php

namespace App\Filament\Widgets;

use App\Models\Check;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class MonthlyCheckPaymentsChart extends ChartWidget
{
    protected ?string $heading = 'Ay Ay Ödenecek Çek';

    protected ?string $description = 'Ödenmemiş (bekleyen) çeklerin vade ayına göre toplam tutarı';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '220px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $today = Carbon::today();

        $rows = Check::query()
            ->where('status', 'issued')
            ->whereNotNull('due_date')
            ->selectRaw("to_char(due_date, 'YYYY-MM') as period, SUM(amount) as total_amount")
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        $labels = [];
        $values = [];
        $colors = [];

        foreach ($rows as $row) {
            $date     = Carbon::createFromFormat('Y-m', $row->period);
            $labels[] = $date->locale('tr')->isoFormat('MMM YYYY');
            $values[] = (float) $row->total_amount;
            // Vadesi geçmiş aylar kırmızı, gelecek aylar amber (panel primary).
            $colors[] = $date->endOfMonth()->lt($today) ? '#ef4444' : '#f59e0b';
        }

        return [
            'datasets' => [
                [
                    'label'           => 'Ödenecek Çek (₺)',
                    'data'            => $values,
                    'backgroundColor' => $colors,
                    'borderRadius'    => 6,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => ['display' => false],
            ],
        ];
    }
}
