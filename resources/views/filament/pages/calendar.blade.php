{{-- Takvim: hatırlatmalar (kim kurduysa) + ödeme günleri + çek vadeleri, gün gün. Salt görüntü. --}}
<x-filament-panels::page>
    @php
        [$from, $to] = $this->period();
        $days = $this->getDays();
        $today = \Illuminate\Support\Carbon::now(\App\Models\Reminder::TIMEZONE)->toDateString();
        $badge = [
            'reminder' => ['Hatırlatma', 'gray'],
            'collect' => ['Tahsilat', 'success'],
            'pay' => ['Ödeme', 'danger'],
            'check' => ['Çek', 'warning'],
        ];
    @endphp

    <div class="flex flex-wrap items-center gap-2">
        @foreach (['week' => 'Bu hafta', 'month' => 'Bu ay', 'next_month' => 'Gelecek ay'] as $key => $label)
            <x-filament::button size="sm" :color="$range === $key ? 'primary' : 'gray'" wire:click="$set('range', '{{ $key }}')">
                {{ $label }}
            </x-filament::button>
        @endforeach
        <span class="ml-auto text-sm text-gray-500 dark:text-gray-400">
            {{ $from->locale('tr')->translatedFormat('j F') }} – {{ $to->locale('tr')->translatedFormat('j F Y') }}
        </span>
    </div>

    @if (empty($days))
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">Bu aralıkta hatırlatma, ödeme günü ya da çek yok. ✅</p>
        </x-filament::section>
    @else
        @foreach ($days as $date => $items)
            @php $day = \Illuminate\Support\Carbon::parse($date); @endphp
            <x-filament::section compact>
                <x-slot name="heading">
                    {{ $day->locale('tr')->translatedFormat('j F l') }}
                    @if ($date === $today)
                        <x-filament::badge size="sm" color="primary" class="ml-2 inline-flex">Bugün</x-filament::badge>
                    @endif
                </x-slot>

                <ul class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($items as $item)
                        <li class="flex items-center gap-3 py-2 text-sm">
                            <x-filament::badge size="sm" :color="$badge[$item['type']][1] ?? 'gray'">{{ $badge[$item['type']][0] ?? $item['type'] }}</x-filament::badge>
                            @if ($item['time'])
                                <span class="font-medium text-gray-950 dark:text-white">{{ $item['time'] }}</span>
                            @endif
                            <span class="text-gray-800 dark:text-gray-200">{{ $item['text'] }}</span>
                            @if ($item['who'])
                                <span class="ml-auto text-xs text-gray-500 dark:text-gray-400">{{ $item['who'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endforeach
    @endif

    <p class="text-xs text-gray-500 dark:text-gray-400">
        Hatırlatmalar WhatsApp'tan kurulur ("20 Ekim'de teslimatım var") ve firmanın takviminde görünür. Ödenen işler listede yer almaz.
    </p>
</x-filament-panels::page>
