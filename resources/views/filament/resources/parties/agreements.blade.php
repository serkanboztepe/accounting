{{-- Anlaşmalar (Faz 6, 21.10): her anlaşma TEK SATIR (kullanıcı: "kutu kutu sevmedim, aşağı sarkar"); tıklayınca kalemleri
     altında açılır. 26.10: Ödenen + Borç sütunları ("Kalan" → "Gelecek mal"), altta "Diğer alışlar / satışlar" (anlaşma dışı),
     bitmiş ve borcu kalmamış anlaşma arşivde (görünmez). Parçaların toplamı cari bakiyesi (AgreementBalances). --}}
@php
    $fmt = fn ($v) => \App\Support\Money::format((float) $v);
@endphp

<x-filament::section
    heading="Anlaşmalar"
    :description="count($agreements['active']) . ' anlaşma · mal/iş gelince Alım Yaptım (satışta Satış Yaptım) penceresinin üstünden işaretlenir'"
    compact
>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                    <th class="py-2 pr-3 font-medium">Anlaşma</th>
                    <th class="py-2 pr-3 text-right font-medium">Anlaşılan</th>
                    <th class="py-2 pr-3 text-right font-medium">Gelen</th>
                    <th class="py-2 pr-3 text-right font-medium">Ödenen</th>
                    <th class="py-2 pr-3 text-right font-medium">Borç</th>
                    <th class="py-2 text-right font-medium">Gelecek mal</th>
                </tr>
            </thead>
            @foreach ($agreements['active'] as $row)
                @include('filament.resources.parties.agreement-row', ['row' => $row])
            @endforeach
            @if ($agreements['other'] !== [])
                <tbody>
                    @foreach ($agreements['other'] as $direction => $balance)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                            <td class="py-2 pr-3 text-gray-700 dark:text-gray-300">
                                {{ \App\Support\AgreementBalances::otherLabel($direction) }}
                                <span class="ml-1 text-xs text-gray-500 dark:text-gray-400">anlaşma dışı</span>
                            </td>
                            <td class="py-2 pr-3 text-right text-gray-400">—</td>
                            <td class="py-2 pr-3 text-right text-gray-400">—</td>
                            <td class="py-2 pr-3 text-right text-gray-400">—</td>
                            <td class="py-2 pr-3 text-right tabular-nums font-medium {{ $balance < -0.009 ? 'text-warning-600 dark:text-warning-400' : 'text-gray-950 dark:text-white' }}">
                                {{ \App\Support\AgreementSummary::balanceText($balance, $direction === \App\Models\Agreement::DIRECTION_SALE) }}
                            </td>
                            <td class="py-2 text-right text-gray-400">—</td>
                        </tr>
                    @endforeach
                </tbody>
            @endif
            @if ($agreements['archived'] !== [])
                <tbody x-data="{ showArchive: false }">
                    <tr>
                        <td colspan="6" class="pt-3">
                            <button type="button" class="text-xs font-medium text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200"
                                x-on:click="showArchive = ! showArchive">
                                <span x-text="showArchive ? '▾' : '▸'"></span> Arşiv ({{ count($agreements['archived']) }}) <span x-text="showArchive ? 'gizle' : 'göster'"></span>
                            </button>
                        </td>
                    </tr>
                    <tr x-show="showArchive" x-cloak>
                        <td colspan="6" class="p-0">
                            <table class="w-full text-sm opacity-75">
                                @foreach ($agreements['archived'] as $row)
                                    @include('filament.resources.parties.agreement-row', ['row' => $row])
                                @endforeach
                            </table>
                        </td>
                    </tr>
                </tbody>
            @endif
        </table>
    </div>
</x-filament::section>
