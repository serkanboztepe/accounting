{{-- Anlaşmalar (Faz 6, 21.10): her anlaşma TEK SATIR (kullanıcı: "kutu kutu sevmedim, aşağı sarkar"); tıklayınca kalemleri
     altında açılır. Teslimat Alım Yaptım / Satış Yaptım penceresinden girilir ("Anlaşmadan gelen" bölümü). --}}
@php
    $fmt = fn ($v) => \App\Support\Money::format((float) $v);
    $groups = [['rows' => $agreements['open'], 'finished' => false], ['rows' => $agreements['finished'], 'finished' => true]];
@endphp

<x-filament::section
    heading="Anlaşmalar"
    :description="$agreements['open']->count() . ' açık · mal/iş gelince Alım Yaptım (satışta Satış Yaptım) penceresinin üstünden işaretlenir'"
    compact
>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                    <th class="py-2 pr-3 font-medium">Anlaşma</th>
                    <th class="py-2 pr-3 text-right font-medium">Anlaşılan</th>
                    <th class="py-2 pr-3 text-right font-medium">Gelen</th>
                    <th class="py-2 pr-3 text-right font-medium">Kalan</th>
                    <th class="py-2 text-right font-medium">%</th>
                </tr>
            </thead>
            @foreach ($groups as $group)
                @if ($group['finished'] && $group['rows']->isNotEmpty())
                    <tbody x-data="{ showFinished: false }">
                        <tr>
                            <td colspan="5" class="pt-3">
                                <button type="button" class="text-xs font-medium text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200"
                                    x-on:click="showFinished = ! showFinished">
                                    <span x-text="showFinished ? '▾' : '▸'"></span> Biten anlaşmalar ({{ $group['rows']->count() }})
                                </button>
                            </td>
                        </tr>
                        <tr x-show="showFinished" x-cloak>
                            <td colspan="5" class="p-0">
                                <table class="w-full text-sm opacity-75">
                                    @foreach ($group['rows'] as $agreement)
                                        @include('filament.resources.parties.agreement-row', ['agreement' => $agreement])
                                    @endforeach
                                </table>
                            </td>
                        </tr>
                    </tbody>
                @elseif (! $group['finished'])
                    @foreach ($group['rows'] as $agreement)
                        @include('filament.resources.parties.agreement-row', ['agreement' => $agreement])
                    @endforeach
                @endif
            @endforeach
        </table>
    </div>
</x-filament::section>
