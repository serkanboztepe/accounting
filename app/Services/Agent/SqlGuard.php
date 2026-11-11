<?php

namespace App\Services\Agent;

/**
 * Ajanın yazdığı sorgunun ön kontrolü (plan v5 §3). ASIL sınır veritabanı kullanıcısının yetkisidir (yalnız izinli
 * tablolarda SELECT); bu sınıf ikinci kattır ve ajana yol gösteren hata mesajı üretir.
 *
 * Kurallar: tek ifade · SELECT / WITH ile başlar · yorum yok · yazma/yönetim kelimesi yok · dosya, bekleme, kilit,
 * sistem değişkeni, sistem şeması ve başka veritabanı yok · kapalı tablo/kolon adı geçmez.
 */
final class SqlGuard
{
    public const MAX_LENGTH = 4000;

    /** Ajanın okuyabildiği tablolar — veritabanı yetkisiyle AYNI liste (database/provisioning/mariadb.sql; SqlGuardTest eşitliği doğrular). */
    public const TABLES = [
        'parties', 'party_ledger_entries', 'expenses', 'expense_categories', 'projects', 'agreements', 'agreement_items',
        'checks', 'products', 'units', 'stock_movements', 'sales', 'purchases', 'sale_returns', 'purchase_returns',
        'reminders', 'assistant_memories', 'whatsapp_messages', 'whatsapp_pending_expenses',
        'contracts', 'contract_items', 'contract_deliveries', 'contract_payments', 'activity_logs',
    ];

    /** Kolon bazında verilen tablolar: yalnız bu kolonlar okunur (fiş/çek fotoğrafı adresi kapalı). */
    public const COLUMNS = [
        'whatsapp_pending_expenses' => ['id', 'phone', 'extracted', 'summary', 'status', 'follow_ups', 'followed_up_at', 'created_at', 'updated_at'],
    ];

    /** Adı sorguda geçemeyen tablolar (giriş bilgisi, oturum, ayar, merkez). */
    private const CLOSED_TABLES = [
        'users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations',
        'company_settings', 'firm_users', 'usage_logs',
    ];

    private const CLOSED_COLUMNS = ['password', 'remember_token', 'cari_pin', 'media_url', 'app_authentication_secret', 'app_authentication_recovery_codes'];

    private const FORBIDDEN_WORDS = [
        'insert', 'update', 'delete', 'merge', 'upsert', 'drop', 'alter', 'create', 'truncate', 'rename', 'grant', 'revoke',
        'call', 'do', 'set', 'into', 'outfile', 'dumpfile', 'load_file', 'load', 'copy', 'handler', 'prepare', 'execute', 'deallocate',
        'lock', 'unlock', 'sleep', 'benchmark', 'get_lock', 'release_lock', 'is_free_lock', 'pg_sleep', 'pg_read_file', 'lo_import',
        'lo_export', 'dblink', 'begin', 'commit', 'rollback', 'savepoint', 'show', 'explain', 'analyze', 'vacuum', 'use', 'kill',
        'shutdown', 'procedure', 'trigger', 'information_schema', 'performance_schema', 'pg_catalog', 'mysql', 'sys',
    ];

