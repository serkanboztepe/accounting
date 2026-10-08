<?php

namespace App\Tenancy;

use App\Models\HubFirm;
use App\Models\User;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Hub'da "Firma Oluştur": veritabanı aç → tabloları kur → birim/kategori varsayılanları → ilk kullanıcı.
 * Mevcut bir veritabanını bağlamak (eski ayrı kurulumlar) için yalnız migrate() yeter; veri kopyalanmaz.
 */
class FirmProvisioner
{
    public static function databaseNameFor(string $code): string
    {
        $slug = Str::of($code)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(40, '');

        if ($slug->isEmpty()) {
            throw new RuntimeException('Kısa ad yalnız harf/rakam olmalı.');
        }

        return config('tenancy.database_prefix') . $slug;
    }

    /** Yeni firma: boş veritabanı + tablolar + varsayılanlar + ilk kullanıcı. */
    public static function create(HubFirm $firm, string $name, string $email, string $password): void
    {
        $database = (string) $firm->database;

        if (! preg_match('/^[a-z0-9_]+$/', $database)) {
            throw new RuntimeException("Geçersiz veritabanı adı: {$database}");
        }

        if (self::databaseExists($database)) {
            throw new RuntimeException("{$database} veritabanı zaten var. Mevcut veritabanını bağlamak için \"Mevcut veritabanı\" seçeneğini kullan.");
        }

        Schema::connection(self::adminConnection())->createDatabase($database);

        self::migrate($firm);

        Tenancy::run($firm, function () use ($name, $email, $password) {
            (new UnitSeeder())->run();
            (new ExpenseCategorySeeder())->run();
            User::create(['name' => $name, 'email' => $email, 'password' => $password]);
        });
    }

    /** Firma veritabanında bekleyen migration'ları çalıştır (deploy'da tüm firmalar için). */
    public static function migrate(HubFirm $firm): string
    {
        return Tenancy::run($firm, function () {
            Artisan::call('migrate', ['--database' => 'tenant', '--force' => true]);

            return Artisan::output();
        });
    }

    /** Firma veritabanındaki kullanıcıları merkez e-posta → firma tablosuna yaz (mevcut DB bağlanınca). */
    public static function syncUsers(HubFirm $firm): int
    {
        $emails = Tenancy::run($firm, fn () => User::pluck('email')->map(fn ($e) => mb_strtolower(trim($e)))->all());

        $firm->users()->whereNotIn('email', $emails)->delete();
        foreach ($emails as $email) {
            $firm->users()->firstOrCreate(['email' => $email]);
        }

        return count($emails);
    }

    /**
     * Veritabanı açma yetkili bağlantı: merkezin ayarları + (varsa) TENANCY_ADMIN_DB_USERNAME/PASSWORD.
     * Ayrı bağlantı, çünkü CREATE DATABASE açık bir işlem (transaction) içinde çalışmaz.
     */
    public static function adminConnection(): string
    {
        if (! config('database.connections.tenancy_admin')) {
            $central = config('database.connections.central');
            config(['database.connections.tenancy_admin' => array_merge($central, array_filter([
                'username' => config('tenancy.admin_username'),
                'password' => config('tenancy.admin_password'),
            ]))]);
        }

        return 'tenancy_admin';
    }

    public static function databaseExists(string $database): bool
    {
        $central = DB::connection(self::adminConnection());

        return match ($central->getDriverName()) {
            'pgsql'  => (bool) $central->selectOne('select 1 from pg_database where datname = ?', [$database]),
            'sqlite' => false,
            default  => (bool) $central->selectOne('select schema_name from information_schema.schemata where schema_name = ?', [$database]),
        };
    }
}
