<?php

namespace App\Console\Commands;

use App\Models\HubFirm;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Tek panel: bir artisan komutunu her aktif firmanın içinde çalıştır.
 *   php artisan tenants:run checks:remind
 *   php artisan tenants:run "checks:remind --dry-run" --firm=3
 * Bir firmada hata olursa diğerleri yine çalışır.
 */
class TenantsRun extends Command
{
    protected $signature = 'tenants:run {line : Çalıştırılacak komut (tırnak içinde, seçenekleriyle)} {--firm= : Yalnız bu firma (id)}';

    protected $description = 'Komutu her firmanın veritabanında çalıştırır';

    public function handle(): int
    {
        $failed = 0;

        foreach (self::firms($this->option('firm')) as $firm) {
            $this->line("<info>▶ {$firm->name}</info> ({$firm->database})");

            try {
                Tenancy::run($firm, function () {
                    Artisan::call($this->argument('line'), [], $this->output);
                });
            } catch (Throwable $e) {
                $failed++;
                $this->error("  {$firm->name}: {$e->getMessage()}");
                report($e);
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
