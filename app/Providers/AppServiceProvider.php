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
        // Tek panel: S-CODER yönetim paneli /hub (eski düzende hub ayrı kurulumdu, APP_ROLE=hub).
        if (config('tenancy.enabled')) {
            $this->app->register(\App\Providers\Filament\HubPanelProvider::class);
        }
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
        // PIN yoksa kilit giriş şifresini sorar; o şifre az önce girildi → açık gelsin.
        // PIN varsa açılmaz: kayıtlı şifreyle giren biri Carileri göremesin.
        Event::listen(Login::class, function () {
            if (CariLock::enabled() && ! CariLock::hasPin()) {
                CariLock::touch();
            }
        });

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
