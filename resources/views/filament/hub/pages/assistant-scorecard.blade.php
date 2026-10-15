{{-- Hub: asistan karnesi — son 7 gün, firma firma. Rakamlar firmanın kendi kayıtlarından (yalnız okuma, 10 dk önbellek). --}}
@php
    $sec = fn ($v) => $v === null ? '—' : $v . ' sn';
    $rate = fn ($a, $b) => $b > 0 ? '%' . round($a * 100 / $b) : '—';
@endphp
<x-filament-panels::page>
    <x-filament::section :heading="'Son ' . $days . ' gün'" description="Geri alma = kaydedildikten sonra silinen/düzeltilen. Anlaşılmadı = asistan yönlendirme cevabı verdi. Yarım = 1 saatten uzun onay bekleyen taslak. 15 sn üstü = Twilio cevabı beklemeyi bırakır, kişiye cevap gitmez.">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                        <th class="py-2 pe-3">Firma</th>
                        <th class="py-2 pe-3 text-right">Mesaj</th>
                        <th class="py-2 pe-3 text-right">Kayıt</th>
                        <th class="py-2 pe-3 text-right">Geri alma</th>
                        <th class="py-2 pe-3 text-right">İptal</th>
                        <th class="py-2 pe-3 text-right">Anlaşılmadı</th>
                        <th class="py-2 pe-3 text-right">Hata</th>
                        <th class="py-2 pe-3 text-right">Yarım</th>
                        <th class="py-2 pe-3 text-right">Süre (orta / en uzun)</th>
                        <th class="py-2 text-right">15 sn üstü</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($rows as $r)
                        <tr>
                            <td class="py-2 pe-3 text-gray-950 dark:text-white">{{ $r['name'] }}</td>
                            @if (! empty($r['error']))
                                <td colspan="9" class="py-2 text-gray-500">okunamadı</td>
                            @else
                                <td class="py-2 pe-3 text-right tabular-nums">{{ $r['in'] }}</td>
                                <td class="py-2 pe-3 text-right tabular-nums">{{ $r['saved'] }}</td>
                                <td @class(['py-2 pe-3 text-right tabular-nums', 'text-warning-600' => $r['saved'] > 0 && $r['undo'] / $r['saved'] > 0.1])>{{ $r['undo'] }} <span class="text-xs text-gray-500">{{ $rate($r['undo'], $r['saved']) }}</span></td>
                                <td class="py-2 pe-3 text-right tabular-nums">{{ $r['cancelled'] }}</td>
                                <td class="py-2 pe-3 text-right tabular-nums">{{ $r['help'] }}</td>
                                <td @class(['py-2 pe-3 text-right tabular-nums', 'text-danger-600' => $r['errors'] > 0])>{{ $r['errors'] }}</td>
                                <td @class(['py-2 pe-3 text-right tabular-nums', 'text-warning-600' => $r['open'] > 0])>{{ $r['open'] }}</td>
                                <td class="py-2 pe-3 text-right tabular-nums">{{ $sec($r['median']) }} / {{ $sec($r['max']) }}</td>
                                <td @class(['py-2 text-right tabular-nums', 'text-danger-600' => $r['slow'] > 0])>{{ $r['slow'] }}</td>
                            @endif
                        </tr>
                    @endforeach
                    <tr class="font-medium">
                        <td class="py-2 pe-3">Toplam</td>
                        <td class="py-2 pe-3 text-right tabular-nums">{{ $total['in'] }}</td>
                        <td class="py-2 pe-3 text-right tabular-nums">{{ $total['saved'] }}</td>
                        <td class="py-2 pe-3 text-right tabular-nums">{{ $total['undo'] }} <span class="text-xs text-gray-500">{{ $rate($total['undo'], $total['saved']) }}</span></td>
                        <td class="py-2 pe-3 text-right tabular-nums">{{ $total['cancelled'] }}</td>
                        <td class="py-2 pe-3 text-right tabular-nums">{{ $total['help'] }}</td>
                        <td class="py-2 pe-3 text-right tabular-nums">{{ $total['errors'] }}</td>
                        <td class="py-2 pe-3 text-right tabular-nums">{{ $total['open'] }}</td>
                        <td class="py-2 pe-3 text-right tabular-nums">— / {{ $sec($total['max']) }}</td>
                        <td class="py-2 text-right tabular-nums">{{ $total['slow'] }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </x-filament::section>

    @if ($eval)
        <x-filament::section heading="Son deneme seti" :description="'Gerçek yapay zekâ ile uçtan uca, ' . \Illuminate\Support\Carbon::parse($eval['at'])->timezone('Europe/Istanbul')->format('d.m H:i')">
            <div class="flex flex-wrap gap-6 text-sm">
                @foreach ($eval['summary'] as $group => $g)
                    <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $group === 'regression' ? 'Bozulmamalı' : 'Yetenek (hedef)' }}</div>
                        <div class="text-xl font-semibold tabular-nums">{{ $g['geçti'] }}/{{ $g['toplam'] }}</div>
                        <div class="text-xs text-gray-500">ort {{ $g['ort_sn'] }} sn · en uzun {{ $g['en_uzun_sn'] }} sn</div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
