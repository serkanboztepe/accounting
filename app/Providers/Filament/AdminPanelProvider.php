<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use App\Filament\Hub\HubFirms\HubFirmResource;
use App\Filament\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\SwitchFirm;
use App\Tenancy\Tenancy;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use App\Http\Middleware\IdentifyTenant;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('Hesap Asistanım')
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('brand-icon.svg'))
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login(Login::class)
            ->userMenuItems([
                // Tek panel: birden çok firmada hesabı olan (girişte şifresi tutan) kullanıcı
                Action::make('switchFirm')
                    ->label(fn (): string => 'Firma: ' . (Tenancy::current()?->name ?? ''))
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->url(fn (): string => SwitchFirm::getUrl())
                    ->visible(fn (): bool => SwitchFirm::canAccess()),
            ])
            ->colors([
                'primary' => Color::hex('#0E8A5F'), // hesapasistanim.com yeşili
                'gray'    => Color::Zinc,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                IdentifyTenant::class,
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

        // WhatsApp hub'ı (APP_ROLE=hub, eski ayrı kurulum): sadece telefon → firma yönetimi.
        // Tek panelde (TENANCY) hub ayrı panel: /hub (HubPanelProvider).
        if (config('app.role') === 'hub') {
            return $panel
                ->resources([HubFirmResource::class])
                ->homeUrl(fn (): string => HubFirmResource::getUrl('index'));
        }

        return $panel
            // Dashboard kapalıysa giriş/ana sayfa Cariler'e gitsin
            ->homeUrl(fn (): ?string => config('modules.dashboard')
                ? null
                : \App\Filament\Resources\Parties\PartyResource::getUrl('index'))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            // Kümeler collapsible; Ayarlar (Sistem) varsayılan kapalı — yalnız
            // içindeyken açılır (Filament aktif grubu otomatik açar).
            ->collapsibleNavigationGroups()
            ->navigationGroups([
                'Genel',
                'Cari',
                'Sözleşmeler',
                'Gider & Çek',
                'Ürün & Stok',
                'Mimar',
                'Raporlar',
                NavigationGroup::make('Sistem')->collapsed(),
            ])
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ]);
    }
}
