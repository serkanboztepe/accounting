<?php

namespace App\Support;

use App\Tenancy\Tenancy;
use App\Models\CompanySettings;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Cari kilidi: Cariler'i açmak için PIN (Ayarlar'dan belirlenir) istenir;
 * PIN belirlenmemişse panel giriş şifresi istenir. Açıldıktan sonra
 * config('app.cari_lock_minutes') dakika işlem yapılmazsa tekrar kilitlenir.
 * Durum oturumda tutulur (son işlem zamanı).
 */
class CariLock
{
    private const SESSION_KEY = 'cari_lock.last_activity';

    public static function enabled(): bool
    {
        return (bool) config('app.cari_lock');
    }

    /** Kilit kapalıysa ya da süre dolmadıysa erişim serbest. */
    public static function isOpen(): bool
    {
        if (! self::enabled()) {
            return true;
        }

        $last = session(self::SESSION_KEY);

        return $last !== null
            && now()->timestamp - (int) $last < config('app.cari_lock_minutes') * 60;
    }

    /** Şifre doğrulandığında ya da açıkken her işlemde süreyi yeniler. */
    public static function touch(): void
    {
        session([self::SESSION_KEY => now()->timestamp]);
    }

    public static function lock(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    public static function hasPin(): bool
    {
        return filled(CompanySettings::current()->cari_pin);
    }

    public static function checkPin(string $pin): bool
    {
        $hash = CompanySettings::current()->cari_pin;

        return filled($hash) && Hash::check($pin, $hash);
    }

    /** Yanlış denemede bu kadar hak, sonra bekleme (4 haneli PIN tahminle bulunmasın). */
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 300;

    private static string $lastMessage = '';

    /** Kilidi açma denemesi: PIN varsa PIN, yoksa giriş şifresi. Deneme sınırlı. */
    public static function attempt(string $secret): bool
    {
        return self::limited(fn () => self::hasPin()
            ? self::checkPin($secret)
            : Hash::check($secret, auth()->user()->getAuthPassword()));
    }

    /** Sadece PIN (Ayarlar'da PIN değiştirirken mevcut PIN). Aynı deneme sayacı. */
    public static function attemptPin(string $pin): bool
    {
        return self::limited(fn () => self::checkPin($pin));
    }

    public static function lastAttemptMessage(): string
    {
        return self::$lastMessage;
    }

    private static function limited(callable $check): bool
    {
        $key = Tenancy::key('cari-unlock:' . auth()->id());
        $label = self::hasPin() ? 'PIN' : 'Şifre';

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            self::$lastMessage = "Çok fazla yanlış deneme. {$minutes} dakika sonra tekrar deneyin.";

            return false;
        }

        if (! $check()) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            self::$lastMessage = "{$label} yanlış.";

            return false;
        }

        RateLimiter::clear($key);
        self::$lastMessage = '';

        return true;
    }

    /** null → PIN kaldırılır (kilit giriş şifresine döner). */
    public static function setPin(?string $pin): void
    {
        $settings = CompanySettings::current();
        $settings->cari_pin = $pin === null ? null : Hash::make($pin);
        $settings->save();
    }
}
