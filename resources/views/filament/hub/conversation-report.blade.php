{{-- Hub → Günlük Analiz → rapor. İçerik Claude'un yazdığı markdown (maskeli); HTML kaçışlı. --}}
<x-filament::section>
    <div class="mb-4 text-sm text-gray-500 dark:text-gray-400">
        {{ $report->message_count }} mesaj · {{ $report->firm_count }} firma ·
        {{ $report->sent_at ? 'WhatsApp özeti gönderildi' : 'WhatsApp özeti gönderilmedi' }} ·
        maliyet ${{ \App\Support\Money::format((float) $report->cost_usd, 2) }}
    </div>
    <div class="mb-6 rounded-lg bg-primary-50 p-3 text-sm font-medium text-primary-800 dark:bg-primary-500/10 dark:text-primary-300">
        {{ $report->summary }}
    </div>
    <div class="conversation-report space-y-3 text-sm leading-relaxed text-gray-800 dark:text-gray-200
                [&_h2]:mt-6 [&_h2]:text-base [&_h2]:font-semibold [&_h2]:text-gray-950 dark:[&_h2]:text-white
                [&_strong]:font-semibold [&_em]:text-primary-700 dark:[&_em]:text-primary-400 [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5">
        {!! \Illuminate\Support\Str::markdown($report->body, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
    </div>
    <p class="mt-6 text-xs text-gray-500 dark:text-gray-400">
        Yorumların için Claude'a yaz: "Rapor {{ $report->id }}: 1'i yapalım ama …, 2'yi yapmayalım".
    </p>
</x-filament::section>
