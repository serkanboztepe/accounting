<?php

namespace App\Providers;

use App\Filament\Resources\Parties\Pages\CreateParty;
use App\Filament\Resources\Parties\Pages\EditParty;
use App\Filament\Resources\Parties\Pages\ListParties;
use App\Http\Middleware\RequireCariUnlock;
use App\Support\CariLock;
use Carbon\Carbon;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Carbon::setLocale('tr');

        $this->bootCariLock();
    }

    /** Cari kilidi (CARI_LOCK) — bkz. App\Support\CariLock. */
    private function bootCariLock(): void
    {
        // Panele giriş şifresi az önce girildi → Cariler hemen açık gelsin.
        Event::listen(Login::class, fn () => CariLock::touch());

        // Cari sayfasındaki işlemler (Livewire) de süreyi yenilesin.
        Livewire::addPersistentMiddleware([RequireCariUnlock::class]);

        // Sayfa açık bırakılırsa süre dolunca yenile → şifre ekranına düşer.
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn () => CariLock::enabled() ? view('filament.cari-lock-idle') : '',
            scopes: [ListParties::class, EditParty::class, CreateParty::class],
        );
    }
}
