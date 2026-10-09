<?php

namespace Tests\Feature;

use App\Models\HubUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Hub iki adımlı giriş: anahtar + kurtarma kodları veritabanında ŞİFRELİ; kilitlenince hub:mfa-reset.
 * (Akışın kendisi — QR, kod sorma, yanlış kod reddi — tarayıcıda doğrulandı; hub paneli testte yüklenmiyor.)
 */
class HubMfaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_secret_and_recovery_codes_are_encrypted_at_rest(): void
    {
        $user = HubUser::forceCreate(['name' => 'Hub', 'email' => 'hub-mfa@example.com', 'password' => 'x']);
        $user->saveAppAuthenticationSecret('ELLOK7XOARZZC6BP');
        $user->saveAppAuthenticationRecoveryCodes(['kod-1', 'kod-2']);

        $raw = DB::table('users')->where('id', $user->id)->first();
        $this->assertStringNotContainsString('ELLOK7XOARZZC6BP', $raw->app_authentication_secret);
        $this->assertStringNotContainsString('kod-1', $raw->app_authentication_recovery_codes);

        $fresh = $user->fresh();
        $this->assertSame('ELLOK7XOARZZC6BP', $fresh->getAppAuthenticationSecret());
        $this->assertSame(['kod-1', 'kod-2'], $fresh->getAppAuthenticationRecoveryCodes());
    }

    public function test_reset_command_clears_mfa(): void
    {
        $user = HubUser::forceCreate(['name' => 'Hub', 'email' => 'hub-mfa@example.com', 'password' => 'x']);
        $user->saveAppAuthenticationSecret('ELLOK7XOARZZC6BP');

        $this->artisan('hub:mfa-reset', ['email' => 'hub-mfa@example.com'])->assertSuccessful();
        $this->assertNull($user->fresh()->getAppAuthenticationSecret());

        $this->artisan('hub:mfa-reset', ['email' => 'yok@example.com'])->assertFailed();
    }
}
