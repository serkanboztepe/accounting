<?php

namespace App\Tenancy;

use App\Models\HubFirm;
use App\Support\ModuleProfiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Tek panel (TENANCY=true): aktif firmayı seçer. Her firmanın AYRI veritabanı vardır;
 * activate() 'tenant' bağlantısını o veritabanına çevirir ve firmanın hub'daki
 * ayarlarını (modüller, cari kilidi, çek hatırlatması) config'e yazar — uygulamanın
 * geri kalanı config('modules.*') vb. okumaya devam eder, firmadan habersizdir.
 *
 * Firma seçilmeden 'tenant'a giden sorgu hata verir (bkz config/database.php).
 * Firmaya özel önbellek anahtarları key() ile, dosyalar 'public' diskinde firma klasörüyle ayrılır.
 */
class Tenancy
{
    private static ?HubFirm $current = null;

    /** activate() öncesi değerler — end() geri yükler (testler, hub'ın firma içinde iş yapması). */
    private static ?array $baseline = null;

    public static function enabled(): bool
    {
        return (bool) config('tenancy.enabled');
    }

    public static function current(): ?HubFirm
    {
        return self::$current;
    }

    public static function activate(HubFirm $firm): void
    {
        if (! $firm->database) {
            throw new RuntimeException("Firmanın veritabanı tanımlı değil: {$firm->name}");
        }

        if (self::$current?->is($firm)) {
            return;
        }

        self::$baseline ??= [
            'default' => DB::getDefaultConnection(),
            'config'  => [
                'database.connections.tenant' => config('database.connections.tenant'),
                'modules'                     => config('modules'),
                'app.profile'                 => config('app.profile'),
                'app.cari_lock'               => config('app.cari_lock'),
                'app.cari_lock_minutes'       => config('app.cari_lock_minutes'),
                'services.whatsapp.reminder_phones'     => config('services.whatsapp.reminder_phones'),
                'services.whatsapp.check_reminder_days' => config('services.whatsapp.check_reminder_days'),
                'filesystems.disks.public'    => config('filesystems.disks.public'),
                'backup.backup.name'             => config('backup.backup.name'),
                'backup.backup.source.databases' => config('backup.backup.source.databases'),
                'backup.monitor_backups'         => config('backup.monitor_backups'),
            ],
        ];

        $connection = self::$baseline['config']['database.connections.tenant'];
        $connection['database'] = $firm->database;
        if ($firm->db_username) {
            $connection['username'] = $firm->db_username;
            $connection['password'] = $firm->db_password;
        }

        $settings = $firm->settingsWithDefaults();
        $public = self::$baseline['config']['filesystems.disks.public'];

        config([
            'database.connections.tenant' => $connection,
            'modules'                     => ModuleProfiles::resolve($settings['profile'], $settings['modules']),
            'app.profile'                 => $settings['profile'],
            'app.cari_lock'               => $settings['cari_lock'],
            'app.cari_lock_minutes'       => $settings['cari_lock_minutes'],
            'services.whatsapp.reminder_phones'     => $firm->reminderPhones(),
            'services.whatsapp.check_reminder_days' => $settings['check_reminder_days'],
            'filesystems.disks.public'    => array_merge($public, [
                'root' => storage_path('app/public/firms/' . $firm->getKey()),
                'url'  => rtrim((string) config('app.url'), '/') . '/storage/firms/' . $firm->getKey(),
            ]),
            // tenants:run "backup:run --only-db" → firma başına ayrı yedek klasörü (firma-3/…).
            'backup.backup.name'             => 'firma-' . $firm->getKey(),
            'backup.backup.source.databases' => ['tenant'],
            'backup.monitor_backups'         => array_map(
                fn (array $m) => array_merge($m, ['name' => 'firma-' . $firm->getKey()]),
                array_slice((array) self::$baseline['config']['backup.monitor_backups'], 0, 1),
            ),
        ]);

        DB::purge('tenant');
        DB::setDefaultConnection('tenant');
        Storage::forgetDisk('public');

        self::$current = $firm;
    }

    /** Firma bağlamından çık (eski hâline dön). */
    public static function end(): void
    {
        if (self::$baseline === null) {
            return;
        }

        config(self::$baseline['config']);
        DB::purge('tenant');
        DB::setDefaultConnection(self::$baseline['default']);
        Storage::forgetDisk('public');

        self::$current = null;
        self::$baseline = null;
    }

    /**
     * Bir işi geçici olarak firmanın içinde çalıştır (hub ekranı, zamanlanmış görev, WhatsApp).
     * Önceki firma bağlamı varsa geri yüklenir.
     */
    public static function run(HubFirm $firm, callable $callback): mixed
    {
        $previous = self::$current;

        self::activate($firm);

        try {
            return $callback($firm);
        } finally {
            self::end();
            if ($previous) {
                self::activate($previous);
            }
        }
    }

    /** Firma bazlı önbellek/limit anahtarı (merkez önbellekte firmalar karışmasın). */
    public static function key(string $key): string
    {
        return self::$current ? 'firm' . self::$current->getKey() . ':' . $key : $key;
    }
}
