<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Parties\PartyResource;
use App\Support\CariLock;
use Filament\Pages\Page;

/** Cari kilidi ekranı — menüde görünmez, RequireCariUnlock buraya yönlendirir. */
class CariUnlock extends Page
{
    protected static ?string $slug = 'cari-kilidi';

    protected static ?string $title = 'Cariler kilitli';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.cari-unlock';

    public string $secret = '';

    public ?string $redirect = null;

    public static function canAccess(): bool
    {
        return CariLock::enabled();
    }

    public function mount(): void
    {
        $this->redirect = request()->query('redirect');
    }

    public function usesPin(): bool
    {
        return CariLock::hasPin();
    }

    public function unlock(): void
    {
        $label = $this->usesPin() ? 'PIN' : 'Şifre';

        $this->validate(['secret' => ['required', 'string']], [
            'secret.required' => "{$label} girin.",
        ]);

        if (! CariLock::attempt($this->secret)) {
            $this->secret = '';
            $this->addError('secret', CariLock::lastAttemptMessage());

            return;
        }

        CariLock::touch();

        $this->redirect($this->safeRedirect());
    }

    /** Sadece bu sitedeki adreslere dön (dışarıya yönlendirme yok). */
    private function safeRedirect(): string
    {
        $fallback = PartyResource::getUrl('index');
        $target = $this->redirect;

        if (! $target) {
            return $fallback;
        }

        $host = parse_url($target, PHP_URL_HOST);

        return ($host === null || $host === request()->getHost()) ? $target : $fallback;
    }
}
