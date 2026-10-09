<?php

namespace App\Console\Commands;

use App\Models\HubUser;
use Illuminate\Console\Command;

/**
 * Telefon + kurtarma kodları kaybolursa: hub yöneticisinin iki adımlı girişini sıfırla.
 * Sonraki girişte yeni QR kod ile yeniden kurulur. Yalnız sunucuda (SSH) çalıştırılabilir.
 */
class HubMfaReset extends Command
{
    protected $signature = 'hub:mfa-reset {email : Hub yöneticisinin e-postası}';

    protected $description = 'Hub yöneticisinin iki adımlı girişini sıfırlar (yeniden kurulur)';

    public function handle(): int
    {
        $user = HubUser::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('Bu e-postayla hub yöneticisi yok.');

            return self::FAILURE;
        }

        $user->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();
        $this->info("{$user->email}: iki adımlı giriş sıfırlandı — bir sonraki girişte yeni QR kod istenecek.");

        return self::SUCCESS;
    }
}
