<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Ödeme Hatırlatıcı</x-slot>
        <x-slot name="description">Ödenmemiş borçlar — vadeye göre (çekler ayrı widget'larda)</x-slot>

        @if ($isEmpty)
            <p class="text-sm text-gray-500 dark:text-gray-400">Bekleyen ödeme yok. 🎉</p>
        @else
            @php
                $sections = [
                    'gecikmis' => ['Gecikmiş', 'text-danger-600 dark:text-danger-400'],
                    'bugun'    => ['Bugün Vadesi', 'text-warning-600 dark:text-warning-400'],
                    'yaklasan' => ['Yaklaşan (14 gün)', 'text-info-600 dark:text-info-400'],
                    'vadesiz'  => ['Vadesiz Açık Borçlar', 'text-gray-600 dark:text-gray-400'],
                ];
            @endphp

            <div class="space-y-6">
                @foreach ($sections as $key => $meta)
                    @php
                        [$label, $labelClass] = $meta;
                        $items = $buckets[$key];
                    @endphp

                    @if (count($items))
                        <div>
                            <h3 class="mb-2 text-sm font-semibold {{ $labelClass }}">
                                {{ $label }}
                                <span class="font-normal text-gray-400">({{ count($items) }})</span>
                            </h3>

                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="text-xs text-gray-500 dark:text-gray-400">
                                        <tr class="border-b border-gray-200 dark:border-white/10">
                                            <th class="py-1 pr-3 text-left font-medium">Vade</th>
                                            <th class="py-1 pr-3 text-left font-medium">Tür</th>
                                            <th class="py-1 pr-3 text-left font-medium">Açıklama</th>
                                            <th class="py-1 text-right font-medium">Tutar</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                        @foreach ($items as $row)
                                            @php
                                                $vade = match ($key) {
                                                    'gecikmis' => \Illuminate\Support\Carbon::parse($row['due'])->format('d.m.Y') . ' · ' . ($row['days'] ?? 0) . ' gün gecikti',
                                                    'bugun'    => 'Bugün',
                                                    'yaklasan' => \Illuminate\Support\Carbon::parse($row['due'])->format('d.m.Y') . ' · ' . ($row['days'] ?? 0) . ' gün kaldı',
                                                    default    => '—',
                                                };
                                            @endphp
                                            <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                                <td class="whitespace-nowrap py-2 pr-3 text-gray-600 dark:text-gray-300">{{ $vade }}</td>
                                                <td class="whitespace-nowrap py-2 pr-3">
                                                    <span class="rounded px-1.5 py-0.5 text-xs {{ $row['type'] === 'Gider' ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400' : 'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-400' }}">
                                                        {{ $row['type'] }}
                                                    </span>
                                                </td>
                                                <td class="py-2 pr-3">
                                                    <a href="{{ $row['url'] }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                                        {{ $row['title'] }}
                                                    </a>
                                                    @if ($row['party'] || $row['project'])
                                                        <div class="text-xs text-gray-400">
                                                            {{ collect([$row['party'], $row['project']])->filter()->implode(' · ') }}
                                                        </div>
                                                    @endif
                                                </td>
                                                <td class="whitespace-nowrap py-2 text-right font-medium tabular-nums">
                                                    {{ \App\Support\Money::format($row['amount']) }} ₺
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
