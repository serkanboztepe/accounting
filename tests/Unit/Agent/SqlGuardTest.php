<?php

namespace Tests\Unit\Agent;

use App\Services\Agent\SqlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Ajanın serbest okuması — sorgu ön kontrolü (plan v5 §3). Asıl sınır veritabanı yetkisi; burası ikinci kat + yol gösteren hata. */
class SqlGuardTest extends TestCase
{
    public static function allowed(): array
    {
        return [
            'basit' => ['SELECT id, name FROM parties ORDER BY name LIMIT 20'],
            'sonda noktalı virgül' => ['select count(*) from expenses;'],
            'birleştirme + takma ad' => ['SELECT p.name, SUM(e.amount) AS toplam FROM party_ledger_entries e JOIN parties p ON p.id = e.party_id WHERE e.type = \'odeme\' GROUP BY p.name'],
            'WITH' => ['WITH t AS (SELECT party_id, SUM(amount) s FROM party_ledger_entries GROUP BY party_id) SELECT * FROM t WHERE s > 1000'],
            'metinde yasak kelime' => ["SELECT id FROM expenses WHERE description LIKE '%update; drop table users -- %'"],
            'metinde kaçışlı tırnak' => ["SELECT id FROM parties WHERE name = 'O''Brien delete'"],
            'updated_at kolonu' => ['SELECT id, updated_at, created_at FROM expenses WHERE updated_at >= \'2026-11-01\''],
            'EXTRACT … FROM takma.kolon' => ['SELECT EXTRACT(MONTH FROM e.entry_date) AS ay, SUM(e.amount) FROM party_ledger_entries e GROUP BY 1'],
            'replace işlevi' => ["SELECT REPLACE(name, ' ', '') FROM parties"],
            'bekleyen kayıt kolonlu' => ['SELECT id, status, summary FROM whatsapp_pending_expenses ORDER BY id DESC LIMIT 5'],
            'bekleyen kayıt sayım' => ['SELECT count(*) FROM whatsapp_pending_expenses WHERE status = \'confirmed\''],
            'alt sorgu' => ['SELECT name FROM parties WHERE id IN (SELECT party_id FROM checks WHERE status = \'issued\')'],
            'parantezli union' => ['(SELECT id FROM sales) UNION ALL (SELECT id FROM purchases)'],
        ];
    }

