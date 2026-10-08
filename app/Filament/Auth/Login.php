<?php

namespace App\Filament\Auth;

use App\Http\Middleware\IdentifyTenant;
use App\Models\FirmUser;
use App\Models\HubFirm;
use App\Models\User;
use App\Tenancy\Tenancy;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\Hash;

/**
 * Tek panel girişi: e-posta → firma (merkezdeki firm_users), şifre o firmanın
 * veritabanında doğrulanır. Aynı e-posta birden çok firmada varsa şifresi tutan
 * firmalar arasından ilki açılır, diğerleri "Firma değiştir" menüsünde çıkar
 * (yalnız şifresi doğrulananlar — başka firmadaki aynı e-postalı hesaba geçilemez).
 * Eski düzende (TENANCY kapalı) Filament'in girişiyle aynı.
 */
class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        if (! Tenancy::enabled()) {
            return parent::authenticate();
        }

        $data = $this->form->getState();
        $firms = $this->firmsAcceptingPassword((string) $data['email'], (string) $data['password']);

        if ($firms === []) {
            // Eşleşme yok: Filament'in sorgusu boş firmaya gitmesin — limiti say, aynı hatayı ver.
            try {
                $this->rateLimit(5);
            } catch (TooManyRequestsException $exception) {
                $this->getRateLimitedNotification($exception)?->send();

                return null;
            }
            $this->throwFailureValidationException();
        }

        Tenancy::activate($firms[0]);
        session()->put(IdentifyTenant::SESSION_KEY, $firms[0]->id);
        session()->put(IdentifyTenant::CHOICES_KEY, array_map(fn (HubFirm $f) => $f->id, $firms));

        return parent::authenticate();
    }

    /** @return list<HubFirm> */
    private function firmsAcceptingPassword(string $email, string $password): array
    {
        $firms = FirmUser::query()
            ->where('email', mb_strtolower(trim($email)))
            ->with('firm')
            ->get()
            ->pluck('firm')
            ->filter(fn (?HubFirm $f) => $f && $f->is_active && $f->isLocal())
            ->sortBy('name')
            ->values();

        $accepted = [];
        foreach ($firms as $firm) {
            $ok = Tenancy::run($firm, function () use ($email, $password) {
                $user = User::whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();

                return $user && Hash::check($password, $user->password);
            });
            if ($ok) {
                $accepted[] = $firm;
            }
        }

        return $accepted;
    }

    /** "Beni hatırla" çerezi firmayı taşımaz; tek panelde kapalı (oturum süresi yeterli). */
    protected function getRememberFormComponent(): Component
    {
        return parent::getRememberFormComponent()->hidden(Tenancy::enabled());
    }
}
