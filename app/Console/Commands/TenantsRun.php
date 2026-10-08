<?php

namespace App\Console\Commands;

use App\Models\HubFirm;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Tek panel: bir artisan komutunu her aktif firmanın içinde çalıştır.
 *   php artisan tenants:run checks:remind
 *   php artisan tenants:run "backup:run --only-db" --firm=3
 *
 * Her firma AYRI SÜREÇTE çalışır (TENANT_ID ortam değişkeni → AppServiceProvider firmayı
 * açılışta seçer). Aynı süreçte firma değiştirmek yetmiyordu: spatie/laravel-backup gibi
 * paketler ayarı açılışta bir kez okuyor — firma yedeği merkez veritabanını yedekliyordu.
 * Bir firmada hata olursa diğerleri yine çalışır.
 */
class TenantsRun extends Command
{
    protected $signature = 'tenants:run {line : Çalıştırılacak komut (tırnak içinde, seçenekleriyle)} {--firm= : Yalnız bu firma (id)}';

    protected $description = 'Komutu her firmanın veritabanında (ayrı süreçte) çalıştırır';

    public function handle(): int
    {
        $failed = 0;

        foreach (self::firms($this->option('firm')) as $firm) {
            $this->line("<info>▶ {$firm->name}</info> ({$firm->database})");

            $process = Process::fromShellCommandline(
                escapeshellarg(PHP_BINARY) . ' artisan ' . $this->argument('line'),
                base_path(),
                ['TENANT_ID' => (string) $firm->id, 'TENANCY' => config('tenancy.enabled') ? 'true' : 'false'],
            );
            $process->setTimeout(1800);
            $process->run(fn ($type, $buffer) => $this->output->write($buffer));

            if (! $process->isSuccessful()) {
                $failed++;
                $this->error("  {$firm->name}: çıkış kodu {$process->getExitCode()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, HubFirm> */
    public static function firms(?string $only = null)
    {
        return HubFirm::query()
            ->whereNotNull('database')
            ->where('is_active', true)
            ->when($only, fn ($q) => $q->whereKey($only))
            ->orderBy('id')
            ->get();
    }
}
