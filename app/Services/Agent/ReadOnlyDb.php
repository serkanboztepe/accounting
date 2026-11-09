<?php

namespace App\Services\Agent;

use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Throwable;

/**
 * Ajanın serbest okuması (plan v5 §3): firmanın SALT-OKUR veritabanı kullanıcısıyla, yalnız izinli tablolarda SELECT.
 *
 * Katmanlar: (1) veritabanı yetkisi — `<db>_ro` kullanıcısı yalnız SqlGuard::TABLES'ta SELECT yapabilir, yalnız kendi firmasının
 * veritabanında; (2) SqlGuard ön kontrolü; (3) salt-okur oturum + süre sınırı; (4) satır ve çıktı sınırı.
 *
 * Tek panelde (TENANCY) firmanın salt-okur kullanıcısı yoksa bu araç YOKTUR (available() false) — asıl kullanıcıyla okumaya düşmez.
 * Yerel geliştirme/testte (tenancy kapalı, PostgreSQL) varsayılan bağlantıda salt-okur alt işlem içinde çalışır.
 */
final class ReadOnlyDb
{
    public const MAX_ROWS = 200;

    public const MAX_CHARS = 20000;

    public const MAX_CELL = 300;

    public const TIMEOUT_SECONDS = 3;

    public const CONNECTION = 'tenant_ro';

    public static function available(): bool
    {
        if (config('database.connections.' . self::CONNECTION)) {
            return true;
        }

        return ! Tenancy::enabled() && DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * @return array{ok: bool, rows?: list<array<string, mixed>>, row_count?: int, truncated?: bool, error?: string}
     */
    public static function select(string $sql): array
    {
        if (! self::available()) {
            return ['ok' => false, 'error' => 'Serbest okuma bu firmada kurulu değil; alan araçlarını kullan.'];
        }
        $checked = SqlGuard::check($sql);
        if (! $checked['ok']) {
            return $checked;
        }
        $sql = $checked['sql'];

        try {
            $rows = config('database.connections.' . self::CONNECTION) ? self::onReadOnlyUser($sql) : self::onLocalSavepoint($sql);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => self::explain($e->getMessage(), $sql)];
        }

        $truncated = count($rows) > self::MAX_ROWS;
        $rows = array_slice($rows, 0, self::MAX_ROWS);
        $rows = array_map(fn (array $r) => array_map(fn ($v) => is_string($v) && mb_strlen($v) > self::MAX_CELL
            ? mb_substr($v, 0, self::MAX_CELL) . '…' : $v, $r), $rows);
        // Çıktı sınırı: sığdığı kadar satır.
        $size = 0;
        $kept = [];
        foreach ($rows as $r) {
            $size += strlen((string) json_encode($r, JSON_UNESCAPED_UNICODE));
            if ($size > self::MAX_CHARS) {
                $truncated = true;
                break;
            }
            $kept[] = $r;
        }

        return ['ok' => true, 'rows' => $kept, 'row_count' => count($kept), 'truncated' => $truncated];
    }

    /** Firmanın salt-okur kullanıcısı (MariaDB canlı / PostgreSQL test): salt-okur oturum + süre sınırı. */
    private static function onReadOnlyUser(string $sql): array
    {
        $db = DB::connection(self::CONNECTION);
        if ($db->getDriverName() === 'pgsql') {
            $db->statement('SET default_transaction_read_only = on');
            $db->statement('SET statement_timeout = ' . (self::TIMEOUT_SECONDS * 1000));
        } else {
            $db->statement('SET SESSION TRANSACTION READ ONLY');
            try {
                $db->statement('SET SESSION max_statement_time = ' . self::TIMEOUT_SECONDS); // MariaDB
            } catch (Throwable) {
                // MySQL: kullanıcı düzeyindeki sınır geçerli
            }
        }

        return self::fetch($db->getPdo(), $sql);
    }

    /** Yerel/test (PostgreSQL, tek veritabanı): salt-okur alt işlem — bittiğinde geri alınır, dış işlem (test) etkilenmez. */
    private static function onLocalSavepoint(string $sql): array
    {
        $db = DB::connection();
        $db->beginTransaction();
        try {
            $db->statement('SET TRANSACTION READ ONLY');
            $db->statement('SET LOCAL statement_timeout = ' . (self::TIMEOUT_SECONDS * 1000));

            return self::fetch($db->getPdo(), $sql);
        } finally {
            $db->rollBack();
        }
    }

    private static function fetch(PDO $pdo, string $sql): array
    {
        $st = $pdo->prepare($sql);
        $st->execute();
        $rows = [];
        while (count($rows) <= self::MAX_ROWS && ($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[] = $row;
        }
        $st->closeCursor();

        return $rows;
    }

    /** Veritabanı hatasını ajanın düzeltebileceği cümleye çevir (kolon yoksa kolonları, tablo yoksa tabloları söyle). */
    private static function explain(string $message, string $sql): string
    {
        $short = mb_substr(preg_replace('/\s+/', ' ', $message), 0, 240);
        $lower = mb_strtolower($message);
        if (str_contains($lower, 'unknown column') || (str_contains($lower, 'column') && str_contains($lower, 'does not exist'))) {
            $hint = collect(SqlGuard::tablesIn($sql))->map(fn (string $t) => $t . ': ' . implode(', ', self::columns($t)))->implode(' | ');

            return 'Kolon bulunamadı (' . $short . '). Kolonlar → ' . ($hint ?: 'tablo adını kontrol et');
        }
        if (str_contains($lower, 'denied') || str_contains($lower, 'permission') || str_contains($lower, "doesn't exist")
            || (str_contains($lower, 'relation') && str_contains($lower, 'does not exist'))) {
            return 'Bu tablo/kolon kapalı ya da yok. Açık tablolar: ' . implode(', ', SqlGuard::TABLES) . '.';
        }
        if (str_contains($lower, 'max_statement_time') || str_contains($lower, 'statement timeout') || str_contains($lower, 'interrupted')) {
            return 'Sorgu ' . self::TIMEOUT_SECONDS . ' saniyeyi aştı. Tarih aralığını daralt ya da alan aracını kullan.';
        }
        if (str_contains($lower, 'read-only') || str_contains($lower, 'read only')) {
            return 'Yalnız okuma yapılabilir.';
        }

        return 'Sorgu çalışmadı: ' . $short;
    }

    /** @return list<string> */
    public static function columns(string $table): array
    {
        if (! in_array($table, SqlGuard::TABLES, true)) {
            return [];
        }
        if (isset(SqlGuard::COLUMNS[$table])) {
            return SqlGuard::COLUMNS[$table];
        }
        try {
            return Schema::getColumnListing($table);
        } catch (Throwable) {
            return [];
        }
    }
}
