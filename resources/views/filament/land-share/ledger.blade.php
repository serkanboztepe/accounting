@php
    $checks = $this->getChecks();
    $result = $this->getResult();
    $common = $result?->commonDenominator();
@endphp

<x-filament-panels::page>
    {{-- ── Kontroller ──────────────────────────────────────────── --}}
    <x-filament::section icon="heroicon-o-clipboard-document-check" icon-color="primary">
        <x-slot name="heading">Kontroller</x-slot>
        <x-slot name="description">Hesap yapılmadan önce doğrulanması gerekenler.</x-slot>

        <ul class="space-y-2">
            @foreach ($checks as $check)
                <li class="flex items-center gap-2 text-sm">
                    @if ($check['ok'])
                        <x-filament::icon icon="heroicon-s-check-circle" class="h-5 w-5 text-success-500" />
                        <span>{{ $check['label'] }}</span>
                    @else
                        <x-filament::icon icon="heroicon-s-exclamation-triangle" class="h-5 w-5 text-warning-500" />
                        <span class="text-warning-600 dark:text-warning-400">{{ $check['label'] }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-filament::section>

    {{-- ── Tapu Çıktısı ───────────────────────────────────────── --}}
    @if ($result === null)
        <x-filament::section>
            <div class="flex items-center gap-2 text-sm text-warning-600 dark:text-warning-400">
                <x-filament::icon icon="heroicon-s-exclamation-triangle" class="h-5 w-5" />
                Cetvel hesaplanamıyor — yukarıdaki kontrollerdeki eksikleri tamamlayın.
            </div>
        </x-filament::section>
    @else
        <x-filament::section icon="heroicon-o-table-cells">
            <x-slot name="heading">Hisse Dağılım Cetveli</x-slot>
            <x-slot name="description">
                Ortak payda: {{ $common }}
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left dark:border-white/10">
                            <th class="py-2 pr-4 font-semibold">Hissedar</th>
                            <th class="py-2 px-4 text-right font-semibold">Mevcut Hisse</th>
                            <th class="py-2 px-4 text-right font-semibold">Devredilen (Satılan)</th>
                            <th class="py-2 px-4 text-right font-semibold">Kalan (Sade)</th>
                            <th class="py-2 px-4 text-right font-semibold">Kalan (/{{ $common }})</th>
                            <th class="py-2 pl-4 text-right font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($result->rows as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4">
                                    {{ $row->name }}
                                    @if ($row->isContractor)
                                        <x-filament::badge color="info" class="ml-1 inline-flex">Müteahhit</x-filament::badge>
                                    @endif
                                </td>
                                <td class="py-2 px-4 text-right tabular-nums">{{ $row->current }}</td>
                                <td class="py-2 px-4 text-right tabular-nums {{ $row->sold->isZero() ? 'text-gray-400' : 'text-danger-600 dark:text-danger-400' }}">
                                    {{ $row->sold }}
                                </td>
                                <td class="py-2 px-4 text-right font-medium tabular-nums">{{ $row->remaining }}</td>
                                <td class="py-2 px-4 text-right tabular-nums text-gray-500">
                                    {{ $row->remaining->numeratorOver($common) }}/{{ $common }}
                                </td>
                                <td class="py-2 pl-4 text-right">
                                    <button type="button" wire:click="toggleExpand('{{ $row->key }}')"
                                        class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                                        Hesabı Açıkla
                                    </button>
                                </td>
                            </tr>
                            @if (in_array($row->key, $expanded, true))
                                <tr class="bg-gray-50 dark:bg-white/5">
                                    <td colspan="6" class="px-4 py-3">
                                        <ol class="list-decimal space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                                            @foreach ($row->explanation as $step)
                                                <li>{{ $step }}</li>
                                            @endforeach
                                        </ol>
                                    </td>
                                </tr>
                            @endif
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
                                    <x-filament::icon icon="heroicon-s-check-circle" class="ml-1 inline h-4 w-4 text-success-500" />
                                @else
                                    <x-filament::icon icon="heroicon-s-exclamation-triangle" class="ml-1 inline h-4 w-4 text-warning-500" />
                                @endif
                            </td>
                            <td class="py-2 px-4"></td>
                            <td class="py-2 pl-4"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
