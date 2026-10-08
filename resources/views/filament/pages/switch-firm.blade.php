<x-filament-panels::page>
    <div class="mx-auto w-full max-w-md">
        <x-filament::section>
            <div class="flex flex-col gap-2">
                @foreach ($this->getFirms() as $firm)
                    @php
                        $active = \App\Tenancy\Tenancy::current()?->is($firm);
                    @endphp
                    <button type="button" wire:click="switchTo({{ $firm->id }})" @disabled($active)
                        class="flex items-center justify-between rounded-lg border px-4 py-3 text-left text-sm font-medium transition
                            {{ $active ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-400' : 'border-gray-200 hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5' }}">
                        <span>{{ $firm->name }}</span>
                        @if ($active)
                            <span class="text-xs">Şu an açık</span>
                        @endif
                    </button>
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
