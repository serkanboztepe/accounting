{{-- Yerin sezon sonucu ("Alışı yere yaz" açık firma — çiftlik, 26.10). Hesap: MonthlyResult::forPlace. --}}
@php
    $tl = fn ($v) => \App\Support\Money::format((float) $v) . ' ₺';
@endphp
<x-filament::section heading="Sezon sonucu" icon="heroicon-o-chart-bar"
    :description="$r['season_label'] . ($r['closed'] ? ' · kapandı' : '') . ' — yalnız bu yere yazılanlar; ödeme/tahsilat sayılmaz, stokta kalan mal düşülmez.'">
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-5">
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Satış</div>
            <div class="text-lg font-semibold text-gray-950 dark:text-white">{{ $tl($r['revenue']) }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Alış</div>
            <div class="text-lg font-semibold text-gray-950 dark:text-white">{{ $tl($r['purchases']) }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Personel</div>
            <div class="text-lg font-semibold text-gray-950 dark:text-white">{{ $tl($r['personnel']) }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Gider</div>
            <div class="text-lg font-semibold text-gray-950 dark:text-white">{{ $tl($r['expenses']) }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $r['result'] >= 0 ? 'Kalan' : 'Eksi' }}</div>
            <div class="text-lg font-semibold {{ $r['result'] >= 0 ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">{{ $tl(abs($r['result'])) }}</div>
        </div>
    </div>
    @if ($r['production'] !== [])
        <div class="mt-4 text-sm text-gray-700 dark:text-gray-300">
            🌱 Üretim:
            {{ collect($r['production'])->map(fn ($p) => \App\Support\StockUsage::quantity($p['quantity']) . ($p['unit'] ? ' ' . $p['unit'] : '') . ' ' . $p['name'])->implode(', ') }}
        </div>
    @endif
    @if ($r['general_unallocated'] > 0)
        <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">
            Yere yazılmamış genel gider: {{ $tl($r['general_unallocated']) }} (bu sonuca dahil değil)
        </div>
    @endif
</x-filament::section>
