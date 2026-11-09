<?php

namespace App\Console\Commands;

use App\Models\HubFirm;
use App\Services\Agent\ReadOnlyDb;
use App\Tenancy\FirmProvisioner;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ajan asistanın serbest okuması için firmaya SALT-OKUR veritabanı kullanıcısı açar (yalnız izinli tablolarda SELECT).
 *   php artisan tenants:create-ro-user            → salt-okur kullanıcısı olmayan tüm firmalar
 *   php artisan tenants:create-ro-user --firm=7
 * Sonunda üç kontrol: izinli tablo okunur, kapalı tablo (users) REDDEDİLİR, yazma REDDEDİLİR. Firma verisine dokunmaz.
 */
class TenantsCreateRoUser extends Command
{
    protected $signature = 'tenants:create-ro-user {--firm= : Yalnız bu firma (id)}';

    protected $description = 'Firmaya salt-okur veritabanı kullanıcısı açar (ajan asistanın serbest okuması)';

    public function handle(): int
    {
        $firms = HubFirm::query()
            ->whereNotNull('database')
            ->when($this->option('firm'), fn ($q, $id) => $q->whereKey($id), fn ($q) => $q->whereNull('db_ro_username'))
            ->orderBy('id')
            ->get();

        if ($firms->isEmpty()) {
            $this->info('Salt-okur kullanıcısı olmayan firma yok.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($firms as $firm) {
            try {
                FirmProvisioner::giveReadOnlyUser($firm);
                $problems = Tenancy::run($firm->fresh(), fn () => self::verify());
                if ($problems !== []) {
                    $failed++;
                    $this->error("✗ {$firm->name}: " . implode(' · ', $problems));

                    continue;
                }
                $this->line("<info>✓ {$firm->name}</info>: {$firm->db_ro_username} — okur, kapalı tablo ve yazma reddedildi");
            } catch (Throwable $e) {
                $failed++;
                $this->error("✗ {$firm->name}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** Sınırı KORUMA KATMANI OLMADAN (SqlGuard'sız) doğrudan bağlantıda dene: asıl sınır veritabanı yetkisidir. @return list<string> */
    public static function verify(): array
    {
        $problems = [];
        $db = \Illuminate\Support\Facades\DB::connection(ReadOnlyDb::CONNECTION);
        try {
            $db->select('select count(*) as n from parties');
        } catch (Throwable $e) {
            $problems[] = 'izinli tablo okunamadı: ' . mb_substr($e->getMessage(), 0, 120);
        }
        foreach (['select count(*) from users' => 'kapalı tablo (users) okundu',
            'select media_url from whatsapp_pending_expenses limit 1' => 'kapalı kolon (media_url) okundu',
            "insert into expense_categories (name) values ('__ro_test__')" => 'YAZMA başarılı oldu',
            "update parties set notes = notes where 1 = 0" => 'UPDATE kabul edildi'] as $sql => $problem) {
            try {
                $db->statement($sql);
                $problems[] = $problem;
            } catch (Throwable) {
                // beklenen: reddedildi
            }
        }
        \Illuminate\Support\Facades\DB::purge(ReadOnlyDb::CONNECTION);

        return $problems;
    }
}
