{{-- Anlaşmalar (Faz 6, 21.10): her anlaşma TEK SATIR (kullanıcı: "kutu kutu sevmedim, aşağı sarkar"); tıklayınca kalemleri
     altında açılır. 26.10: Ödenen + Borç sütunları ("Kalan" → "Gelecek mal"), bitmiş ve borcu kalmamış anlaşma arşivde (görünmez).
     27.10: "Diğer alışlar" satırı kalktı (kullanıcı: anlaşma dışı ekstrede zaten görünüyor; ekstrenin Anlaşma süzgecinde "Diğer"). --}}
@php
    $fmt = fn ($v) => \App\Support\Money::format((float) $v);
@endphp

<x-filament::section
    heading="Anlaşmalar"
    :description="count($agreements['active']) . ' anlaşma · mal/iş gelince Alım Yaptım (satışta Satış Yaptım) penceresinin üstünden işaretlenir'"
    compact
>
    {{-- Üç kutu (12.11): şu an ödenecek · yapılacak iş · toplam — AgreementBalances::totals. Tek yönlü caride ilk kutu
         Hesap Dökümü bakiyesiyle aynı; iki yönlü caride alış ve satış ayrı sıra, yalnız anlaşmalardan. --}}
    @foreach ($agreements['totals'] ?? [] as $direction => $t)
        @php
            $sale = $direction === \App\Models\Agreement::DIRECTION_SALE;
            $boxes = [
                [$t['now'] < -0.009 ? ($sale ? 'Avans aldık' : 'Avans verdik') : ($sale ? 'Şu an alacağımız' : 'Şu an ödenecek'),
                    abs($t['now']), $t['now'] < -0.009 ? ($sale ? 'yapılan işten fazla alınan' : 'yapılan işten fazla ödenen') : 'yapılan iş − ' . ($sale ? 'alınan' : 'ödenen')],
                ['Yapılacak iş', $t['remaining'], 'anlaşmada olup henüz gelmeyen'],
                [$t['total'] < -0.009 ? ($sale ? 'Fazla aldığımız' : 'Fazla ödediğimiz') : ($sale ? 'Toplam alacağımız' : 'Toplam ödeyeceğimiz'),
                    abs($t['total']), 'şu an + yapılacak iş'],
            ];
        @endphp
        @if ($t['agreements_only'])
            <div class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">{{ $sale ? '📤 Satış anlaşmaları' : '📥 Alış anlaşmaları' }} · yalnız anlaşmalardan</div>
        @endif
        <div class="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-3" data-agreement-totals="{{ $direction }}">
            @foreach ($boxes as $i => [$label, $value, $hint])
                <div class="rounded-lg border px-3 py-2 {{ $i === 2 ? 'border-primary-200 bg-primary-50 dark:border-primary-500/30 dark:bg-primary-500/10' : 'border-gray-200 dark:border-white/10' }}">
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                    <div class="text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ $fmt($value) }} ₺</div>
                    <div class="text-xs text-gray-400 dark:text-gray-500">{{ $hint }}</div>
                </div>
            @endforeach
        </div>
    @endforeach

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
