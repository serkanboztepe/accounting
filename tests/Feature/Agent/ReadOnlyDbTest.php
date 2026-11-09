<?php

namespace Tests\Feature\Agent;

use App\Models\Party;
use App\Services\Agent\ReadOnlyDb;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Ajanın serbest okuması — yerel (tek veritabanı) yol: salt-okur alt işlem, sınırlar, yol gösteren hatalar. */
class ReadOnlyDbTest extends TestCase
{
    use DatabaseTransactions;

    public function test_reads_rows_and_leaves_the_outer_transaction_writable(): void
    {
        $party = Party::create(['name' => 'Salt Okur Deneme Carisi']);

        $r = ReadOnlyDb::select("SELECT id, name FROM parties WHERE name = 'Salt Okur Deneme Carisi'");
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertSame([['id' => $party->id, 'name' => 'Salt Okur Deneme Carisi']], $r['rows']);
        $this->assertFalse($r['truncated']);

        // Okumadan sonra aynı bağlantı yazmaya devam eder (salt-okur ayarı alt işlemle geri alındı).
        $party->update(['notes' => 'yazılabildi']);
        $this->assertSame('yazılabildi', $party->fresh()->notes);
    }

    public function test_guard_rejections_never_reach_the_database(): void
    {
        DB::enableQueryLog();
        $r = ReadOnlyDb::select('SELECT email FROM users');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('users', $r['error']);
        $this->assertSame([], DB::getQueryLog());
    }

    /** Koruma atlansa bile salt-okur işlem yazmayı reddeder (CTE içinde yazma PostgreSQL'de mümkündür). */
    public function test_the_read_only_transaction_itself_refuses_writes(): void
    {
        $party = Party::create(['name' => 'Salt Okur Yazma Denemesi']);
        $run = new \ReflectionMethod(ReadOnlyDb::class, 'onLocalSavepoint');
        try {
            $run->invoke(null, "UPDATE parties SET notes = 'yazıldı' WHERE id = {$party->id}");
            $this->fail('yazma reddedilmeliydi');
        } catch (\Throwable $e) {
            $this->assertStringContainsStringIgnoringCase('read-only', $e->getMessage());
        }
        $this->assertNull($party->fresh()->notes);
    }

    public function test_row_and_cell_limits(): void
    {
        $r = ReadOnlyDb::select('SELECT g AS n, repeat(\'a\', 500) AS uzun FROM generate_series(1, 450) g');
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertTrue($r['truncated']);
        $this->assertLessThanOrEqual(ReadOnlyDb::MAX_ROWS, $r['row_count']);
        $this->assertLessThanOrEqual(ReadOnlyDb::MAX_CHARS + $r['row_count'] + 2, strlen(json_encode($r['rows'], JSON_UNESCAPED_UNICODE)));
        $this->assertSame(ReadOnlyDb::MAX_CELL + 1, mb_strlen($r['rows'][0]['uzun']));
    }

    public function test_unknown_column_error_lists_the_real_columns(): void
    {
        $r = ReadOnlyDb::select('SELECT tutar FROM expenses');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('expenses: ', $r['error']);
        $this->assertStringContainsString('amount', $r['error']);
        $this->assertStringContainsString('expense_date', $r['error']);

        // Hatadan sonra dış işlem bozulmaz (test işlemi "aborted" kalmaz).
        $this->assertGreaterThanOrEqual(0, Party::count());
    }

    public function test_unknown_table_error_lists_open_tables(): void
    {
        $r = ReadOnlyDb::select('SELECT * FROM faturalar');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('party_ledger_entries', $r['error']);
        $this->assertGreaterThanOrEqual(0, Party::count());
    }
}
