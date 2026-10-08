<?php

namespace App\Console\Commands;

use App\Tenancy\FirmProvisioner;
use Illuminate\Console\Command;
use Throwable;

/** Deploy'da: her firma veritabanında bekleyen migration'lar (merkez için ayrıca: migrate --database=central). */
class TenantsMigrate extends Command
{
    protected $signature = 'tenants:migrate {--firm= : Yalnız bu firma (id)}';

    protected $description = 'Tüm firma veritabanlarında migration çalıştırır';

    public function handle(): int
    {
        $failed = 0;

        foreach (TenantsRun::firms($this->option('firm')) as $firm) {
            $this->line("<info>▶ {$firm->name}</info> ({$firm->database})");
            try {
                $this->line(trim(FirmProvisioner::migrate($firm)) ?: '  (değişiklik yok)');
                FirmProvisioner::syncUsers($firm);
            } catch (Throwable $e) {
                $failed++;
                $this->error("  {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
