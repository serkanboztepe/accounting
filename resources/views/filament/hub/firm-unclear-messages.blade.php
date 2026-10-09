{{-- Hub firma sayfası → "Anlaşılamayanlar" penceresi: mesaj + asistanın cevabı. --}}
@php
    $kindLabels = ['help' => 'anlaşılamadı', 'error' => 'hata', 'no_draft' => 'taslak yokken'];
@endphp
@if (empty($rows))
    <p class="text-sm text-gray-500 dark:text-gray-400">Son 30 günde anlaşılamayan mesaj yok. ✅</p>
@else
    <div class="divide-y divide-gray-200 dark:divide-white/10">
        @foreach ($rows as $row)
            <div class="py-3">
                <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                    <span>{{ $row['at'] }}</span>
                    <span>·</span>
                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ $row['who'] }}</span>
                    <x-filament::badge size="sm" color="warning">{{ $kindLabels[$row['kind']] ?? $row['kind'] }}</x-filament::badge>
                </div>
                <div class="mt-1 text-sm font-medium text-gray-950 dark:text-white">“{{ $row['body'] }}”</div>
                @if ($row['reply'])
                    <div class="mt-1 whitespace-pre-line text-sm text-gray-600 dark:text-gray-400">↳ {{ \Illuminate\Support\Str::limit($row['reply'], 400) }}</div>
                @endif
            </div>
        @endforeach
    </div>
@endif
