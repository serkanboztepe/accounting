{{-- Hub: bakiyeler (Twilio + Anthropic) ve bu ayın maliyet tablosu — kalemler ayrı, en altta genel toplam. --}}
@php
    $usd = fn ($v) => '$' . \App\Support\Money::format((float) $v);
    $low = fn ($v) => $v !== null && $v < 5;
@endphp
<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-2">
        <x-filament::section icon="heroicon-o-chat-bubble-left-right" heading="Twilio (WhatsApp)">
            @if ($twilio !== null)
                <div @class(['text-3xl font-semibold', 'text-danger-600 dark:text-danger-400' => $low($twilio), 'text-gray-950 dark:text-white' => ! $low($twilio)])>{{ $usd($twilio) }}</div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Gerçek bakiye — Twilio'dan otomatik okunur (10 dk'da bir).</p>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">Twilio'ya ulaşılamadı.</p>
            @endif
            @if ($low($twilio))
                <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">Az kaldı — bitince WhatsApp mesajları gitmez.</p>
            @endif
        </x-filament::section>

        <x-filament::section icon="heroicon-o-sparkles" heading="Anthropic (yapay zekâ)">
            <x-slot name="afterHeader">{{ $this->creditAction }}</x-slot>
            @if ($credit)
                <div @class(['text-3xl font-semibold', 'text-danger-600 dark:text-danger-400' => $low($credit['remaining']), 'text-gray-950 dark:text-white' => ! $low($credit['remaining'])])>{{ $usd($credit['remaining']) }}</div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Tahmini — Anthropic bakiyeyi dışarı vermiyor. {{ $credit['at']->timezone('Europe/Istanbul')->format('d.m H:i') }}'de {{ $usd($credit['entered']) }} girildi, o andan beri {{ $usd($credit['spent']) }} harcandı.
                    Önbellek isabeti: {{ $cacheHit === null ? '—' : '%' . \App\Support\Money::format($cacheHit * 100, 0) }}.
                </p>
                @if ($low($credit['remaining']))
                    <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">Az kaldı — bitince müşteriler "okuyamadım" cevabı alır. Yükleme yapıp <b>Güncelle</b>.</p>
                @endif
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">Bakiye girilmedi — sağ üstteki <b>Güncelle</b> ile Anthropic Billing'deki tutarı yaz.</p>
            @endif
        </x-filament::section>
    </div>

    <x-filament::section :heading="'Bu ayın maliyeti — ' . $monthLabel">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                        <th class="py-2 pe-3">Kalem</th>
                        <th class="py-2 pe-3 text-right">Yapay zekâ</th>
                        <th class="py-2 pe-3 text-right">WhatsApp mesajları</th>
                        <th class="py-2 pe-3 text-right">Şablonlar</th>
                        <th class="py-2 pe-3 text-right">Numara kirası</th>
                        <th class="py-2 text-right">Toplam</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach (collect($rows)->sortByDesc('total') as $r)
                        <tr @class(['opacity-60' => ! $r['active']])>
                            <td class="py-2 pe-3 text-gray-950 dark:text-white">{{ $r['name'] }}@if (! $r['active']) <span class="text-xs text-gray-500">(pasif)</span>@endif</td>
                            <td class="py-2 pe-3 text-right">{{ $r['usage'] ? $usd($r['aiCost']) : '—' }}</td>
                            <td class="py-2 pe-3 text-right">{{ $usd($r['msgCost']) }} <span class="text-xs text-gray-500">({{ $r['in'] + $r['out'] }})</span></td>
                            <td class="py-2 pe-3 text-right">{{ $usd($r['templateCost']) }} <span class="text-xs text-gray-500">({{ $r['templates'] }})</span></td>
                            <td class="py-2 pe-3 text-right">{{ $usd($r['numberShare']) }}</td>
                            <td class="py-2 text-right">{{ $usd($r['total']) }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <td class="py-2 pe-3 text-gray-950 dark:text-white">Sabah analizi <span class="text-xs text-gray-500">(günlük konuşma raporu)</span></td>
                        <td class="py-2 pe-3 text-right">{{ $usd($analysis) }}</td>
                        <td class="py-2 pe-3 text-right">—</td><td class="py-2 pe-3 text-right">—</td><td class="py-2 pe-3 text-right">—</td>
                        <td class="py-2 text-right">{{ $usd($analysis) }}</td>
                    </tr>
                    <tr>
                        <td class="py-2 pe-3 text-gray-950 dark:text-white">Testler / geliştirme <span class="text-xs text-gray-500">({{ $devCalls }} çağrı, 14 Ekim'den beri)</span></td>
                        <td class="py-2 pe-3 text-right">{{ $usd($devCost) }}</td>
                        <td class="py-2 pe-3 text-right">—</td><td class="py-2 pe-3 text-right">—</td><td class="py-2 pe-3 text-right">—</td>
                        <td class="py-2 text-right">{{ $usd($devCost) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-gray-300 font-semibold text-gray-950 dark:border-white/20 dark:text-white">
                        <td class="py-3 pe-3">GENEL TOPLAM</td>
                        <td class="py-3 pe-3 text-right">{{ $usd($sum['ai']) }}</td>
                        <td class="py-3 pe-3 text-right">{{ $usd($sum['msgCost']) }} <span class="text-xs font-normal text-gray-500">({{ $sum['msgs'] }})</span></td>
                        <td class="py-3 pe-3 text-right">{{ $usd($sum['templateCost']) }} <span class="text-xs font-normal text-gray-500">({{ $sum['templates'] }})</span></td>
                        <td class="py-3 pe-3 text-right">{{ $usd($sum['numberShare']) }}</td>
                        <td class="py-3 text-right text-primary-700 dark:text-primary-400">{{ $usd($grandTotal) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            Parantez içi adet. Fiyatlar tahmini (config/costs.php) — Twilio faturasıyla kuruşu kuruşuna tutuyor. Numara kirası aktif firmalara bölünür.
        </p>
    </x-filament::section>
</x-filament-panels::page>
