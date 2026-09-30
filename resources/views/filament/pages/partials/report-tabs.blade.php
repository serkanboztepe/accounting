@php
    // Etkin modüllere göre rapor sekmeleri — tek "Raporlar" bölümü hissi.
    $reportTabs = array_values(array_filter([
        config('modules.report_project')
            ? ['key' => 'project', 'label' => 'Proje Raporları', 'url' => \App\Filament\Pages\ProjectReports::getUrl()]
            : null,
        (config('modules.stock') && config('modules.report_stock'))
            ? ['key' => 'stock', 'label' => 'Stok Raporu', 'url' => \App\Filament\Pages\StockReport::getUrl()]
            : null,
        (config('modules.direct_sales') && config('modules.report_sales'))
            ? ['key' => 'sales', 'label' => 'Satış Özeti', 'url' => \App\Filament\Pages\SalesSummary::getUrl()]
            : null,
    ]));
@endphp

@if (count($reportTabs) > 1)
    <div class="flex gap-1 border-b border-gray-200 dark:border-white/10 mb-6 overflow-x-auto">
        @foreach ($reportTabs as $tab)
            <a href="{{ $tab['url'] }}" wire:navigate @class([
                'whitespace-nowrap px-4 py-2.5 text-sm font-semibold -mb-px border-b-2 transition',
                'text-primary-600 border-primary-600' => ($active ?? '') === $tab['key'],
                'text-gray-500 dark:text-gray-400 border-transparent hover:text-gray-700 dark:hover:text-gray-200' => ($active ?? '') !== $tab['key'],
            ])>
                {{ $tab['label'] }}
            </a>
        @endforeach
    </div>
@endif
