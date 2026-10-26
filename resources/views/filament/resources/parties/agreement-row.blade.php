{{-- Tek anlaşma: özet satırı (Anlaşılan · Gelen · Ödenen · Borç · Gelecek mal). Tıklayınca yandan anlaşma penceresi açılır (27.10). --}}
@php
    $agreement = $row['agreement'];
    $fmt = fn ($v) => \App\Support\Money::format((float) $v);
    $isSale = ! $agreement->isPurchase();
    $agreed = $agreement->agreedTotal();
    $budget = $agreement->budget !== null ? (float) $agreement->budget : null;
    $delivered = $agreement->deliveredTotal();
    $balance = $row['balance'];
@endphp

<tbody class="border-b border-gray-100 last:border-0 dark:border-white/5">
    <tr class="cursor-pointer hover:bg-gray-50 dark:hover:bg-white/5"
        wire:click="mountAction('agreementSummary', { agreement: {{ $agreement->id }} })">
        <td class="py-2 pr-3">
            <span class="font-medium text-gray-950 dark:text-white">{{ $isSale ? '📤' : '📥' }} {{ $agreement->title }}</span>
            <span class="ml-1 text-xs text-gray-500 dark:text-gray-400">
                {{ $isSale ? 'satış' : 'alış' }}{{ $agreement->project ? ' · ' . $agreement->project->name : '' }} · {{ $agreement->agreement_date?->format('d.m.Y') }}
                @if ($agreement->status === \App\Models\Agreement::STATUS_DONE) · <span class="text-success-600 dark:text-success-400">bitti</span>
                @elseif ($agreement->status === \App\Models\Agreement::STATUS_CLOSED) · kapatıldı
                @endif
            </span>
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
</tbody>
