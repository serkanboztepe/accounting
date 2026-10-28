<x-filament-panels::page>
    @php
        $months = $this->getMonths();
        $current = $months[0];
        $fmt = fn ($v) => '₺' . \App\Support\Money::format($v);
        // Personel (maaş) yalnız personel işaretli carisi olan firmada görünür.
        $hasPersonnel = collect($months)->contains(fn ($m) => ($m['personnel'] ?? 0) > 0);
        $cards = array_values(array_filter([['Ciro', $current['revenue']], ['Alış', $current['purchases']],
            $hasPersonnel ? ['Personel', $current['personnel']] : null, ['Gider', $current['expenses']],
            [$current['result'] >= 0 ? 'Kalan' : 'Eksi', $current['result']]]));
    @endphp

    <x-filament::section :heading="$current['label'] . ' (bu ay)'" :description="'Yaklaşık: ciro − alış − ' . ($hasPersonnel ? 'personel − ' : '') . 'gider. Ödeme ve tahsilatlar sayılmaz; stokta kalan mal düşülmez.'">
        <div class="grid gap-4 {{ $hasPersonnel ? 'sm:grid-cols-5' : 'sm:grid-cols-4' }}">
            @foreach ($cards as [$title, $value])
                <div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $title }}</div>
                    <div class="text-xl font-semibold tabular-nums {{ $loop->last ? ($value >= 0 ? 'text-success-600' : 'text-danger-600') : 'text-gray-950 dark:text-white' }}">{{ $fmt(abs($value)) }}</div>
                </div>
            @endforeach
        </div>
        <div class="mt-4 grid gap-1 text-sm text-gray-600 dark:text-gray-400 sm:grid-cols-2">
            <div>Satışlar: {{ $fmt($current['sales_docs']) }}@if ($current['sales_ledger'] > 0) · basit satış: {{ $fmt($current['sales_ledger']) }}@endif @if ($current['returns'] > 0) · iade: −{{ $fmt($current['returns']) }}@endif</div>
            <div>Peşin satışların nakit: {{ $fmt($current['cash']) }} · kart: {{ $fmt($current['bank']) }}</div>
            <div>Alışlar: {{ $fmt($current['purchase_docs']) }}@if ($current['purchase_ledger'] > 0) · basit alış: {{ $fmt($current['purchase_ledger']) }}@endif @if ($current['goods_in'] > 0) · mal girişi: {{ $fmt($current['goods_in']) }}@endif</div>
            @if ($current['unpriced_goods'] > 0)
                <div class="text-warning-600">⚠ Fiyatsız {{ $current['unpriced_goods'] }} mal girişi 0 sayıldı.</div>
            @endif
        </div>
    </x-filament::section>

    <x-filament::section heading="Son 12 ay">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3 text-left font-medium">Ay</th>
                        <th class="px-3 py-2 text-right font-medium">Ciro</th>
                        <th class="px-3 py-2 text-right font-medium">Nakit / Kart</th>
                        <th class="px-3 py-2 text-right font-medium">Alış</th>
                        @if ($hasPersonnel)
                            <th class="px-3 py-2 text-right font-medium">Personel</th>
                        @endif
                        <th class="px-3 py-2 text-right font-medium">Gider</th>
                        <th class="py-2 pl-3 text-right font-medium">Sonuç</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($months as $m)
                        <tr>
                            <td class="py-2 pr-3 whitespace-nowrap text-gray-950 dark:text-white">{{ $m['label'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($m['revenue']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-gray-500">{{ \App\Support\Money::format($m['cash']) }} / {{ \App\Support\Money::format($m['bank']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($m['purchases']) }}</td>
                            @if ($hasPersonnel)
                                <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($m['personnel']) }}</td>
                            @endif
                            <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($m['expenses']) }}</td>
                            <td class="py-2 pl-3 text-right font-medium tabular-nums {{ $m['result'] >= 0 ? 'text-success-600' : 'text-danger-600' }}">{{ $m['result'] < 0 ? '−' : '' }}{{ $fmt(abs($m['result'])) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
