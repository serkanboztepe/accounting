<div class="space-y-6 mt-6">

    {{-- Cari Ekstresi --}}
    <x-filament::section
        heading="Cari Ekstresi"
        :description="'Bakiye ₺' . \App\Support\Money::format(abs($statement['balance'])) . ' — ' . ($statement['balance'] >= 0 ? 'cari bize borçlu' : 'biz cariye borçluyuz')"
        collapsible
    >
        {{-- Filtreler --}}
        @php
            $hasFilter = filled($statementDateFrom) || filled($statementDateTo) || filled($statementProjectId);
        @endphp
        <div class="mb-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Başlangıç</label>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model.live="statementDateFrom" />
                </x-filament::input.wrapper>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Bitiş</label>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model.live="statementDateTo" />
                </x-filament::input.wrapper>
            </div>
            @if (count($statementProjects) > 0)
                <div class="min-w-[12rem]">
                    <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Proje</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="statementProjectId">
                            <option value="">Tüm projeler</option>
                            @foreach ($statementProjects as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            @endif
            @if ($hasFilter)
                <x-filament::button
                    color="gray"
                    icon="heroicon-o-x-mark"
                    wire:click="clearStatementFilters"
                >
                    Temizle
                </x-filament::button>
            @endif

            {{-- Yazdır butonu en sağda --}}
            <x-filament::button
                tag="a"
                :href="$this->statementPrintUrl()"
                target="_blank"
                icon="heroicon-o-printer"
                color="primary"
                class="ml-auto"
            >
                Ekstre Yazdır
            </x-filament::button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3 text-left font-medium">Tarih</th>
                        <th class="py-2 px-3 text-left font-medium">Açıklama</th>
                        <th class="py-2 px-3 text-right font-medium">Borç</th>
                        <th class="py-2 px-3 text-right font-medium">Alacak</th>
                        <th class="py-2 pl-3 text-right font-medium">Bakiye</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($statement['rows'] as $r)
                        <tr>
                            <td class="py-2 pr-3 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $r['date'] }}</td>
                            <td class="py-2 px-3">
                                <span class="text-gray-950 dark:text-white">{{ $r['desc'] }}</span>
                                <span class="text-xs text-gray-400">· {{ $r['label'] }}</span>
                            </td>
                            <td class="py-2 px-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ $r['borc'] > 0 ? '₺' . \App\Support\Money::format($r['borc']) : '' }}</td>
                            <td class="py-2 px-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ $r['alacak'] > 0 ? '₺' . \App\Support\Money::format($r['alacak']) : '' }}</td>
                            <td class="py-2 pl-3 text-right tabular-nums font-medium text-gray-950 dark:text-white">₺{{ \App\Support\Money::format($r['balance']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-gray-500 dark:text-gray-400">Hareket yok.</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-gray-300 font-semibold text-gray-950 dark:border-white/20 dark:text-white">
                        <td class="py-2 pr-3" colspan="2">Toplam</td>
                        <td class="py-2 px-3 text-right tabular-nums">₺{{ \App\Support\Money::format($statement['total_borc']) }}</td>
                        <td class="py-2 px-3 text-right tabular-nums">₺{{ \App\Support\Money::format($statement['total_alacak']) }}</td>
                        <td class="py-2 pl-3 text-right tabular-nums">₺{{ \App\Support\Money::format($statement['balance']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-filament::section>

    {{-- Aktivite Zaman Çizgisi --}}
    @if (count($timeline) > 0)
        @php
            $colorMap = [
                'gray'    => ['bg' => '#f3f4f6', 'fg' => '#4b5563', 'dot' => '#6b7280'],
                'info'    => ['bg' => '#dbeafe', 'fg' => '#1d4ed8', 'dot' => '#3b82f6'],
                'warning' => ['bg' => '#fef3c7', 'fg' => '#b45309', 'dot' => '#f59e0b'],
                'success' => ['bg' => '#dcfce7', 'fg' => '#15803d', 'dot' => '#10b981'],
                'amber'   => ['bg' => '#fef3c7', 'fg' => '#b45309', 'dot' => '#d97706'],
                'rose'    => ['bg' => '#ffe4e6', 'fg' => '#be123c', 'dot' => '#f43f5e'],
            ];
        @endphp
        <x-filament::section
            heading="Aktivite Zaman Çizgisi"
            :description="count($timeline) . ' kayıt'"
            collapsible
            collapsed
            compact
        >
            <div class="space-y-2">
                @foreach ($timeline as $event)
                    @php $c = $colorMap[$event['color']] ?? $colorMap['gray']; @endphp
                    <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-white/10 dark:bg-white/5"
                         style="border-left: 3px solid {{ $c['dot'] }};">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 md:flex-nowrap">
                            <div class="w-20 shrink-0 text-xs text-gray-500 dark:text-gray-400">
                                {{ $event['date'] }}
                            </div>

                            <span class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                                  style="background:{{ $c['bg'] }};color:{{ $c['fg'] }};">
                                {{ $event['label'] }}
                            </span>

                            <div class="min-w-0 flex-1">
                                @if ($event['title'])
                                    <div class="truncate text-sm font-medium text-gray-900 dark:text-white">
                                        {{ $event['title'] }}
                                    </div>
                                @endif
                                @if (!empty($event['subtitle']))
                                    <div class="truncate text-xs text-gray-500 dark:text-gray-400">
                                        {{ $event['subtitle'] }}
                                    </div>
                                @endif
                            </div>

                            @if ($event['amount'] > 0)
                                <div class="shrink-0 text-sm font-semibold text-gray-900 dark:text-white md:text-right">
                                    ₺{{ \App\Support\Money::format($event['amount']) }}
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{-- Sözleşmeler --}}
    <x-filament::section heading="Sözleşmeler">
        <div class="space-y-3">
            @forelse ($contracts as $row)
                <x-filament::section
                    :heading="$row['title']"
                    :description="($row['project'] ?? 'Projesiz') . ' · ₺' . \App\Support\Money::format($row['total'])"
                    collapsible
                    collapsed
                    compact
                >
                    <div style="display:flex;flex-direction:column;gap:0.5rem;">

                        {{-- Ödeme satırı --}}
                        <div style="display:flex;flex-wrap:wrap;column-gap:1.5rem;row-gap:0.25rem;align-items:center;">
                            <div class="flex items-center gap-1.5">
                                <span class="text-xs text-gray-500 dark:text-gray-400">Durum</span>
                                <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">
                                    @if ($row['status'] === 'draft') Taslak
                                    @elseif ($row['status'] === 'active') Aktif
                                    @elseif ($row['status'] === 'completed') Tamamlandı
                                    @elseif ($row['status'] === 'cancelled') İptal
                                    @else {{ $row['status'] }}
                                    @endif
                                </span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span class="text-xs text-gray-500 dark:text-gray-400">Toplam</span>
                                <span class="text-sm font-medium text-gray-950 dark:text-white">₺{{ \App\Support\Money::format($row['total']) }}</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span class="text-xs text-gray-500 dark:text-gray-400">Ödenen</span>
                                <span class="text-sm font-semibold text-green-700 dark:text-green-400">₺{{ \App\Support\Money::format($row['paid']) }}</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span class="text-xs text-gray-500 dark:text-gray-400">Kalan</span>
                                <span class="text-sm font-semibold text-orange-600 dark:text-orange-400">₺{{ \App\Support\Money::format($row['remaining']) }}</span>
                            </div>
                        </div>

                        {{-- Fatura satırı --}}
                        <div style="display:flex;flex-wrap:wrap;column-gap:1.5rem;row-gap:0.25rem;align-items:center;">
                            <div class="flex items-center gap-1.5">
                                <span class="text-xs text-gray-500 dark:text-gray-400">Fatura</span>
                                @if ($row['invoice_status'] === 'complete')
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" style="background:#dcfce7;color:#15803d;">Tamamlandı</span>
                                @elseif ($row['invoice_status'] === 'partial')
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" style="background:#fef3c7;color:#b45309;">Kısmi</span>
                                @else
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" style="background:#f3f4f6;color:#6b7280;">Fatura Yok</span>
                                @endif
                            </div>
                            @if ($row['invoiced'] > 0)
                                <div class="flex items-center gap-1.5">
                                    <span class="text-xs text-gray-500 dark:text-gray-400">Faturalanan</span>
                                    <span class="text-sm font-medium text-gray-950 dark:text-white">₺{{ \App\Support\Money::format($row['invoiced']) }}</span>
                                </div>
                            @endif
                            @if ($row['uninvoiced'] > 0)
                                <div class="flex items-center gap-1.5">
                                    <span class="text-xs text-gray-500 dark:text-gray-400">Faturasız</span>
                                    <span class="text-sm font-semibold text-orange-600 dark:text-orange-400">₺{{ \App\Support\Money::format($row['uninvoiced']) }}</span>
                                </div>
                            @endif
                        </div>

                        {{-- Teslimat proje dağılımı --}}
                        @if ($row['has_deliveries'])
                            <div class="pt-1">
                                <div class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">Teslimat Dağılımı</div>
                                <div class="space-y-1">
                                    @foreach ($row['delivery_by_project'] as $d)
                                        <div style="display:flex;flex-wrap:wrap;column-gap:1.5rem;align-items:center;">
                                            <span class="text-sm text-gray-700 dark:text-gray-300" style="min-width:10rem;">{{ $d['project'] }}</span>
                                            @if ($d['quantity'] > 0)
                                                <span class="text-sm text-gray-500 dark:text-gray-400">
                                                    {{ \App\Support\Money::format($d['quantity']) }} {{ $d['unit'] }}
                                                </span>
                                            @endif
                                            <span class="text-sm font-medium text-gray-950 dark:text-white">
                                                ₺{{ \App\Support\Money::format($d['amount']) }}
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                    </div>
                </x-filament::section>
            @empty
                <div class="rounded-2xl border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
                    Sözleşme yok.
                </div>
            @endforelse
        </div>
    </x-filament::section>

    {{-- Faturalar --}}
    @if (count($invoices) > 0)
        <x-filament::section heading="Faturalar">
            <div class="space-y-3">
                <div class="hidden rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400 lg:grid lg:grid-cols-12 lg:gap-x-3">
                    <div class="col-span-2">Tarih</div>
                    <div class="col-span-2">Fatura No</div>
                    <div class="col-span-5">Sözleşme</div>
                    <div class="col-span-2 text-right">Tutar</div>
                    <div class="col-span-1 text-right">PDF</div>
                </div>
                @foreach ($invoices as $row)
                    <div class="rounded-2xl border border-gray-200 bg-white px-4 py-4 shadow-sm dark:border-white/10 dark:bg-white/5 lg:grid lg:grid-cols-12 lg:items-center lg:gap-x-3">
                        <div class="mb-2 lg:col-span-2 lg:mb-0">
                            <div class="text-xs uppercase tracking-wide text-gray-400 lg:hidden">Tarih</div>
                            <div class="text-sm text-gray-700 dark:text-gray-300">{{ $row['date'] ?? '-' }}</div>
                        </div>
                        <div class="mb-2 lg:col-span-2 lg:mb-0">
                            <div class="text-xs uppercase tracking-wide text-gray-400 lg:hidden">Fatura No</div>
                            <div class="text-sm font-medium text-gray-950 dark:text-white">{{ $row['invoice_number'] ?: '-' }}</div>
                        </div>
                        <div class="mb-2 min-w-0 lg:col-span-5 lg:mb-0">
                            <div class="text-xs uppercase tracking-wide text-gray-400 lg:hidden">Sözleşme</div>
                            <div class="truncate text-sm text-gray-700 dark:text-gray-300">{{ $row['contract_title'] }}</div>
                        </div>
                        <div class="mb-2 lg:col-span-2 lg:mb-0 lg:text-right">
                            <div class="text-xs uppercase tracking-wide text-gray-400 lg:hidden">Tutar</div>
                            <div class="text-sm font-semibold text-gray-950 dark:text-white">₺{{ \App\Support\Money::format($row['amount']) }}</div>
                        </div>
                        <div class="lg:col-span-1 lg:text-right">
                            @if ($row['has_pdf'])
                                <a href="{{ $row['pdf_url'] }}" target="_blank" class="inline-flex items-center gap-1 text-sm font-medium text-amber-700 hover:text-amber-800 dark:text-amber-400 dark:hover:text-amber-300">
                                    Aç
                                </a>
                            @else
                                <span class="text-xs text-gray-300 dark:text-gray-600">-</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{-- Direkt Giderler --}}
    @if (count($expenses) > 0)
        <x-filament::section heading="Direkt Giderler">
            <div class="space-y-3">
                @foreach ($expenses as $group)
                    <x-filament::section
                        :heading="$group['project']"
                        :description="'₺' . \App\Support\Money::format($group['total']) . ' · ' . count($group['items']) . ' kayıt'"
                        collapsible
                        collapsed
                        compact
                    >
                        <div class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($group['items'] as $item)
                                <div class="flex items-center gap-2 py-3">
                                    <span class="w-20 shrink-0 text-xs text-gray-400">{{ $item['date'] }}</span>
                                    <span class="shrink-0 text-xs text-gray-300 dark:text-gray-600">-</span>
                                    <span class="min-w-0 flex-1 truncate text-sm text-gray-700 dark:text-gray-300">
                                        {{ $item['description'] ?: ($item['category'] ?: '-') }}
                                        @if ($item['category'])
                                            <span class="text-xs text-gray-400">· {{ $item['category'] }}</span>
                                        @endif
                                    </span>
                                    <span class="shrink-0 text-right text-sm font-medium text-gray-950 dark:text-white">
                                        ₺{{ \App\Support\Money::format($item['amount']) }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </x-filament::section>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{-- Çekler --}}
    @if (count($checks) > 0)
        <x-filament::section heading="Çekler">
            <div class="space-y-3">
                <div class="hidden rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400 lg:grid lg:grid-cols-12 lg:gap-x-3">
                    <div class="col-span-4">Proje</div>
                    <div class="col-span-3">Vade</div>
                    <div class="col-span-3 text-right">Tutar</div>
                    <div class="col-span-2 text-right">Durum</div>
                </div>
                @foreach ($checks as $row)
                    <div class="rounded-2xl border border-gray-200 bg-white px-4 py-4 shadow-sm dark:border-white/10 dark:bg-white/5 lg:grid lg:grid-cols-12 lg:items-center lg:gap-x-3">
                        <div class="mb-2 min-w-0 lg:col-span-4 lg:mb-0">
                            <div class="text-xs uppercase tracking-wide text-gray-400 lg:hidden">Proje</div>
                            <div class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $row['project'] }}</div>
                        </div>
                        <div class="mb-2 lg:col-span-3 lg:mb-0">
                            <div class="text-xs uppercase tracking-wide text-gray-400 lg:hidden">Vade</div>
                            <div class="text-sm text-gray-700 dark:text-gray-300">{{ $row['due_date'] }}</div>
                        </div>
                        <div class="mb-2 lg:col-span-3 lg:mb-0 lg:text-right">
                            <div class="text-xs uppercase tracking-wide text-gray-400 lg:hidden">Tutar</div>
                            <div class="text-sm font-semibold text-gray-950 dark:text-white">₺{{ \App\Support\Money::format($row['amount']) }}</div>
                        </div>
                        <div class="lg:col-span-2 lg:text-right">
                            <div class="text-xs uppercase tracking-wide text-gray-400 lg:hidden">Durum</div>
                            <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">
                                @if ($row['status'] === 'portfolio') Portföyde
                                @elseif ($row['status'] === 'issued') Verildi
                                @elseif ($row['status'] === 'collected') Tahsil Edildi
                                @elseif ($row['status'] === 'paid') Ödendi
                                @elseif ($row['status'] === 'cancelled') İptal
                                @elseif ($row['status'] === 'bounced') Karşılıksız
                                @else {{ $row['status'] }}
                                @endif
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

</div>
