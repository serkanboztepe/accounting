{{-- Hub firma sayfası: bu ayın kullanım ve maliyet özeti. --}}
@php
    $usd = fn ($v) => '$' . \App\Support\Money::format((float) $v);
@endphp
<x-filament::section :heading="'Bu ay — ' . $monthLabel" icon="heroicon-o-banknotes"
    :description="'Numara kirası ' . $usd(config('costs.twilio_number_monthly')) . '/ay, ' . $activeFirms . ' aktif firmaya bölünür. WhatsApp mesajı 0,005 $ (Twilio faturasıyla doğrulandı).'">
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400">WhatsApp mesaj</div>
            <div class="text-xl font-semibold text-gray-950 dark:text-white">{{ $in + $out + $templates }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $in }} gelen · {{ $out }} cevap · {{ $templates }} hatırlatma</div>
        </div>
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Yapay zekâ</div>
            <div class="text-xl font-semibold text-gray-950 dark:text-white">{{ $usage ? $usd($usage['ai_cost_usd']) : '—' }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                @if ($usage)
                    {{ $usage['ai_calls'] }} çağrı · {{ \App\Support\Money::format($usage['ai_input_tokens'] + $usage['ai_output_tokens'], 0) }} token
                @else
                    kurulumdan alınamadı
                @endif
            </div>
        </div>
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400">WhatsApp</div>
            <div class="text-xl font-semibold text-gray-950 dark:text-white">{{ $usd($waCost) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Twilio + Meta (tahmini)</div>
        </div>
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Toplam</div>
            <div class="text-xl font-semibold text-primary-700 dark:text-primary-400">{{ $usd($total) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">numara payı {{ $usd($numberShare) }} dahil</div>
        </div>
    </div>
</x-filament::section>
