<x-filament-widgets::widget>
    <x-filament::section heading="Yaklaşan Tahsilatlar">
        @php($rows = $this->getRows())
        @if (empty($rows))
            <div class="rounded-xl border border-dashed border-gray-300 px-4 py-6 text-center text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
                Planlanmış yaklaşan tahsilat yok.
            </div>
        @else
            <div class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($rows as $row)
                    <div class="flex items-center gap-3 py-2.5">
                        <span class="w-20 shrink-0 text-xs font-medium text-gray-500 dark:text-gray-400">{{ $row['date']->format('d.m.Y') }}</span>
                        <span class="min-w-0 flex-1 truncate text-sm text-gray-700 dark:text-gray-300">
                            {{ $row['party'] }}
                            <span class="text-xs text-gray-400">· {{ $row['type'] }}</span>
                        </span>
                        <span class="shrink-0 text-right text-sm font-semibold text-gray-950 dark:text-white">
                            ₺{{ \App\Support\Money::format($row['amount']) }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
