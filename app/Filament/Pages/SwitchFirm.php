<?php

namespace App\Filament\Pages;

use App\Http\Middleware\IdentifyTenant;
use App\Models\HubFirm;
use App\Models\User;
use App\Tenancy\Tenancy;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * Tek panel: aynı e-postayla birden çok firmada hesabı olan kullanıcı için firma değiştirme.
 * Yalnız GİRİŞTE ŞİFRESİ DOĞRULANAN firmalar listelenir (session tenant_choices) — bir firmada
 * aynı e-postayla hesap açan biri başka firmanın hesabına geçemesin.
 */
class SwitchFirm extends Page
{
    protected static ?string $slug = 'firma-degistir';

    protected static ?string $title = 'Firma değiştir';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.switch-firm';

    public static function canAccess(): bool
    {
        return Tenancy::enabled() && count(self::choiceIds()) > 1;
    }

    /** @return list<int> */
    public static function choiceIds(): array
    {
        return array_map('intval', (array) session(IdentifyTenant::CHOICES_KEY, []));
    }

    public function getFirms()
    {
        return HubFirm::whereKey(self::choiceIds())->where('is_active', true)->orderBy('name')->get();
    }

    public function switchTo(int $firmId): void
    {
        abort_unless(in_array($firmId, self::choiceIds(), true), 403);

        $firm = HubFirm::where('is_active', true)->findOrFail($firmId);
        $email = Filament::auth()->user()->email;

        Tenancy::end();
        Tenancy::activate($firm);
        $user = User::whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();
        abort_unless($user, 403);

        $guard = Filament::auth();
        $guard->login($user);
        session()->put(IdentifyTenant::SESSION_KEY, $firm->id);
        // AuthenticateSession şifre özetini oturumda tutar; yeni firmanın kullanıcısıyla güncelle.
        session()->put('password_hash_' . Filament::getAuthGuard(), $user->getAuthPassword());
        session()->forget('cari_lock.last_activity');
        session()->regenerate();

        $this->redirect(Filament::getUrl());
    }
}
