<?php

namespace Tests\Feature\Agent;

use App\Console\Commands\TenantsCreateRoUser;
use App\Models\HubFirm;
use App\Models\Party;
use App\Models\User;
use App\Services\Agent\ReadOnlyDb;
use App\Tenancy\FirmProvisioner;
use App\Tenancy\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Ajanın salt-okur veritabanı kullanıcısı — GERÇEK veritabanı yetkisiyle (TenancyTest'in hesap_test_a / hesap_test_b veritabanları).
 * Asıl sınır budur: koruma katmanı (SqlGuard) atlansa bile kullanıcı kapalı tabloyu okuyamaz, yazamaz, öteki firmaya giremez.
 */
class ReadOnlyUserTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['central'];

    private string $originalDefault;

    private HubFirm $a;

    private HubFirm $b;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true]);

        $this->a = HubFirm::create(['name' => 'A Salt Okur', 'database' => 'hesap_test_a']);
        $this->b = HubFirm::create(['name' => 'B Salt Okur', 'database' => 'hesap_test_b']);
        foreach ([$this->a, $this->b] as $firm) {
            if (! FirmProvisioner::databaseExists($firm->database)) {
                Schema::connection(FirmProvisioner::adminConnection())->createDatabase($firm->database);
            }
            Tenancy::run($firm, function () {
                Artisan::call('migrate', ['--database' => 'tenant', '--force' => true]);
                DB::statement('TRUNCATE users, parties, party_ledger_entries, whatsapp_pending_expenses, whatsapp_messages RESTART IDENTITY CASCADE');
            });
        }
        $this->originalDefault = DB::getDefaultConnection();
        config(['database.default' => 'tenant']);
        DB::setDefaultConnection('tenant');
    }

    protected function tearDown(): void
    {
        Tenancy::end();
        config(['database.default' => $this->originalDefault]);
        DB::setDefaultConnection($this->originalDefault);
        parent::tearDown();
    }

    public function test_without_a_read_only_user_free_reading_does_not_exist(): void
    {
        Tenancy::run($this->a, function () {
            $this->assertNull(config('database.connections.tenant_ro'));
            $this->assertFalse(ReadOnlyDb::available());
            $this->assertFalse(ReadOnlyDb::select('SELECT 1 FROM parties')['ok']);
        });
    }

    public function test_read_only_user_reads_allowed_tables_and_nothing_else(): void
    {
        Tenancy::run($this->a, function () {
            Party::create(['name' => 'A Firmasının Carisi']);
            User::create(['name' => 'A Kullanıcı', 'email' => 'a-ro@example.test', 'password' => 'gizli-sifre-123']);
        });
        Tenancy::run($this->b, fn () => Party::create(['name' => 'B Firmasının Carisi']));

        FirmProvisioner::giveReadOnlyUser($this->a);
        $this->a->refresh();
        $this->assertSame('hesap_test_a_ro', $this->a->db_ro_username);
        $this->assertNotSame($this->a->db_ro_password, $this->a->getRawOriginal('db_ro_password'), 'şifre merkezde şifreli');
        $this->assertArrayNotHasKey('db_ro_password', $this->a->toArray());

        Tenancy::run($this->a, function () {
            $this->assertTrue(ReadOnlyDb::available());
            // 1) Araç yolu: okur.
            $r = ReadOnlyDb::select('SELECT name FROM parties');
            $this->assertTrue($r['ok'], $r['error'] ?? '');
            $this->assertSame([['name' => 'A Firmasının Carisi']], $r['rows']);
            $this->assertTrue(ReadOnlyDb::select('SELECT id, status, summary FROM whatsapp_pending_expenses')['ok']);

            // 2) Koruma katmanı OLMADAN, doğrudan salt-okur bağlantıda: veritabanı reddeder.
            $ro = DB::connection(ReadOnlyDb::CONNECTION);
            foreach ([
                'select email, password from users',
                'select * from company_settings',
                'select * from sessions',
                'select media_url from whatsapp_pending_expenses',
                'select * from whatsapp_pending_expenses',
                "insert into parties (name) values ('sızdı')",
                "update parties set name = 'değişti'",
                'delete from parties',
                'create table sizinti (id int)',
                'drop table parties',
            ] as $sql) {
                try {
                    $ro->statement($sql);
                    $this->fail('veritabanı reddetmeliydi: ' . $sql);
                } catch (QueryException $e) {
                    $this->assertMatchesRegularExpression('/permission denied|read-only/i', $e->getMessage(), $sql);
                }
            }
            $this->assertSame([], TenantsCreateRoUser::verify(), 'komutun kendi doğrulaması');
            $this->assertSame(1, Party::count(), 'veri değişmedi');
            $this->assertSame('A Firmasının Carisi', Party::value('name'));
        });

        // 3) A'nın salt-okur kullanıcısı B'nin veritabanına bağlanamaz.
        config(['database.connections.sizinti' => array_merge(config('database.connections.central'), [
            'database' => 'hesap_test_b', 'username' => $this->a->db_ro_username, 'password' => $this->a->db_ro_password])]);
        try {
            DB::connection('sizinti')->select('select name from parties');
            $this->fail('öteki firmanın veritabanı okunmamalıydı');
        } catch (\Throwable $e) {
            $this->assertMatchesRegularExpression('/permission denied|denied/i', $e->getMessage());
        } finally {
            DB::purge('sizinti');
        }

        // 4) B'de salt-okur kullanıcı yok → B'de araç yok; firma değişince A'nın bağlantısı kalmaz.
        Tenancy::run($this->b, function () {
            $this->assertNull(config('database.connections.tenant_ro'));
            $this->assertFalse(ReadOnlyDb::available());
        });
        $this->assertNull(config('database.connections.tenant_ro'), 'firma bağlamı bitince salt-okur bağlantı da biter');
    }

    public function test_command_creates_verifies_and_is_repeatable(): void
    {
        $this->artisan('tenants:create-ro-user', ['--firm' => $this->a->id])->assertSuccessful();
        $first = $this->a->fresh()->db_ro_password;
        $this->artisan('tenants:create-ro-user', ['--firm' => $this->a->id])->assertSuccessful();
        $this->assertNotSame($first, $this->a->fresh()->db_ro_password, 'tekrar çalışınca şifre yenilenir');
        Tenancy::run($this->a->fresh(), fn () => $this->assertTrue(ReadOnlyDb::select('SELECT count(*) AS n FROM parties')['ok']));
    }
}
