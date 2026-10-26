{{-- Tek anlaşma: özet satırı (Anlaşılan · Gelen · Ödenen · Borç · Gelecek mal) + tıklayınca kalemler (Fiyat / Sil) ve işlemler. --}}
@php
    $agreement = $row['agreement'];
    $fmt = fn ($v) => \App\Support\Money::format((float) $v);
    $isOpen = $agreement->isOpen();
    $isSale = ! $agreement->isPurchase();
    $agreed = $agreement->agreedTotal();
    $hasDeliveries = $agreement->items->contains(fn ($i) => $i->movements->isNotEmpty());
    $budget = $agreement->budget !== null ? (float) $agreement->budget : null;
    $delivered = $agreement->deliveredTotal();
    $balance = $row['balance'];
@endphp

<tbody x-data="{ open: false }" class="border-b border-gray-100 last:border-0 dark:border-white/5">
    <tr class="cursor-pointer hover:bg-gray-50 dark:hover:bg-white/5" x-on:click="open = ! open">
        <td class="py-2 pr-3">
            <span class="text-gray-400" x-text="open ? '▾' : '▸'"></span>
            <span class="font-medium text-gray-950 dark:text-white">{{ $isSale ? '📤' : '📥' }} {{ $agreement->title }}</span>
            <span class="ml-1 text-xs text-gray-500 dark:text-gray-400">
                {{ $isSale ? 'satış' : 'alış' }}{{ $agreement->project ? ' · ' . $agreement->project->name : '' }} · {{ $agreement->agreement_date?->format('d.m.Y') }}
                @if ($agreement->status === \App\Models\Agreement::STATUS_DONE) · <span class="text-success-600 dark:text-success-400">bitti</span>
                @elseif ($agreement->status === \App\Models\Agreement::STATUS_CLOSED) · kapatıldı
                @endif
            </span>
            <button type="button" class="ml-2 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                x-on:click.stop wire:click="mountAction('agreementSummary', { agreement: {{ $agreement->id }} })">Özet</button>
        </td>
        <td class="py-2 pr-3 text-right tabular-nums">
            @if ($agreed > 0) {{ $fmt($agreed) }}
            @else <span class="text-xs text-gray-500 dark:text-gray-400">liste{{ $budget !== null ? ' · bütçe ' . $fmt($budget) : '' }}</span>
            @endif
        </td>
        <td class="py-2 pr-3 text-right tabular-nums">
            {{ $fmt($delivered) }}
            @if ($budget !== null && $delivered > $budget) <span class="text-danger-600 dark:text-danger-400" title="Bütçe aşıldı">⚠</span> @endif
        </td>
        <td class="py-2 pr-3 text-right tabular-nums">{{ $fmt($row['paid']) }}</td>
        <td class="py-2 pr-3 text-right tabular-nums font-medium {{ $balance < -0.009 ? 'text-warning-600 dark:text-warning-400' : 'text-gray-950 dark:text-white' }}">
            {{ \App\Support\AgreementSummary::balanceText($balance, $isSale) }}
        </td>
        <td class="py-2 text-right tabular-nums">{{ $agreed > 0 && $agreement->remainingTotal() > 0 ? $fmt($agreement->remainingTotal()) : '—' }}</td>
    </tr>

    {{-- Kalemler --}}
    <tr x-show="open" x-cloak>
        <td colspan="6" class="pb-3 pl-6">
            <table class="w-full text-xs">
                <tbody>
                    @foreach ($agreement->items as $item)
                        @php
                            $rest = $item->remainingQuantity();
                            $closed = $item->isClosed();
                        @endphp
                        <tr class="{{ $closed ? 'text-gray-400 dark:text-gray-500' : 'text-gray-700 dark:text-gray-300' }}">
                            <td class="py-1 pr-3">{{ $item->name() }} @if ($closed) <span>✓ eski fiyat</span> @endif</td>
                            @if ($item->is_lump)
                                <td class="py-1 pr-3 text-right">götürü</td>
                                <td class="py-1 pr-3 text-right tabular-nums">%{{ (int) round($item->deliveredQuantity() * 100) }} · {{ $fmt($item->deliveredAmount()) }}</td>
                                <td class="py-1 pr-3 text-right tabular-nums {{ $rest < 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">kalan {{ $fmt($item->remainingAmount()) }}</td>
                                <td class="py-1 pr-3 text-right tabular-nums">{{ $fmt($item->unit_price) }}</td>
                            @elseif ($item->isList())
                                <td class="py-1 pr-3 text-right">liste</td>
                                <td class="py-1 pr-3 text-right tabular-nums">gelen {{ $item->quantityLabel($item->deliveredQuantity()) }}</td>
                                <td class="py-1 pr-3 text-right">—</td>
                                <td class="py-1 pr-3 text-right tabular-nums">{{ $fmt($item->unit_price) }}{{ $item->unitName() ? '/' . $item->unitName() : '' }}</td>
                            @else
                                <td class="py-1 pr-3 text-right tabular-nums">{{ $item->quantityLabel((float) $item->quantity) }}</td>
                                <td class="py-1 pr-3 text-right tabular-nums">gelen {{ $item->quantityLabel($item->deliveredQuantity()) }}</td>
                                <td class="py-1 pr-3 text-right tabular-nums {{ $rest < 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">
                                    {{ $closed ? '—' : ($rest < 0 ? '⚠ ' . $item->quantityLabel(abs($rest)) . ' fazla' : 'kalan ' . $item->quantityLabel($rest)) }}
                                </td>
                                <td class="py-1 pr-3 text-right tabular-nums">{{ $fmt($item->unit_price) }}</td>
                            @endif
                            <td class="py-1 text-right whitespace-nowrap">
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
            <div class="mt-2 flex flex-wrap gap-3 text-xs">
                @if ($agreement->project) <span class="text-gray-500 dark:text-gray-400">{{ \App\Support\Labels::project() }}: {{ $agreement->project->name }}</span> @endif
                @if ($agreement->notes) <span class="text-gray-500 dark:text-gray-400">{{ $agreement->notes }}</span> @endif
                @if ($isOpen)
                    <button type="button" class="text-primary-600 hover:underline dark:text-primary-400"
                        wire:click="mountAction('addAgreementItems', { agreement: {{ $agreement->id }} })">+ Kalem ekle</button>
                @endif
                <button type="button" class="text-primary-600 hover:underline dark:text-primary-400"
                    wire:click="mountAction('editAgreement', { agreement: {{ $agreement->id }} })">Düzenle</button>
                <button type="button" class="text-gray-600 hover:underline dark:text-gray-400"
                    wire:click="mountAction('toggleAgreement', { agreement: {{ $agreement->id }} })">
                    {{ $agreement->status === \App\Models\Agreement::STATUS_CLOSED ? 'Yeniden aç' : 'Anlaşmayı kapat' }}
                </button>
                @unless ($hasDeliveries)
                    <button type="button" class="text-danger-600 hover:underline dark:text-danger-400"
                        wire:click="mountAction('deleteAgreement', { agreement: {{ $agreement->id }} })">Anlaşmayı sil</button>
                @endunless
            </div>
        </td>
    </tr>
</tbody>
