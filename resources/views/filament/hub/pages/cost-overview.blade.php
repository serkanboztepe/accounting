{{-- Hub Rapor: üstte bakiyeler (Anthropic tahmini + Twilio gerçek), altta ay listesi ya da seçili ayın firma firma ayrıntısı. --}}
@php
    $usd = fn ($v) => '$' . \App\Support\Money::format((float) $v);
    $try = fn ($v) => $v === null ? '—' : \App\Support\Money::format((float) $v) . ' ₺';
    $signed = fn ($v) => ($v > 0 ? '+' : ($v < 0 ? '−' : '')) . \App\Support\Money::format(abs((float) $v)) . ' ₺';
    $profitClass = fn ($v) => $v < 0 ? 'text-danger-600 dark:text-danger-400' : ($v > 0 ? 'text-success-600 dark:text-success-400' : 'text-gray-500');
    $low = fn ($v) => $v !== null && $v < 5;
@endphp
<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-2">
        <x-filament::section icon="heroicon-o-sparkles" heading="Anthropic (yapay zekâ)">
            <x-slot name="afterHeader">
                <div class="flex gap-3">{{ $this->anthropicTopupAction }} {{ $this->creditAction }}</div>
            </x-slot>
            @if ($credit)
                <div @class(['text-3xl font-semibold', 'text-danger-600 dark:text-danger-400' => $low($credit['remaining']), 'text-gray-950 dark:text-white' => ! $low($credit['remaining'])])>{{ $usd($credit['remaining']) }}</div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Tahmini — Anthropic bakiyeyi dışarı vermiyor. {{ $credit['at']->timezone('Europe/Istanbul')->format('d.m H:i') }}'de {{ $usd($credit['entered']) }}
                    @if ($credit['topups'] > 0) + {{ $usd($credit['topups']) }} yükleme @endif
                    , o andan beri {{ $usd($credit['spent']) }} harcandı.
                </p>
                @if ($low($credit['remaining']))
                    <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">Az kaldı — bitince müşteriler "okuyamadım" cevabı alır. Yükleyip <b>Kredi yükledim</b>.</p>
                @endif
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">Bakiye girilmedi — <b>Bakiye düzelt</b> ile Anthropic Billing'deki tutarı yaz.</p>
            @endif
        </x-filament::section>

        <x-filament::section icon="heroicon-o-chat-bubble-left-right" heading="Twilio (WhatsApp)">
            <x-slot name="afterHeader">{{ $this->twilioTopupAction }}</x-slot>
            @if ($twilio !== null)
                <div @class(['text-3xl font-semibold', 'text-danger-600 dark:text-danger-400' => $low($twilio), 'text-gray-950 dark:text-white' => ! $low($twilio)])>{{ $usd($twilio) }}</div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Gerçek bakiye — Twilio'dan otomatik okunur (10 dk'da bir).</p>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">Twilio'ya ulaşılamadı.</p>
            @endif
            @if ($low($twilio))
                <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">Az kaldı — bitince WhatsApp mesajları gitmez.</p>
            @endif
        </x-filament::section>
    </div>

    @if ($detail)
        @php
            $t = $detail['total'];
        @endphp
        <div class="flex items-center justify-between">
            <x-filament::link :href="request()->url()" icon="heroicon-m-arrow-left">Tüm aylar</x-filament::link>
            <span class="text-xs text-gray-500 dark:text-gray-400">
                @if ($detail['rates']) Kur (TCMB, {{ \Illuminate\Support\Carbon::parse($detail['rates']['date'])->format('d.m.Y') }}): 1 $ = {{ \App\Support\Money::format($detail['rates']['USD']) }} ₺ · 1 € = {{ \App\Support\Money::format($detail['rates']['EUR']) }} ₺
                @else <span class="text-danger-600">Kur alınamadı — dolar giderleri eksik görünür.</span> @endif
            </span>
        </div>

        <x-filament::section :heading="$detail['label'] . ($detail['current'] ? ' — sürüyor' : '')">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                            <th class="py-2 pe-3">Firma</th>
                            <th class="py-2 pe-3">Plan</th>
                            <th class="py-2 pe-3 text-right">Abonelik</th>
                            <th class="py-2 pe-3 text-right">Bekleyen</th>
                            <th class="py-2 pe-3 text-right">Yapay zekâ</th>
                            <th class="py-2 pe-3 text-right">WhatsApp</th>
                            <th class="py-2 pe-3 text-right">Sabit pay</th>
                            <th class="py-2 text-right">Kâr / zarar</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($detail['rows'] as $r)
                            <tr>
                                <td class="py-2 pe-3 text-gray-950 dark:text-white">{{ $r['name'] }}</td>
                                <td class="py-2 pe-3 text-gray-500">{{ $r['plan'] }}</td>
                                <td class="py-2 pe-3 text-right">{{ $try($r['paid']) }}</td>
                                <td class="py-2 pe-3 text-right {{ $r['pending'] > 0 ? 'text-warning-600 dark:text-warning-400' : 'text-gray-400' }}">{{ $r['pending'] > 0 ? $try($r['pending']) : '—' }}</td>
                                <td class="py-2 pe-3 text-right">{{ $try($r['ai']) }}</td>
                                <td class="py-2 pe-3 text-right">{{ $try($r['wa']) }} <span class="text-xs text-gray-500">({{ $r['msgs'] }})</span></td>
                                <td class="py-2 pe-3 text-right">{{ $try($r['fixed']) }}</td>
                                <td class="py-2 text-right font-medium {{ $profitClass($r['profit']) }}">{{ $signed($r['profit']) }}</td>
                            </tr>
                        @endforeach
                        <tr>
                            <td class="py-2 pe-3 text-gray-950 dark:text-white" colspan="4">Geliştirme <span class="text-xs text-gray-500">(testler — firmalara yazılmaz)</span></td>
                            <td class="py-2 pe-3 text-right">{{ $try($detail['dev']) }}</td>
                            <td class="py-2 pe-3 text-right">—</td><td class="py-2 pe-3 text-right">—</td>
                            <td class="py-2 text-right font-medium {{ $profitClass(-$detail['dev']) }}">{{ $signed(-$detail['dev']) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 font-semibold text-gray-950 dark:border-white/20 dark:text-white">
                            <td class="py-3 pe-3" colspan="2">TOPLAM</td>
                            <td class="py-3 pe-3 text-right">{{ $try($t['paid']) }}</td>
                            <td class="py-3 pe-3 text-right">{{ $t['pending'] > 0 ? $try($t['pending']) : '—' }}</td>
                            <td class="py-3 pe-3 text-right">{{ $try($t['ai'] + $detail['dev']) }}</td>
                            <td class="py-3 pe-3 text-right">{{ $try($t['wa']) }} <span class="text-xs font-normal text-gray-500">({{ $t['msgs'] }})</span></td>
                            <td class="py-3 pe-3 text-right">{{ $try($t['fixed']) }}</td>
                            <td class="py-3 text-right {{ $profitClass($t['profit']) }}">{{ $signed($t['profit']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                Abonelik = bu aya düşen ödenmiş tutar (yıllık 12'ye bölünür). Bekleyen = ödenmemiş dönem, kâra girmez. Parantez içi mesaj adedi; mesaj ücreti tahmini (config/costs.php).
            </p>
        </x-filament::section>

        <div class="grid gap-4 md:grid-cols-2">
            <x-filament::section heading="Sabit giderler bu ay" icon="heroicon-o-server-stack">
                <x-slot name="afterHeader">{{ $this->fixedCostsAction }}</x-slot>
                @forelse ($detail['fixed'] as $f)
                    <div class="flex justify-between py-1 text-sm">
                        <span>{{ $f['title'] }} <span class="text-xs text-gray-500">({{ \App\Support\Money::format($f['amount']) }} {{ \App\Models\HubLedgerEntry::CURRENCIES[$f['currency']] ?? $f['currency'] }} / {{ $f['period'] === 'yearly' ? 'yıl' : 'ay' }})</span></span>
                        <span>{{ $try($f['monthly_try']) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">Sabit gider girilmedi.</p>
                @endforelse
            </x-filament::section>
            <x-filament::section heading="Bu ay yüklenen kredi" icon="heroicon-o-banknotes" description="Yalnız bilgi — gidere tekrar eklenmez.">
                @forelse ($detail['topups'] as $u)
                    <div class="flex justify-between py-1 text-sm">
                        <span>{{ $u['provider'] === 'twilio' ? 'Twilio' : 'Anthropic' }} · {{ $u['date'] }} @if ($u['note']) <span class="text-xs text-gray-500">({{ $u['note'] }})</span> @endif</span>
                        <span>{{ $usd($u['amount']) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">Bu ay yükleme yok.</p>
                @endforelse
            </x-filament::section>
        </div>
    @else
        <x-filament::section heading="Aylar">
            <x-slot name="afterHeader">{{ $this->fixedCostsAction }}</x-slot>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                            <th class="py-2 pe-3">Ay</th>
                            <th class="py-2 pe-3 text-right">Gelir (abonelik)</th>
                            <th class="py-2 pe-3 text-right">Bekleyen</th>
                            <th class="py-2 pe-3 text-right">Gider</th>
                            <th class="py-2 pe-3 text-right">Kâr / zarar</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($summary as $s)
                            <tr class="cursor-pointer hover:bg-gray-50 dark:hover:bg-white/5" onclick="window.location='{{ request()->url() }}?ay={{ $s['month'] }}'">
                                <td class="py-3 pe-3 font-medium text-gray-950 dark:text-white">
                                    {{ $s['label'] }}
                                    @if (! $s['started']) <span class="ms-1 text-xs font-normal text-gray-500">(henüz başlamadı)</span>
                                    @elseif ($s['current']) <span class="ms-1 text-xs font-normal text-gray-500">(sürüyor)</span> @endif
                                </td>
                                <td class="py-3 pe-3 text-right">{{ $try($s['paid']) }}</td>
                                <td class="py-3 pe-3 text-right {{ $s['pending'] > 0 ? 'text-warning-600 dark:text-warning-400' : 'text-gray-400' }}">{{ $s['pending'] > 0 ? $try($s['pending']) : '—' }}</td>
                                <td class="py-3 pe-3 text-right">{{ $try($s['cost']) }}</td>
                                <td class="py-3 pe-3 text-right font-semibold {{ $profitClass($s['profit']) }}">{{ $signed($s['profit']) }}</td>
                                <td class="py-3 text-right text-gray-400">›</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Rapor {{ \Illuminate\Support\Carbon::parse($start . '-01')->locale('tr')->translatedFormat('F Y') }}'dan başlar (öncesinde geliştirme masrafı karışık). Aya tıkla → firma firma ayrıntı.</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
