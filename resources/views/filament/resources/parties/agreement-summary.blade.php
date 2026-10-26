{{--
    Anlaşma penceresi (26.10 özet, 27.10 tek yer): üstte işlem düğmeleri ve rakamlar, sonra kalemler (Fiyat / Sil), gelen mallar,
    ödemeler — hepsi açık. Düğmeler iç pencere açar; kaydedince bu pencereye dönülür. Bkz. AgreementSummary, EditParty::agreementSummaryAction.
--}}
@php
    $fmt = fn ($v) => \App\Support\AgreementBalances::money((float) $v);
@endphp

@if ($agreement)
    @php
        $isSale = ! $agreement->isPurchase();
        $isOpen = $agreement->isOpen();
        $isClosed = $agreement->status === \App\Models\Agreement::STATUS_CLOSED;
        $id = $agreement->id;
        $stats = array_values(array_filter([
            $agreed > 0 ? ['Anlaşılan', $fmt($agreed)] : null,
            [$isSale ? 'Teslim edilen' : 'Gelen', $fmt($delivered) . ' (' . $deliveries->count() . ' teslimat)'],
            [$isSale ? 'Tahsil edilen' : 'Ödenen', $fmt($paid) . ' (' . $payments->count() . ' ' . ($isSale ? 'tahsilat' : 'ödeme') . ')'],
            $pendingCount > 0 ? ['Bekleyen çek', $fmt($pendingTotal) . ' (' . $pendingCount . ' çek' . ($pendingNext ? ', ilk vade ' . $pendingNext->format('d.m.Y') : '') . ')'] : null,
        ]));
        $payAction = $isSale
            ? "mountAction('newCollection', { agreement: {$id} })"
            : "mountAction('newLedgerEntry', { type: '" . \App\Models\PartyLedgerEntry::TYPE_PAYMENT . "', agreement: {$id} })";
    @endphp

    <div class="space-y-6 text-sm">
        <div class="space-y-3">
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ $isSale ? 'Satış' : 'Alış' }}{{ $agreement->project ? ' · ' . $agreement->project->name : '' }} · {{ $agreement->agreement_date?->format('d.m.Y') }}
                · {{ \App\Models\Agreement::STATUSES[$agreement->status] ?? $agreement->status }}
                @if ($agreement->notes) <span class="block pt-1">{{ $agreement->notes }}</span> @endif
            </p>
            <div class="flex flex-wrap gap-2">
                <x-filament::button size="sm" wire:click="{{ $payAction }}">{{ $isSale ? 'Ödeme al' : 'Ödeme yap' }}</x-filament::button>
                @if ($isOpen)
                    <x-filament::button size="sm" color="gray" wire:click="mountAction('addAgreementItems', { agreement: {{ $id }} })">+ Kalem ekle</x-filament::button>
                @endif
                <x-filament::button size="sm" color="gray" wire:click="mountAction('editAgreement', { agreement: {{ $id }} })">Düzenle</x-filament::button>
                <x-filament::button size="sm" color="gray" wire:click="mountAction('toggleAgreement', { agreement: {{ $id }} })">
                    {{ $isClosed ? 'Yeniden aç' : 'Anlaşmayı kapat' }}
                </x-filament::button>
                @if ($deliveries->isEmpty())
                    <x-filament::button size="sm" color="danger" outlined wire:click="mountAction('deleteAgreement', { agreement: {{ $id }} })">Sil</x-filament::button>
                @endif
            </div>
        </div>

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

        <section>
            <h3 class="text-sm font-medium text-gray-950 dark:text-white">Kalemler ({{ $agreement->items->count() }})</h3>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full text-xs">
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($agreement->items as $item)
                            @php
                                $rest = $item->remainingQuantity();
                                $closed = $item->isClosed();
                            @endphp
                            <tr class="{{ $closed ? 'text-gray-400 dark:text-gray-500' : 'text-gray-700 dark:text-gray-300' }}">
                                <td class="py-1.5 pr-3">{{ $item->name() }} @if ($closed) <span>✓ eski fiyat</span> @endif</td>
                                @if ($item->is_lump)
                                    <td class="py-1.5 pr-3 text-right">götürü</td>
                                    <td class="py-1.5 pr-3 text-right tabular-nums">%{{ (int) round($item->deliveredQuantity() * 100) }} · {{ $fmt($item->deliveredAmount()) }}</td>
                                    <td class="py-1.5 pr-3 text-right tabular-nums {{ $rest < 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">kalan {{ $fmt($item->remainingAmount()) }}</td>
                                    <td class="py-1.5 pr-3 text-right tabular-nums">{{ $fmt($item->unit_price) }}</td>
                                @elseif ($item->isList())
                                    <td class="py-1.5 pr-3 text-right">liste</td>
                                    <td class="py-1.5 pr-3 text-right tabular-nums">gelen {{ $item->quantityLabel($item->deliveredQuantity()) }}</td>
                                    <td class="py-1.5 pr-3 text-right">—</td>
                                    <td class="py-1.5 pr-3 text-right tabular-nums">{{ $fmt($item->unit_price) }}{{ $item->unitName() ? '/' . $item->unitName() : '' }}</td>
                                @else
                                    <td class="py-1.5 pr-3 text-right tabular-nums">{{ $item->quantityLabel((float) $item->quantity) }}</td>
                                    <td class="py-1.5 pr-3 text-right tabular-nums">gelen {{ $item->quantityLabel($item->deliveredQuantity()) }}</td>
                                    <td class="py-1.5 pr-3 text-right tabular-nums {{ $rest < 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">
                                        {{ $closed ? '—' : ($rest < 0 ? '⚠ ' . $item->quantityLabel(abs($rest)) . ' fazla' : 'kalan ' . $item->quantityLabel($rest)) }}
                                    </td>
                                    <td class="py-1.5 pr-3 text-right tabular-nums">{{ $fmt($item->unit_price) }}</td>
                                @endif
                                <td class="py-1.5 text-right whitespace-nowrap">
                                    @if ($isOpen && ! $closed)
                                        <button type="button" class="text-primary-600 hover:underline dark:text-primary-400"
                                            wire:click="mountAction('changeAgreementPrice', { item: {{ $item->id }} })">Fiyat</button>
                                        @if ($item->movements->isEmpty())
                                            <button type="button" class="ml-2 text-danger-600 hover:underline dark:text-danger-400"
                                                wire:click="mountAction('deleteAgreementItem', { item: {{ $item->id }} })">Sil</button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section>
            <h3 class="text-sm font-medium text-gray-950 dark:text-white">{{ $isSale ? 'Teslimatlar' : 'Gelen mallar' }} ({{ $deliveries->count() }})</h3>
            <table class="mt-2 w-full text-xs">
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
        </section>

        <section>
            <h3 class="text-sm font-medium text-gray-950 dark:text-white">{{ $isSale ? 'Tahsilatlar' : 'Ödemeler' }} ({{ $payments->count() }})</h3>
            <table class="mt-2 w-full text-xs">
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
        </section>
    </div>
@endif
