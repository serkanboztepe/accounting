{{-- Kişiler ve Firmalar listesinin üstü (27.10): sekme yerine anlaşma penceresindeki proje seçimi gibi yuvarlak düğmeler. --}}
<div class="flex flex-wrap items-center gap-2">
    @foreach ($tabs as $key => $label)
        <button type="button" wire:click="$set('activeTab', @js($key))"
            @class([
                'rounded-full px-3 py-1 text-sm font-medium ring-1 transition',
                'bg-primary-600 text-white ring-primary-600' => $active === $key,
                'text-gray-700 ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-white/5' => $active !== $key,
            ])>{{ $label }}</button>
    @endforeach
</div>
