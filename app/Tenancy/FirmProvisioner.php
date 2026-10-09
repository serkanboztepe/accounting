<?php

namespace App\Tenancy;

use App\Models\HubFirm;
use App\Models\User;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

    /**
     * Yeni firma: firmaya ÖZEL veritabanı kullanıcısı + boş veritabanı + tablolar + varsayılanlar + ilk kullanıcı.
     * Firma kendi kullanıcısıyla bağlanır — o kullanıcı başka hiçbir firmanın veritabanını açamaz
     * (koddaki bir hata yanlış veritabanı adı verse bile MySQL reddeder).
     */
    public static function create(HubFirm $firm, string $name, string $email, string $password): void
    {
        $database = (string) $firm->database;
        self::assertSafeName($database);

        if (self::databaseExists($database)) {
            throw new RuntimeException("{$database} veritabanı zaten var, başka bir kısa ad seç.");
        }

        self::createDatabaseWithOwnUser($firm);

        self::migrate($firm);

        Tenancy::run($firm, function () use ($name, $email, $password) {
            (new UnitSeeder())->run();
            (new ExpenseCategorySeeder())->run();
            User::create(['name' => $name, 'email' => $email, 'password' => $password]);
        });
    }

    /**
     * Var olan veritabanı için firmaya özel kullanıcı aç ve firmayı ona bağla (ortak kullanıcıyla
     * açılmış firmalar için: `php artisan tenants:create-db-user --firm=4`).
     */
    public static function giveOwnDatabaseUser(HubFirm $firm): void
    {
        self::assertSafeName((string) $firm->database);

        if (! self::databaseExists($firm->database)) {
            throw new RuntimeException("{$firm->database} veritabanı yok.");
        }

        self::createDatabaseWithOwnUser($firm, databaseExists: true);
    }

    /** Kullanıcı = veritabanı adı; şifre rastgele, hub'da şifreli (HubFirm db_password 'encrypted'). */
    private static function createDatabaseWithOwnUser(HubFirm $firm, bool $databaseExists = false): void
    {
        $admin = DB::connection(self::adminConnection());
        $database = (string) $firm->database;
        $user = $database;
        $password = Str::random(40);
        $quoted = $admin->getPdo()->quote($password);

        if ($admin->getDriverName() === 'pgsql') {
            $admin->statement("DROP ROLE IF EXISTS \"{$user}\"");
            $admin->statement("CREATE ROLE \"{$user}\" LOGIN PASSWORD {$quoted}");
            if ($databaseExists) {
                $admin->statement("ALTER DATABASE \"{$database}\" OWNER TO \"{$user}\"");
                // PostgreSQL'de (yerel geliştirme/test) veritabanı sahipliği tabloları kapsamaz.
                config(['database.connections.tenancy_admin_db' => array_merge(config('database.connections.' . self::adminConnection()), ['database' => $database])]);
                $inDb = DB::connection('tenancy_admin_db');
                $inDb->statement("GRANT ALL ON ALL TABLES IN SCHEMA public TO \"{$user}\"");
                $inDb->statement("GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO \"{$user}\"");
                $inDb->statement("GRANT ALL ON SCHEMA public TO \"{$user}\"");
                DB::purge('tenancy_admin_db');
            } else {
                $admin->statement("CREATE DATABASE \"{$database}\" OWNER \"{$user}\"");
            }
        } else {
            if (! $databaseExists) {
                $admin->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
            // Panel 127.0.0.1 (TCP) ile, artisan bazen soket (localhost) ile bağlanır → ikisi de.
            foreach (['localhost', '127.0.0.1'] as $host) {
                $admin->statement("DROP USER IF EXISTS '{$user}'@'{$host}'");
                $admin->statement("CREATE USER '{$user}'@'{$host}' IDENTIFIED BY {$quoted}");
                $admin->statement("GRANT ALL PRIVILEGES ON `{$database}`.* TO '{$user}'@'{$host}'");
            }
        }

        $firm->forceFill(['db_username' => $user, 'db_password' => $password])->save();
    }

    private static function assertSafeName(string $database): void
    {
        if (! preg_match('/^[a-z0-9_]+$/', $database)) {
            throw new RuntimeException("Geçersiz veritabanı adı: {$database}");
        }
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
     * Yetkili bağlantı (veritabanı + kullanıcı açar): merkezin ayarları + TENANCY_ADMIN_DB_USERNAME/PASSWORD.
     * Yalnız "Firma oluştur" anında kullanılır; merkez kullanıcısı (hub_usr) firma veritabanlarını açamaz.
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
