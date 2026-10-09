<?php

namespace App\Console\Commands;

use App\Models\HubFirm;
use App\Tenancy\FirmProvisioner;
use App\Tenancy\Tenancy;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ortak (merkez) kullanıcıyla bağlanan firmaya kendi veritabanı kullanıcısını aç ve firmayı ona bağla.
 *   php artisan tenants:create-db-user            → kendi kullanıcısı olmayan tüm firmalar
 *   php artisan tenants:create-db-user --firm=4
 * Sonunda firma yeni kullanıcıyla açılıp kullanıcı sayısı okunur (bağlantı doğrulaması).
 */
class TenantsCreateDbUser extends Command
{
    protected $signature = 'tenants:create-db-user {--firm= : Yalnız bu firma (id)}';

    protected $description = 'Firmaya özel veritabanı kullanıcısı açar (ortak kullanıcı yerine)';

    public function handle(): int
    {
        $firms = HubFirm::query()
            ->whereNotNull('database')
            ->when($this->option('firm'), fn ($q, $id) => $q->whereKey($id), fn ($q) => $q->whereNull('db_username'))
            ->orderBy('id')
            ->get();

        if ($firms->isEmpty()) {
            $this->info('Kendi kullanıcısı olmayan firma yok.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($firms as $firm) {
            try {
                FirmProvisioner::giveOwnDatabaseUser($firm);
                $users = Tenancy::run($firm->fresh(), fn () => User::count());
                $this->line("<info>✓ {$firm->name}</info>: kullanıcı {$firm->db_username} — bağlantı OK ({$users} panel kullanıcısı)");
            } catch (Throwable $e) {
                $failed++;
                $this->error("✗ {$firm->name}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