    public static function rejected(): array
    {
        return [
            'boş' => ['  ', 'boş'],
            'yazma' => ['UPDATE parties SET name = \'x\'', 'SELECT'],
            'silme' => ['delete from expenses', 'SELECT'],
            'iki ifade' => ['SELECT 1; DELETE FROM expenses', 'Tek sorgu'],
            'yazan CTE' => ['WITH d AS (DELETE FROM expenses RETURNING id) SELECT * FROM d', 'delete'],
            'INTO OUTFILE' => ["SELECT * FROM parties INTO OUTFILE '/tmp/x'", 'into'],
            'SELECT INTO' => ['SELECT * INTO kopya FROM parties', 'into'],
            'dosya okuma' => ["SELECT LOAD_FILE('/etc/passwd')", 'load_file'],
            'bekletme' => ['SELECT SLEEP(10)', 'sleep'],
            'pg bekletme' => ['SELECT pg_sleep(10)', 'pg_sleep'],
            'benchmark' => ['SELECT BENCHMARK(100000000, MD5(1))', 'benchmark'],
            'kilit' => ['SELECT * FROM parties FOR UPDATE', 'FOR UPDATE'],
            'paylaşımlı kilit' => ['SELECT * FROM parties LOCK IN SHARE MODE', 'Kilitleyen'],
            'sistem değişkeni' => ['SELECT @@version', 'Değişken'],
            'kullanıcı değişkeni' => ['SELECT @a := 1', 'Değişken'],
            'sistem şeması' => ['SELECT table_name FROM information_schema.tables', 'information_schema'],
            'mysql şeması' => ['SELECT user FROM mysql.user', 'mysql'],
            'pg kataloğu' => ['SELECT rolname FROM pg_roles', 'pg_roles'],
            'kullanıcılar' => ['SELECT email FROM users', 'users'],
            'kullanıcılar alt sorguda' => ['SELECT name FROM parties WHERE id IN (SELECT id FROM users)', 'users'],
            'şifre kolonu' => ['SELECT password FROM parties', 'password'],
            'cari pin' => ['SELECT cari_pin FROM parties', 'cari_pin'],
            'ayarlar' => ['SELECT * FROM company_settings', 'company_settings'],
            'oturumlar' => ['SELECT payload FROM sessions', 'sessions'],
            'merkez tablo' => ['SELECT * FROM hub_firms', 'hub_firms'],
            'merkez telefon' => ['SELECT phone FROM hub_phones', 'hub_phones'],
            'başka firma' => ['SELECT name FROM hesap_baska.parties', 'veritabanı adı'],
            'başka firma ters tırnak' => ['SELECT name FROM `hesap_baska`.`parties`', 'veritabanı adı'],
            'üç parçalı ad' => ['SELECT x.parties.name FROM parties', 'veritabanı adı'],
            'yorumla gizleme' => ['SELECT 1 /* x */ FROM parties', 'Yorum'],
            'satır yorumu' => ["SELECT 1 FROM parties -- where", 'Yorum'],
            'kare yorumu' => ['SELECT 1 FROM parties # x', 'Yorum'],
            'fotoğraf adresi' => ['SELECT media_url FROM whatsapp_pending_expenses', 'media_url'],
            'bekleyen kayıt yıldız' => ['SELECT * FROM whatsapp_pending_expenses', 'kolonları tek tek'],
            'bekleyen kayıt takma yıldız' => ['SELECT w.* FROM whatsapp_pending_expenses w', 'kolonları tek tek'],
            'kapanmamış tırnak' => ["SELECT 'abc FROM parties", 'Tırnak'],
            'CALL' => ['SELECT 1 FROM parties WHERE (call x())', 'call'],
            'çok uzun' => ['SELECT ' . str_repeat('1,', 2500) . '1', 'uzun'],
        ];
    }

    #[DataProvider('allowed')]
    public function test_read_queries_pass(string $sql): void
    {
        $r = SqlGuard::check($sql);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertStringEndsNotWith(';', $r['sql']);
    }

    #[DataProvider('rejected')]
    public function test_everything_else_is_rejected_with_a_helpful_message(string $sql, string $hint): void
    {
        $r = SqlGuard::check($sql);
        $this->assertFalse($r['ok'], 'geçmemeliydi: ' . $sql);
        $this->assertStringContainsStringIgnoringCase($hint, $r['error']);
    }

    /** Veritabanı yetkisi (yordam) ile koddaki liste AYNI olmalı — biri değişip öteki unutulmasın. */
    public function test_table_list_matches_the_database_grant_procedure(): void
    {
        $sql = file_get_contents(database_path('provisioning/mariadb.sql'));
        $this->assertSame(1, preg_match('/table_name IN \((.*?)\);/s', $sql, $m));
        preg_match_all("/'([a-z_]+)'/", $m[1], $names);
        $fromProcedure = $names[1];
        // Kolon bazında verilenler yordamda ayrı GRANT ile.
        foreach (SqlGuard::COLUMNS as $table => $columns) {
            $this->assertNotContains($table, $fromProcedure, 'kolon bazlı tablo tümden açılmamalı');
            $this->assertStringContainsString('GRANT SELECT (' . implode(', ', $columns) . ') ON', $sql);
            $fromProcedure[] = $table;
        }
        $this->assertEqualsCanonicalizing(SqlGuard::TABLES, $fromProcedure);
        foreach (['users', 'company_settings', 'sessions', 'password_reset_tokens'] as $closed) {
            $this->assertNotContains($closed, SqlGuard::TABLES);
        }
    }

    public function test_tables_in_query_are_found_for_error_hints(): void
    {
        $this->assertSame(['parties', 'party_ledger_entries'],
            SqlGuard::tablesIn("SELECT * FROM party_ledger_entries e JOIN parties p ON p.id = e.party_id WHERE e.description = 'expenses'"));
    }
}