    /**
     * @return array{ok: bool, sql?: string, error?: string}
     */
    public static function check(string $sql): array
    {
        $sql = trim($sql);
        $sql = rtrim(rtrim($sql, ';'));
        if ($sql === '') {
            return self::no('Sorgu boş.');
        }
        if (mb_strlen($sql) > self::MAX_LENGTH) {
            return self::no('Sorgu çok uzun (en çok ' . self::MAX_LENGTH . ' karakter). Daha dar bir sorgu yaz.');
        }

        // Metin sabitleri kontrol dışında: açıklamada "update" kelimesini aramak serbest ('%update%').
        $bare = self::withoutStrings($sql);
        if ($bare === null) {
            return self::no('Tırnak kapanmamış.');
        }
        $lower = mb_strtolower($bare, 'UTF-8');

        if (str_contains($lower, ';')) {
            return self::no('Tek sorgu yaz (noktalı virgülle ikinci ifade olmaz).');
        }
        if (preg_match('~--|/\*|\*/|#~', $lower)) {
            return self::no('Yorum işareti (--, #, /* */) kullanma.');
        }
        if (! preg_match('/^\(*\s*(select|with)\b/', $lower)) {
            return self::no('Yalnız SELECT (ya da WITH … SELECT) sorgusu çalışır.');
        }
        if (str_contains($lower, '@')) {
            return self::no('Değişken (@, @@) kullanma.');
        }
        if (preg_match('/\bfor\s+(update|share|no\s+key|key\s+share)\b|\block\s+in\s+share\b/', $lower)) {
            return self::no('Kilitleyen sorgu (FOR UPDATE / FOR SHARE) olmaz.');
        }
        foreach (self::FORBIDDEN_WORDS as $word) {
            if (preg_match('/(?<![a-z0-9_])' . preg_quote($word, '/') . '(?![a-z0-9_])/', $lower)) {
                return self::no('"' . $word . '" kullanılamaz — yalnız okuma sorgusu yaz. Açık tablolar: ' . implode(', ', self::TABLES) . '.');
            }
        }
        if (preg_match('/(?<![a-z0-9_])(hub_[a-z0-9_]+|pg_[a-z0-9_]+)/', $lower, $m)) {
            return self::no('"' . $m[1] . '" kapalı. Açık tablolar: ' . implode(', ', self::TABLES) . '.');
        }
        foreach (self::CLOSED_TABLES as $table) {
            if (preg_match('/(?<![a-z0-9_])' . $table . '(?![a-z0-9_])/', $lower)) {
                return self::no('"' . $table . '" tablosu kapalı. Açık tablolar: ' . implode(', ', self::TABLES) . '.');
            }
        }
        foreach (self::CLOSED_COLUMNS as $column) {
            if (preg_match('/(?<![a-z0-9_])' . $column . '(?![a-z0-9_])/', $lower)) {
                return self::no('"' . $column . '" kolonu kapalı.');
            }
        }
        // Başka veritabanı: firma veritabanı öneki ("hesap_x.parties") ya da üç parçalı ad. (EXTRACT(month FROM e.entry_date) serbest.)
        $prefix = preg_quote(mb_strtolower((string) config('tenancy.database_prefix', 'hesap_')), '/');
        if (($prefix !== '' && preg_match('/(?<![a-z0-9_])(' . $prefix . '[a-z0-9_]*|[a-z0-9_]+_mgmt)[`"]?\s*\./', $lower))
            || preg_match('/[`"]?[a-z_][a-z0-9_]*[`"]?\s*\.\s*[`"]?[a-z_][a-z0-9_]*[`"]?\s*\.\s*[`"]?[a-z_*][a-z0-9_]*/', $lower)) {
            return self::no('Tablo adını tek başına yaz (veritabanı adı ekleme).');
        }
        // whatsapp_pending_expenses kolon bazında açık: "SELECT *" yetki hatası verir — baştan söyle.
        if (str_contains($lower, 'whatsapp_pending_expenses') && preg_match('/(?<![a-z0-9_])(\*|[a-z0-9_]+\.\*)/', str_replace('count(*)', '', $lower))) {
            return self::no('whatsapp_pending_expenses için kolonları tek tek yaz: ' . implode(', ', self::COLUMNS['whatsapp_pending_expenses']) . '.');
        }

        return ['ok' => true, 'sql' => $sql];
    }

    /** Sorguda adı geçen açık tablolar (hata mesajında kolon listesi vermek için). */
    public static function tablesIn(string $sql): array
    {
        $lower = mb_strtolower((string) self::withoutStrings($sql), 'UTF-8');

        return array_values(array_filter(self::TABLES, fn (string $t) => (bool) preg_match('/(?<![a-z0-9_])' . $t . '(?![a-z0-9_])/', $lower)));
    }

    /** Tek tırnaklı metinleri boşalt ('' ve \' kaçışlarıyla); kapanmamış tırnakta null. Çift tırnak / ters tırnak ad sayılır, kalır. */
    private static function withoutStrings(string $sql): ?string
    {
        $out = '';
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            if ($c !== "'") {
                $out .= $c;

                continue;
            }
            $closed = false;
            for ($i++; $i < $len; $i++) {
                if ($sql[$i] === '\\') {
                    $i++;

                    continue;
                }
                if ($sql[$i] === "'") {
                    if (($sql[$i + 1] ?? '') === "'") {
                        $i++;

                        continue;
                    }
                    $closed = true;
                    break;
                }
            }
            if (! $closed) {
                return null;
            }
            $out .= "''";
        }

        return $out;
    }

    private static function no(string $error): array
    {
        return ['ok' => false, 'error' => $error];
    }
}
