{{-- Satışlar / Alışlar tezgahı: Bugün ve Bu ay özeti. --}}
@php
    $cards = $kind === 'sale'
        ? [
            ['Ciro', 'total'], ['Nakit', 'cash'], ['Kart', 'bank'], ['Veresiye', 'credit'],
        ]
        : [
            ['Alış', 'total'], ['Peşin', 'paid'], ['Veresiye', 'credit'],
        ];
@endphp
<div class="grid gap-3 md:grid-cols-2">
    @foreach (['Bugün' => $today, 'Bu ay' => $month] as $label => $sum)
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="mb-2 flex items-baseline justify-between">
                <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ $label }}</span>
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $sum['count'] }} kayıt</span>
            </div>
            <div class="grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-4">
                @foreach ($cards as [$title, $key])
                    <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $title }}</div>
                        <div class="tabular-nums {{ $loop->first ? 'text-lg font-semibold text-gray-950 dark:text-white' : 'text-sm text-gray-700 dark:text-gray-300' }}">
                            ₺{{ \App\Support\Money::format($sum[$key]) }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
