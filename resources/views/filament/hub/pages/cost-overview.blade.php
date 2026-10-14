{{-- Hub: bu ayın toplu maliyeti — firmalar + sabah analizi + yerel denemeler. --}}
@php
    $usd = fn ($v) => '$' . \App\Support\Money::format((float) $v);
@endphp
<x-filament-panels::page>
    <x-filament::section icon="heroicon-o-chat-bubble-left-right" heading="Twilio bakiyesi (WhatsApp)">
        @if ($twilio !== null)
            <div @class(['text-3xl font-semibold',
                'text-danger-600 dark:text-danger-400' => $twilio < 5,
                'text-primary-700 dark:text-primary-400' => $twilio >= 5])>{{ $usd($twilio) }}</div>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Twilio'dan anlık okunur (10 dk'da bir yenilenir). Bitince mesajlar gitmez.</p>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">Twilio'ya ulaşılamadı.</p>
        @endif
    </x-filament::section>

    <x-filament::section icon="heroicon-o-credit-card" heading="Anthropic bakiyesi (tahmini)">
        @if ($credit)
            <div class="flex flex-wrap items-baseline gap-x-6 gap-y-1">
                <div @class(['text-3xl font-semibold',
                    'text-danger-600 dark:text-danger-400' => $credit['remaining'] < 5,
                    'text-primary-700 dark:text-primary-400' => $credit['remaining'] >= 5])>{{ $usd($credit['remaining']) }}</div>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $credit['at']->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}'de {{ $usd($credit['entered']) }} girildi · o andan beri {{ $usd($credit['spent']) }} harcandı
                </div>
            </div>
            @if ($credit['remaining'] < 5)
                <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">Bakiye azaldı — bitince müşteriler "okuyamadım" cevabı alır. Yükleme yapıp *Bakiye gir* ile güncelle.</p>
            @endif
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">Henüz bakiye girilmedi. Sağ üstteki *Bakiye gir* ile Anthropic Billing'deki tutarı yaz.</p>
        @endif
    </x-filament::section>

    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Genel toplam ({{ $monthLabel }})</div>
            <div class="text-2xl font-semibold text-primary-700 dark:text-primary-400">{{ $usd($grandTotal) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Yapay zekâ toplamı</div>
            <div class="text-2xl font-semibold text-gray-950 dark:text-white">{{ $usd($aiTotal) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">firmalar + analiz + denemeler</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">önbellek isabeti: {{ $cacheHit === null ? '—' : '%' . \App\Support\Money::format($cacheHit * 100, 0) }} (14 Ekim akşamından önce önbellek yoktu)</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Sabah analizi</div>
            <div class="text-2xl font-semibold text-gray-950 dark:text-white">{{ $usd($analysis) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Testler / yerel denemeler</div>
            <div class="text-2xl font-semibold text-gray-950 dark:text-white">{{ $usd($devCost) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $devCalls }} çağrı (14 Ekim'den beri kaydediliyor)</div>
        </x-filament::section>
    </div>

    <x-filament::section heading="Firmalar">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                        <th class="py-2 pe-3">Firma</th>
                        <th class="py-2 pe-3 text-right">Mesaj</th>
                        <th class="py-2 pe-3 text-right">Yapay zekâ</th>
                        <th class="py-2 pe-3 text-right">WhatsApp</th>
                        <th class="py-2 pe-3 text-right">Numara payı</th>
                        <th class="py-2 text-right">Toplam</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach (collect($rows)->sortByDesc('total') as $r)
                        <tr @class(['opacity-60' => ! $r['active']])>
                            <td class="py-2 pe-3 text-gray-950 dark:text-white">{{ $r['name'] }}@if (! $r['active']) <span class="text-xs text-gray-500">(pasif)</span>@endif</td>
                            <td class="py-2 pe-3 text-right">{{ $r['in'] + $r['out'] + $r['templates'] }}</td>
                            <td class="py-2 pe-3 text-right">{{ $r['usage'] ? $usd($r['aiCost']) : '—' }}</td>
                            <td class="py-2 pe-3 text-right">{{ $usd($r['waCost']) }}</td>
                            <td class="py-2 pe-3 text-right">{{ $usd($r['numberShare']) }}</td>
                            <td class="py-2 text-right font-semibold">{{ $usd($r['total']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            Tahmini (fiyatlar config/costs.php). Gerçek bakiye Anthropic ve Twilio panellerinde. Claude Code aboneliği bu anahtara dahil değil.
        </p>
    </x-filament::section>
</x-filament-panels::page>
