{{-- Anlaşma özeti (26.10): yandan açılan pencere — rakamlar üstte, teslimatlar ve ödemeler altta. Bkz. AgreementSummary. --}}
@php
    $fmt = fn ($v) => \App\Support\AgreementBalances::money((float) $v);
@endphp

@if ($agreement)
    @php
        $isSale = ! $agreement->isPurchase();
        $stats = array_values(array_filter([
            $agreed > 0 ? ['Anlaşılan', $fmt($agreed)] : null,
            [$isSale ? 'Teslim edilen' : 'Gelen', $fmt($delivered) . ' (' . $deliveries->count() . ' teslimat)'],
            [$isSale ? 'Tahsil edilen' : 'Ödenen', $fmt($paid) . ' (' . $payments->count() . ' ' . ($isSale ? 'tahsilat' : 'ödeme') . ')'],
            $pendingCount > 0 ? ['Bekleyen çek', $fmt($pendingTotal) . ' (' . $pendingCount . ' çek' . ($pendingNext ? ', ilk vade ' . $pendingNext->format('d.m.Y') : '') . ')'] : null,
        ]));
    @endphp

    <div class="space-y-6 text-sm">
        <dl class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($stats as [$label, $value])
                <div class="flex justify-between gap-4 py-2">
                    <dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                    <dd class="tabular-nums text-gray-950 dark:text-white">{{ $value }}</dd>
                </div>
            @endforeach
            <div class="flex justify-between gap-4 py-2 font-semibold">
                <dt class="text-gray-950 dark:text-white">{{ $isSale ? 'Alacak' : 'Borç' }} ({{ $isSale ? 'teslim edilen' : 'gelen' }} − {{ $isSale ? 'tahsil edilen' : 'ödenen' }})</dt>
                <dd class="tabular-nums {{ $balance < -0.009 ? 'text-warning-600 dark:text-warning-400' : 'text-gray-950 dark:text-white' }}">
                    {{ \App\Support\AgreementSummary::balanceText($balance, $isSale) }}
                </dd>
            </div>
            @if ($agreed > 0)
                <div class="flex justify-between gap-4 py-2">
                    <dt class="text-gray-500 dark:text-gray-400">{{ $isSale ? 'Teslim edilecek' : 'Gelecek mal' }}</dt>
                    <dd class="tabular-nums text-gray-950 dark:text-white">{{ $remaining > 0 ? $fmt($remaining) : '—' }}</dd>
                </div>
            @endif
        </dl>

        <div x-data="{ open: true }">
            <button type="button" class="text-sm font-medium text-gray-950 dark:text-white" x-on:click="open = ! open">
                <span x-text="open ? '▾' : '▸'"></span> {{ $isSale ? 'Teslimatlar' : 'Gelen mallar' }} ({{ $deliveries->count() }})
            </button>
            <table x-show="open" class="mt-2 w-full text-xs">
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($deliveries as $d)
                        <tr class="{{ $d['return'] ? 'text-danger-600 dark:text-danger-400' : 'text-gray-700 dark:text-gray-300' }}">
                            <td class="py-1.5 pr-3 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $d['date']?->format('d.m.Y') }}</td>
                            <td class="py-1.5 pr-3">{{ $d['name'] }}{{ $d['return'] ? ' (iade)' : '' }}</td>
                            <td class="py-1.5 pr-3 text-right tabular-nums">{{ $d['qty'] }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ $d['amount'] < 0 ? '−' : '' }}{{ $fmt($d['amount']) }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-2 text-gray-500 dark:text-gray-400">Henüz teslimat yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div x-data="{ open: true }">
            <button type="button" class="text-sm font-medium text-gray-950 dark:text-white" x-on:click="open = ! open">
                <span x-text="open ? '▾' : '▸'"></span> {{ $isSale ? 'Tahsilatlar' : 'Ödemeler' }} ({{ $payments->count() }})
            </button>
            <table x-show="open" class="mt-2 w-full text-xs">
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($payments as $p)
                        <tr class="text-gray-700 dark:text-gray-300">
                            <td class="py-1.5 pr-3 whitespace-nowrap align-top text-gray-500 dark:text-gray-400">{{ $p['date']?->format('d.m.Y') }}</td>
                            <td class="py-1.5 pr-3">
                                {{ $p['label'] }}{{ $p['method'] ? ' · ' . $p['method'] : '' }}
                                @if ($p['desc']) <span class="text-gray-500 dark:text-gray-400">— {{ $p['desc'] }}</span> @endif
                                @foreach ($p['checks'] as $c)
                                    <div class="text-gray-500 dark:text-gray-400">çek {{ $c }}</div>
                                @endforeach
                            </td>
                            <td class="py-1.5 text-right align-top tabular-nums">{{ $p['amount'] < 0 ? '−' : '' }}{{ $fmt($p['amount']) }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-2 text-gray-500 dark:text-gray-400">Bu anlaşmaya bağlı {{ $isSale ? 'tahsilat' : 'ödeme' }} yok. Eski ödemeleri ekstrede "Bağla" ile bağlayabilirsin.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
