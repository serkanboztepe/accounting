<div class="overflow-x-auto">
    <div class="mb-2 text-sm text-gray-500 dark:text-gray-400">
        Ortak payda: {{ $common }}
    </div>
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-left dark:border-white/10">
                <th class="py-2 pr-4 font-semibold">Hissedar</th>
                <th class="py-2 px-4 text-right font-semibold">Mevcut</th>
                <th class="py-2 px-4 text-right font-semibold">Devredilen</th>
                <th class="py-2 px-4 text-right font-semibold">Kalan (Sade)</th>
                <th class="py-2 pl-4 text-right font-semibold">Kalan (/{{ $common }})</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($result->rows as $row)
                <tr class="border-b border-gray-100 dark:border-white/5">
                    <td class="py-2 pr-4">
                        {{ $row->name }}
                        @if ($row->isContractor)
                            <span class="ml-1 rounded bg-info-100 px-1.5 py-0.5 text-xs text-info-700 dark:bg-info-400/10 dark:text-info-400">Müteahhit</span>
                        @endif
                    </td>
                    <td class="py-2 px-4 text-right tabular-nums">{{ $row->current }}</td>
                    <td class="py-2 px-4 text-right tabular-nums {{ $row->sold->isZero() ? 'text-gray-400' : 'text-danger-600 dark:text-danger-400' }}">{{ $row->sold }}</td>
                    <td class="py-2 px-4 text-right font-medium tabular-nums">{{ $row->remaining }}</td>
                    <td class="py-2 pl-4 text-right tabular-nums text-gray-500">{{ $row->remaining->numeratorOver($common) }}/{{ $common }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="border-t-2 border-gray-300 font-semibold dark:border-white/20">
                <td class="py-2 pr-4">TOPLAM</td>
                <td class="py-2 px-4"></td>
                <td class="py-2 px-4 text-right tabular-nums">{{ $result->totalSold() }}</td>
                <td class="py-2 px-4 text-right tabular-nums">
                    {{ $result->totalRemaining() }}
                    @if ($result->isBalanced())
                        <span class="text-success-600 dark:text-success-400">✓</span>
                    @else
                        <span class="text-warning-600 dark:text-warning-400">⚠</span>
                    @endif
                </td>
                <td class="py-2 pl-4"></td>
            </tr>
        </tfoot>
    </table>
</div>
