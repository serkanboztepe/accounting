<?php

namespace App\Providers\Filament;

use App\Filament\Hub\HubFirms\HubFirmResource;
use App\Http\Middleware\UseHubGuard;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Tek panel (TENANCY): S-CODER yönetim paneli /hub — firmalar, numaralar, modüller,
 * kullanıcılar, maliyet. Ayrı giriş (merkez users tablosu, 'hub' guard); firma verisine
 * yalnız firma ekranındaki işlemler aracılığıyla (Tenancy::run) dokunur.
 * Yalnız TENANCY açıkken yüklenir (AppServiceProvider).
 */
class HubPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('hub')
            ->path('hub')
            ->authGuard('hub')
            ->brandName('Hesap Asistanım · Hub')
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('brand-icon.svg'))
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            ->colors([
                'primary' => Color::hex('#0E8A5F'),
                'gray'    => Color::Zinc,
            ])
            ->resources([HubFirmResource::class])
            ->homeUrl(fn (): string => HubFirmResource::getUrl('index', panel: 'hub'))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                UseHubGuard::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
