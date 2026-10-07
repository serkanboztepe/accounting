@php
    $pin = $this->usesPin();
@endphp
<x-filament-panels::page>
    <div class="mx-auto w-full max-w-md">
        <x-filament::section>
            <form wire:submit="unlock" class="flex flex-col gap-4" autocomplete="off">
                <div class="flex items-center gap-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">
                        <x-filament::icon icon="heroicon-o-lock-closed" class="size-5" />
                    </span>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        @if ($pin)
                            Cari bilgilerini görmek için Cari PIN'inizi girin.
                        @else
                            Cari bilgilerini görmek için panele giriş şifrenizi girin.
                        @endif
                        {{ config('app.cari_lock_minutes') }} dakika işlem yapılmazsa tekrar kilitlenir.
                    </p>
                </div>

                <div>
                    <label for="cari-secret" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">{{ $pin ? 'PIN' : 'Şifre' }}</label>
                    <x-filament::input.wrapper :valid="! $errors->has('secret')">
                        @if ($pin)
                            {{-- type=text + gizleme: tarayıcı kayıtlı şifre önermesin --}}
                            <x-filament::input id="cari-secret" type="text" wire:model="secret" inputmode="numeric"
                                autocomplete="off" autofocus data-1p-ignore data-lpignore="true"
                                style="-webkit-text-security: disc; letter-spacing: .3em;" />
                        @else
                            <x-filament::input id="cari-secret" type="password" wire:model="secret" autocomplete="current-password" autofocus />
                        @endif
                    </x-filament::input.wrapper>
                    @error('secret')
                        <p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                    @enderror
                    @unless ($pin)
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            İpucu: Ayarlar → <b>Cari PIN</b> ile şifre yerine kısa bir PIN belirleyebilirsiniz.
                        </p>
                    @endunless
                </div>

                <x-filament::button type="submit" icon="heroicon-o-lock-open" class="w-full">
                    Kilidi aç
                </x-filament::button>
            </form>
        </x-filament::section>
    </div>
</x-filament-panels::page>
