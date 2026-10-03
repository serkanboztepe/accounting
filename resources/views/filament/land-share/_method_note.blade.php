{{-- Cetvelin neye göre hesaplandığı + arsa payı eksikse açık uyarı. $arsa = StudyValidator::arsaSharesState(), $denominator = çalışmanın ortak paydası --}}
@if ($arsa['state'] === 'complete')
    <div class="mb-3 rounded-lg bg-success-50 px-3 py-2 text-sm text-success-700 dark:bg-success-400/10 dark:text-success-400">
        ✓ Arsa payına göre hesaplandı — her BB kendi arsa payı kadar hisse eder.
    </div>
@elseif ($arsa['state'] === 'incomplete')
    <div class="mb-3 rounded-lg bg-warning-50 px-3 py-2 text-sm text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">
        @php
            $missingText = $arsa['missing'] > 0 ? ', ' . $arsa['missing'] . ' BB boş' : '';
        @endphp
        ⚠ Arsa payları eksik (toplam {{ $arsa['sum']->toStringOver($denominator ?? $arsa['denominator']) }}{{ $missingText }}) — cetvel her BB eşit sayılarak hesaplandı. Arsa payına göre hesap için toplam 1/1 olmalı.
    </div>
@else
    <div class="mb-3 text-sm text-gray-500 dark:text-gray-400">
        Her BB eşit sayıldı (arsa payı girilmedi).
    </div>
@endif
