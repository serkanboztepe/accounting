<?php

namespace App\Support;

/**
 * Cari kilidi: Cariler'i açmak için giriş şifresi istenir; açıldıktan sonra
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
}
