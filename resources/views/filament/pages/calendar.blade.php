{{-- Takvim: hatırlatmalar (kim kurduysa) + ödeme günleri + çek vadeleri, gün gün; altta hatırlatma / düzenli gider listesi (düzelt, sil). --}}
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

    @php $reminders = $this->getReminders(); @endphp
    <x-filament::section compact>
        <x-slot name="heading">Hatırlatmalar ve düzenli giderler</x-slot>
        @if ($reminders->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Bekleyen hatırlatma yok. Sağ üstten ekleyebilir ya da WhatsApp'tan yazabilirsin.</p>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($reminders as $r)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 text-sm">
                        @if ($r->isExpense())
                            <x-filament::badge size="sm" color="warning">Düzenli gider</x-filament::badge>
                        @else
                            <x-filament::badge size="sm" color="gray">Hatırlatma</x-filament::badge>
                        @endif
                        <span class="text-gray-950 dark:text-white">{{ $r->text }}</span>
                        @if ($r->isExpense())
                            <span class="font-medium tabular-nums">{{ \App\Support\Money::format((float) $r->amount) }} ₺</span>
                            @if ($r->project)
                                <span class="text-gray-500 dark:text-gray-400">{{ $r->project->name }}</span>
                            @endif
                        @endif
                        <span class="text-gray-500 dark:text-gray-400">{{ $r->repeat ? $r->scheduleText() : '' }}@if ($r->next_fire_at) · sıradaki {{ $r->next_fire_at->setTimezone(\App\Models\Reminder::TIMEZONE)->format('d.m.Y H:i') }}@endif</span>
                        <span class="ml-auto flex items-center gap-1">
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $this->whoName($r->phone) }}</span>
                            {{ ($this->editReminderAction)(['id' => $r->id]) }}
                            {{ ($this->deleteReminderAction)(['id' => $r->id]) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        Hatırlatmalar WhatsApp'tan ("20 Ekim'de teslimatım var", "her gün çobanlara ekmek 34,60 gider yaz") ya da buradan kurulur. Ödenen işler listede yer almaz.
    </p>
</x-filament-panels::page>
