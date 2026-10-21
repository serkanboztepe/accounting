{{-- İşlem geçmişi satırı (cari sayfası + İşlem Geçmişi sayfası). Ayrıntı açılır: son hal / silinmeden önceki hal + değişenler. --}}
@php
    $tone = match ($log->event) {
        'created'  => ['bg' => '#dcfce7', 'fg' => '#15803d', 'dot' => '#10b981', 'icon' => '➕'],
        'updated'  => ['bg' => '#dbeafe', 'fg' => '#1d4ed8', 'dot' => '#3b82f6', 'icon' => '✏️'],
        'deleted'  => ['bg' => '#ffe4e6', 'fg' => '#be123c', 'dot' => '#f43f5e', 'icon' => '🗑'],
        default    => ['bg' => '#f3f4f6', 'fg' => '#4b5563', 'dot' => '#9ca3af', 'icon' => '·'],
    };
    $details = $log->details ?? [];
    $changes = $log->changes ?? [];
@endphp
<details @if ($open ?? false) open @endif class="group rounded-lg border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/5"
         style="border-left: 3px solid {{ $tone['dot'] }};">
    <summary class="flex cursor-pointer list-none flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 md:flex-nowrap">
        <div class="w-28 shrink-0 text-xs text-gray-500 dark:text-gray-400">
            {{ $log->happened_at?->format('d.m.Y H:i') }}
        </div>
        <span class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
              style="background:{{ $tone['bg'] }};color:{{ $tone['fg'] }};">
            {{ $tone['icon'] }} {{ $log->eventLabel() }}
        </span>
        <div class="min-w-0 flex-1">
            <div class="truncate text-sm font-medium text-gray-900 dark:text-white">
                {{ $log->action }}@if (($showParty ?? true) && $log->party_name) · {{ $log->party_name }}@endif
            </div>
            @if ($log->summary)
                <div class="truncate text-xs text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Str::limit(str_replace("\n", ' · ', $log->summary), 120) }}</div>
            @endif
        </div>
        @if ($log->amount !== null && (float) $log->amount > 0)
            <div class="shrink-0 text-sm font-semibold text-gray-900 dark:text-white md:text-right">
                @if ($log->old_amount !== null)
                    <span class="font-normal text-gray-400 line-through">₺{{ \App\Support\Money::format($log->old_amount) }}</span> →
                @endif
                ₺{{ \App\Support\Money::format($log->amount) }}
            </div>
        @endif
        <div class="w-full shrink-0 text-xs text-gray-500 dark:text-gray-400 md:w-44 md:text-right">
            {{ $log->event === 'existing' ? '—' : $log->channelLabel() }}
        </div>
    </summary>
    <div class="space-y-3 border-t border-gray-100 px-4 py-3 text-sm dark:border-white/10">
        @if ($log->note)
            <div class="text-xs font-medium text-amber-700 dark:text-amber-400">{{ $log->note }}</div>
        @endif
        @if ($changes)
            <div>
                <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Değişenler</div>
                <table class="w-full text-xs">
                    @foreach ($changes as $label => [$old, $new])
                        <tr class="align-top">
                            <td class="w-36 py-0.5 pr-3 text-gray-500">{{ $label }}</td>
                            <td class="py-0.5 pr-3 whitespace-pre-line text-gray-400 line-through">{{ $old ?? '—' }}</td>
                            <td class="py-0.5 whitespace-pre-line text-gray-900 dark:text-white">{{ $new ?? '—' }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
        @if ($details)
            <div>
                <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">
                    {{ $log->event === 'deleted' ? 'Silinmeden önceki hali' : 'Kaydın hali' }}
                </div>
                <table class="w-full text-xs">
                    @foreach ($details as $label => $value)
                        <tr class="align-top">
                            <td class="w-36 py-0.5 pr-3 text-gray-500">{{ $label }}</td>
                            <td class="py-0.5 whitespace-pre-line text-gray-900 dark:text-white">{{ $value }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
    </div>
</details>
