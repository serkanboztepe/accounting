<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            {{ $this->form }}
        </x-filament::section>

        @php($project = $this->getSelectedProject())

        @if (! $project)
            <x-filament::section>
                <div class="rounded-xl border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
                    Lütfen bir proje seçin.
                </div>
            </x-filament::section>
        @else
            @php($summary = $this->getSummaryStats())

            @php($m = fn ($v) => '₺' . \App\Support\Money::format($v))

            {{-- Defter tablo özet — kendi style bloğuyla, Tailwind derlemesinden bağımsız --}}
            <style>
                .pr-summary {
                    --pr-card: #ffffff; --pr-border: #e9e4db; --pr-ink: #1b1915;
                    --pr-muted: #8b8474; --pr-accent: #b45309;
                    --pr-good: #15803d; --pr-warn: #c2410c; --pr-pend: #b7791f;
                    background: var(--pr-card); border: 1px solid var(--pr-border);
                    border-radius: 14px; padding: 22px 24px; color: var(--pr-ink);
                }
                .dark .pr-summary {
                    --pr-card: rgba(255,255,255,.03); --pr-border: rgba(255,255,255,.1); --pr-ink: #f2efe7;
                    --pr-muted: #9a9280; --pr-accent: #f5a524;
                    --pr-good: #4ade80; --pr-warn: #fb923c; --pr-pend: #e0b04a;
                }
                .pr-summary .tnum { font-variant-numeric: tabular-nums; }
                .pr-top { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 12px; }
                .pr-eyebrow { font-size: 11px; text-transform: uppercase; letter-spacing: .1em; color: var(--pr-muted); font-weight: 600; }
                .pr-hero { font-size: 32px; font-weight: 700; letter-spacing: -.02em; line-height: 1.1; margin-top: 3px; }
                .pr-break { font-size: 12.5px; color: var(--pr-muted); }
                .pr-break b { color: var(--pr-ink); font-weight: 600; }
                .pr-grid { margin-top: 20px; border-top: 1px solid var(--pr-border); display: grid; grid-template-columns: 1fr 1fr; }
                .pr-col { padding-top: 14px; }
                .pr-col:first-child { padding-right: 24px; }
                .pr-col:last-child { border-left: 1px solid var(--pr-border); padding-left: 24px; }
                .pr-colhead { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: var(--pr-accent); font-weight: 700; margin-bottom: 6px; }
                .pr-row { display: flex; justify-content: space-between; align-items: baseline; padding: 8px 0; border-bottom: 1px solid var(--pr-border); }
                .pr-row:last-child { border-bottom: 0; }
                .pr-row .pr-k { font-size: 13.5px; color: var(--pr-muted); }
                .pr-row .pr-v { font-size: 14.5px; font-weight: 600; }
                .pr-row.pr-hi .pr-k { color: var(--pr-ink); font-weight: 600; }
                .pr-good { color: var(--pr-good) !important; }
                .pr-warn { color: var(--pr-warn) !important; }
                .pr-pend { color: var(--pr-pend) !important; }
                .pr-foot { margin-top: 14px; font-size: 12px; color: var(--pr-muted); }
                @media (max-width: 560px) {
                    .pr-grid { grid-template-columns: 1fr; }
                    .pr-col:first-child { padding-right: 0; }
                    .pr-col:last-child { border-left: 0; border-top: 1px solid var(--pr-border); padding-left: 0; margin-top: 8px; }
                }
            </style>

            <div class="pr-summary">
                <div class="pr-top">
                    <div>
                        <div class="pr-eyebrow">Toplam Proje Maliyeti</div>
                        <div class="pr-hero tnum">{{ $m($summary['total_cost']) }}</div>
                    </div>
                    <div class="pr-break tnum">Direkt gider <b>{{ $m($summary['direct_expenses']) }}</b> &nbsp;+&nbsp; Teslimat <b>{{ $m($summary['delivery_cost']) }}</b></div>
                </div>

                <div class="pr-grid">
                    <div class="pr-col">
                        <div class="pr-colhead">Sözleşme &amp; Ödeme</div>
                        <div class="pr-row"><span class="pr-k">Sözleşme Toplamı</span><span class="pr-v tnum">{{ $m($summary['contracts_total']) }}</span></div>
                        <div class="pr-row"><span class="pr-k">Ödenen</span><span class="pr-v tnum pr-good">{{ $m($summary['paid_payments']) }}</span></div>
                        @if ($summary['pending_checks'] > 0)
                            <div class="pr-row"><span class="pr-k">Bekleyen çek</span><span class="pr-v tnum pr-pend">{{ $m($summary['pending_checks']) }}</span></div>
                        @endif
                        <div class="pr-row pr-hi"><span class="pr-k">Kalan Bakiye</span><span class="pr-v tnum pr-warn">{{ $m($summary['remaining_contract_balance']) }}</span></div>
                    </div>
                    <div class="pr-col">
                        <div class="pr-colhead">Fatura</div>
                        <div class="pr-row"><span class="pr-k">Teslimat Maliyeti</span><span class="pr-v tnum">{{ $m($summary['delivery_cost']) }}</span></div>
                        <div class="pr-row"><span class="pr-k">Faturalanan</span><span class="pr-v tnum">{{ $m($summary['invoiced_total']) }}</span></div>
                        <div class="pr-row pr-hi"><span class="pr-k">Faturasız</span><span class="pr-v tnum pr-warn">{{ $m($summary['uninvoiced_total']) }}</span></div>
                    </div>
                </div>

                <div class="pr-foot tnum">Ödenen = nakit/EFT + tahsil edilmiş çek · Toplam çek {{ $m($summary['checks_total']) }} · Sözleşme/Kalan çapraz proje hariç, Teslimat dahil</div>
            </div>

            {{-- Satış / Alacak — yalnız projenin satış sözleşmesi varsa. Maliyetle ASLA toplanmaz. --}}
            @php($sales = $this->getSalesStats())
            @if ($sales)
                <div class="pr-summary">
                    <div class="pr-top">
                        <div>
                            <div class="pr-eyebrow">Brüt (Satış − Maliyet)</div>
                            <div class="pr-hero tnum {{ $sales['gross'] >= 0 ? 'pr-good' : 'pr-warn' }}">{{ $m($sales['gross']) }}</div>
                        </div>
                        <div class="pr-break tnum">Satış <b>{{ $m($sales['sales_total']) }}</b> &nbsp;−&nbsp; Maliyet <b>{{ $m($sales['cost']) }}</b></div>
                    </div>

                    <div class="pr-grid">
                        <div class="pr-col">
                            <div class="pr-colhead">Satış &amp; Tahsilat</div>
                            <div class="pr-row"><span class="pr-k">Satış Toplamı</span><span class="pr-v tnum">{{ $m($sales['sales_total']) }}</span></div>
                            <div class="pr-row"><span class="pr-k">Tahsil Edilen</span><span class="pr-v tnum pr-good">{{ $m($sales['collected']) }}</span></div>
                            @if ($sales['pending_checks'] > 0)
                                <div class="pr-row"><span class="pr-k">Bekleyen çek</span><span class="pr-v tnum pr-pend">{{ $m($sales['pending_checks']) }}</span></div>
                            @endif
                            <div class="pr-row pr-hi"><span class="pr-k">Kalan Alacak</span><span class="pr-v tnum pr-warn">{{ $m($sales['remaining']) }}</span></div>
                        </div>
                        <div class="pr-col">
                            <div class="pr-colhead">Karşılaştırma</div>
                            <div class="pr-row"><span class="pr-k">Toplam Proje Maliyeti</span><span class="pr-v tnum">{{ $m($sales['cost']) }}</span></div>
                            <div class="pr-row"><span class="pr-k">Satış Geliri</span><span class="pr-v tnum">{{ $m($sales['sales_total']) }}</span></div>
                            <div class="pr-row pr-hi"><span class="pr-k">Brüt Sonuç</span><span class="pr-v tnum {{ $sales['gross'] >= 0 ? 'pr-good' : 'pr-warn' }}">{{ $m($sales['gross']) }}</span></div>
                        </div>
                    </div>

                    <div class="pr-foot tnum">Satış (alacak) tarafı maliyet toplamlarına dahil değildir · Tahsil Edilen = nakit/EFT + tahsil edilmiş çek · Brüt = Satış − Toplam Proje Maliyeti</div>
                </div>
            @endif

            <div class="grid gap-6 xl:grid-cols-2">
                <x-filament::section heading="Direkt Giderler">
                    @php($expenseGroups = $this->getExpenseRows())
                    <div class="space-y-3">
                        @forelse ($expenseGroups as $group)
                            <x-filament::section
                                :heading="$group['category']"
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
                                                {{ $item['description'] ?: ($item['party'] ?: '-') }}
                                                @if ($item['description'] && $item['party'])
                                                    <span class="text-xs text-gray-400">· {{ $item['party'] }}</span>
                                                @endif
                                            </span>
                                            <span class="shrink-0 text-xs text-gray-300 dark:text-gray-600">-</span>
                                            <span class="shrink-0 text-right text-sm font-medium text-gray-950 dark:text-white">
                                                ₺{{ \App\Support\Money::format($item['amount']) }}
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            </x-filament::section>
                        @empty
                            <div class="rounded-2xl border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
                                Kayıt yok.
                            </div>
                        @endforelse

                        @if (count($expenseGroups) > 1)
                            <div class="flex items-center justify-between rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                                <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">Genel Toplam</span>
                                <span class="text-sm font-bold text-gray-950 dark:text-white">
                                    ₺{{ \App\Support\Money::format(array_sum(array_column($expenseGroups, 'total'))) }}
                                </span>
                            </div>
                        @endif
                    </div>
                </x-filament::section>

                <x-filament::section heading="Çekler">
                    <div class="space-y-3">
                        <div class="hidden rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400 lg:grid lg:grid-cols-12 lg:gap-x-3">
                            <div class="col-span-5">Cari</div>
                            <div class="col-span-3">Vade</div>
                            <div class="col-span-2 text-right">Tutar</div>
                            <div class="col-span-2 text-right">Durum</div>
                        </div>

                        @forelse ($this->getCheckRows() as $row)
                            <div class="rounded-2xl border border-gray-200 bg-white px-4 py-4 shadow-sm dark:border-white/10 dark:bg-white/5 lg:grid lg:grid-cols-12 lg:items-center lg:gap-x-3">
                                <div class="mb-2 min-w-0 overflow-hidden lg:col-span-5 lg:mb-0">
                                    <div class="text-xs uppercase tracking-wide text-gray-400 lg:hidden">Cari</div>
                                    <div class="truncate font-medium text-gray-950 dark:text-white">{{ $row['party'] }}</div>
                                </div>
                                <div class="mb-2 lg:col-span-3 lg:mb-0">
                                    <div class="text-xs uppercase tracking-wide text-gray-400 lg:hidden">Vade</div>
                                    <div class="text-sm text-gray-700 dark:text-gray-300">{{ $row['due_date'] }}</div>
                                </div>
                                <div class="mb-2 lg:col-span-2 lg:mb-0 lg:text-right">
                                    <div class="text-xs uppercase tracking-wide text-gray-400 lg:hidden">Tutar</div>
                                    <div class="text-sm font-semibold text-gray-950 dark:text-white">₺{{ \App\Support\Money::format($row['amount']) }}</div>
                                </div>
                                <div class="lg:col-span-2 lg:text-right">
                                    <div class="text-xs uppercase tracking-wide text-gray-400 lg:hidden">Durum</div>
                                    <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">
                                        {{ match($row['status']) {
                                            'portfolio' => 'Portföyde',
                                            'issued'    => 'Verildi',
                                            'collected' => 'Tahsil Edildi',
                                            'paid'      => 'Ödendi',
                                            'cancelled' => 'İptal',
                                            'bounced'   => 'Karşılıksız',
                                            default     => $row['status'],
                                        } }}
                                    </span>
                                </div>
                            </div>
                        @empty
                            <div class="rounded-2xl border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
                                Kayıt yok.
                            </div>
                        @endforelse
                    </div>
                </x-filament::section>
            </div>

            <x-filament::section heading="Sözleşme Teslimatları">
                @php($deliveryGroups = $this->getDeliveryRows())
                <div class="space-y-3">
                    @forelse ($deliveryGroups as $group)
                        <x-filament::section
                            :heading="$group['contract_title']"
                            :description="$group['description']"
                            collapsible
                            collapsed
                            compact
                        >
                            <div class="divide-y divide-gray-100 dark:divide-white/5">
                                <div class="flex gap-2 pb-2 text-xs font-medium uppercase tracking-wide text-gray-400">
                                    <span class="flex-1">Malzeme / Kalem</span>
                                    <span class="w-20 shrink-0 text-right">Teslimat</span>
                                    <span class="w-28 shrink-0 text-right">Toplam Miktar</span>
                                    <span class="w-28 shrink-0 text-right">Tutar</span>
                                </div>
                                @foreach ($group['materials'] as $material)
                                    <div class="flex items-center gap-2 py-3">
                                        <span class="flex-1 truncate text-sm font-medium text-gray-800 dark:text-gray-200" title="{{ $material['name'] }}">
                                            {{ $material['name'] }}
                                        </span>
                                        <span class="w-20 shrink-0 text-right text-sm text-gray-500 dark:text-gray-400 tnum">
                                            {{ $material['delivery_count'] }}
                                        </span>
                                        <span class="w-28 shrink-0 text-right text-sm text-gray-700 dark:text-gray-300 tnum">
                                            @if ($material['unit_mixed'])
                                                <span class="text-gray-400" title="Karışık birim, miktar toplanamadı">karışık birim</span>
                                            @else
                                                {{ \App\Support\Money::format($material['quantity']) }} {{ $material['unit'] }}
                                            @endif
                                        </span>
                                        <span class="w-28 shrink-0 text-right text-sm font-semibold text-gray-950 dark:text-white tnum">
                                            ₺{{ \App\Support\Money::format($material['amount']) }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </x-filament::section>
                    @empty
                        <div class="rounded-2xl border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
                            Kayıt yok.
                        </div>
                    @endforelse

                    @if (count($deliveryGroups) > 0)
                        <div class="flex items-center justify-between rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                            <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">Genel Toplam</span>
                            <span class="text-sm font-bold text-gray-950 dark:text-white">
                                ₺{{ \App\Support\Money::format(array_sum(array_column($deliveryGroups, 'total_amount'))) }}
                            </span>
                        </div>
                    @endif
                </div>
            </x-filament::section>

            <x-filament::section heading="Sözleşmeler">
                <div class="space-y-3">
                    @forelse ($this->getContractRows() as $row)
                        <x-filament::section
                            :heading="$row['title']"
                            :description="($row['party'] ? $row['party'] . ' · ' : '') . '₺' . \App\Support\Money::format($row['total'])"
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
                                            {{ match($row['status']) {
                                                'draft'     => 'Taslak',
                                                'active'    => 'Aktif',
                                                'completed' => 'Tamamlandı',
                                                'cancelled' => 'İptal',
                                                default     => $row['status'],
                                            } }}
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-xs text-gray-500 dark:text-gray-400">Toplam</span>
                                        <span class="text-sm font-medium text-gray-950 dark:text-white">₺{{ \App\Support\Money::format($row['total']) }}</span>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-xs text-gray-500 dark:text-gray-400">Ödenen</span>
                                        <span class="text-sm font-semibold text-green-700 dark:text-green-400">₺{{ \App\Support\Money::format($row['paid']) }}</span>
                                        <span class="text-xs text-gray-400 dark:text-gray-500">(nakit/EFT + tahsil çek)</span>
                                    </div>
                                    @if ($row['pending'] > 0)
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-xs text-gray-500 dark:text-gray-400">Bekleyen çek</span>
                                            <span class="text-sm font-semibold text-orange-500">₺{{ \App\Support\Money::format($row['pending']) }}</span>
                                        </div>
                                    @endif
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-xs text-gray-500 dark:text-gray-400">Kalan</span>
                                        <span class="text-sm font-semibold text-orange-600 dark:text-orange-400">₺{{ \App\Support\Money::format($row['remaining']) }}</span>
                                    </div>
                                </div>

                                {{-- Kalem sayısı --}}
                                @if ($row['item_count'] > 0)
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-xs text-gray-500 dark:text-gray-400">Kalemler</span>
                                        <span class="text-sm text-gray-700 dark:text-gray-300">{{ $row['item_count'] }} kalem</span>
                                    </div>
                                @endif

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

                            </div>
                        </x-filament::section>
                    @empty
                        <div class="col-span-3 rounded-2xl border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
                            Kayıt yok.
                        </div>
                    @endforelse
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
