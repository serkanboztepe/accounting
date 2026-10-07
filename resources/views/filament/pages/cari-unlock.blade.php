<x-filament-panels::page>
    <div class="mx-auto w-full max-w-md">
        <x-filament::section>
            <form wire:submit="unlock" class="flex flex-col gap-4">
                <div class="flex items-center gap-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">
                        <x-filament::icon icon="heroicon-o-lock-closed" class="size-5" />
                    </span>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Cari bilgilerini görmek için panele giriş şifrenizi girin.
                        {{ config('app.cari_lock_minutes') }} dakika işlem yapılmazsa tekrar kilitlenir.
                    </p>
                </div>

                <div>
                    <label for="cari-password" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">Şifre</label>
                    <x-filament::input.wrapper :valid="! $errors->has('password')">
                        <x-filament::input id="cari-password" type="password" wire:model="password" autocomplete="current-password" autofocus />
                    </x-filament::input.wrapper>
                    @error('password')
                        <p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                    @enderror
                </div>

                <x-filament::button type="submit" icon="heroicon-o-lock-open" class="w-full">
                    Kilidi aç
                </x-filament::button>
            </form>
        </x-filament::section>
    </div>
</x-filament-panels::page>
