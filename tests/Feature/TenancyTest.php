<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Filament\Pages\SwitchFirm;
use App\Filament\Hub\HubFirms\Pages\EditHubFirm;
use App\Filament\Hub\HubFirms\RelationManagers\UsersRelationManager;
use App\Models\HubUser;
use App\Providers\Filament\HubPanelProvider;
use App\Http\Middleware\IdentifyTenant;
use App\Models\FirmUser;
use App\Models\HubFirm;
use App\Models\HubMessageLog;
use App\Models\HubPhone;
use App\Models\Party;
use App\Models\User;
use App\Tenancy\FirmProvisioner;
use App\Tenancy\Tenancy;
use Filament\Facades\Filament;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Tek panel (Model 3) izolasyonu: "A firması B'nin verisini ASLA görmez".
 * İki gerçek test veritabanı (hesap_test_a / hesap_test_b) kullanılır; ilk çalıştırmada açılıp kurulur.
 */
class TenancyTest extends TestCase
{
    use DatabaseTransactions;

    /** Merkez tablolar 'central' bağlantısında — yazdıkları geri alınsın. Firma DB'leri setUp'ta temizlenir. */
    protected $connectionsToTransact = ['central'];

    private string $originalDefault;

    private const DB_A = 'hesap_test_a';

    private const DB_B = 'hesap_test_b';

    private static bool $prepared = false;

    private HubFirm $a;

    private HubFirm $b;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.enabled' => true, 'services.twilio.verify_signature' => false]);

        $this->a = HubFirm::create(['name' => 'A Mimarlık', 'database' => self::DB_A, 'settings' => ['profile' => 'mimar']]);
        $this->b = HubFirm::create(['name' => 'B Yapı', 'database' => self::DB_B]);

        foreach ([$this->a, $this->b] as $firm) {
            if (! self::$prepared && ! FirmProvisioner::databaseExists($firm->database)) {
                Schema::connection(FirmProvisioner::adminConnection())->createDatabase($firm->database);
            }
            Tenancy::run($firm, function () {
                if (! self::$prepared) {
                    Artisan::call('migrate', ['--database' => 'tenant', '--force' => true]);
                }
                // whatsapp_messages da: "ilk kez yazan" (karşılama) önceki koşulardan kalan mesaja bağlı kalmasın (23.10 aralıklı düşüş).
                DB::statement('TRUNCATE users, parties, party_ledger_entries, whatsapp_pending_expenses, whatsapp_messages, usage_logs RESTART IDENTITY CASCADE');
            });
        }
        self::$prepared = true;

        // Üretimdeki gibi: varsayılan bağlantı 'tenant' ve firma seçilmemiş → sorgu HATA verir.
        // (Varsayılan geliştirme DB'si kalırsa yanlış veritabanından okuyan hata gizlenir.)
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

    public function test_firm_data_is_isolated(): void
    {
        Tenancy::run($this->a, fn () => Party::create(['name' => 'Sadece A Carisi']));

        $this->assertSame(1, Tenancy::run($this->a, fn () => Party::where('name', 'Sadece A Carisi')->count()));
        $this->assertSame(0, Tenancy::run($this->b, fn () => Party::where('name', 'Sadece A Carisi')->count()));
    }

    public function test_query_without_firm_fails_instead_of_hitting_some_database(): void
    {
        $this->expectException(QueryException::class);

        DB::connection('tenant')->select('select 1');
    }

    public function test_firm_settings_drive_modules_and_end_restores(): void
    {
        $before = config('modules');

        Tenancy::run($this->a, function () {
            $this->assertFalse(config('modules.contracts'), 'mimar profilinde sözleşme kapalı');
            $this->assertTrue(config('modules.land_share'));
        });
        Tenancy::run($this->b, fn () => $this->assertTrue(config('modules.contracts'), 'profilsiz firma: hepsi açık'));

        $this->assertSame($before, config('modules'));
    }

    public function test_cache_keys_are_firm_scoped(): void
    {
        $ka = Tenancy::run($this->a, fn () => Tenancy::key('check-reminder:x'));
        $kb = Tenancy::run($this->b, fn () => Tenancy::key('check-reminder:x'));

        $this->assertNotSame($ka, $kb);
        Cache::add($ka, true);
        $this->assertTrue(Cache::add($kb, true), 'A firmasının "bugün gönderildi" işareti B\'yi engellememeli');
    }

    public function test_user_mapping_is_kept_in_sync(): void
    {
        $user = Tenancy::run($this->a, fn () => User::create(['name' => 'Ferhat', 'email' => 'Ferhat@Example.com', 'password' => 'gizli-sifre-1']));
        $this->assertTrue(FirmUser::where('hub_firm_id', $this->a->id)->where('email', 'ferhat@example.com')->exists());

        Tenancy::run($this->a, fn () => User::find($user->id)->update(['email' => 'yeni@example.com']));
        $this->assertFalse(FirmUser::where('email', 'ferhat@example.com')->exists());
        $this->assertTrue(FirmUser::where('hub_firm_id', $this->a->id)->where('email', 'yeni@example.com')->exists());
    }

    public function test_login_opens_the_firm_whose_password_matches(): void
    {
        Tenancy::run($this->a, fn () => User::create(['name' => 'Ortak', 'email' => 'ortak@example.com', 'password' => 'a-firmasi-sifre']));
        Tenancy::run($this->b, fn () => User::create(['name' => 'Ortak', 'email' => 'ortak@example.com', 'password' => 'b-firmasi-sifre']));
        Filament::setCurrentPanel('admin');

        Livewire::test(Login::class)
            ->fillForm(['email' => 'ortak@example.com', 'password' => 'b-firmasi-sifre'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertSame($this->b->id, session(IdentifyTenant::SESSION_KEY));
        $this->assertSame([$this->b->id], session(IdentifyTenant::CHOICES_KEY), 'A\'nın şifresi girilmedi → A\'ya geçiş hakkı yok');
        $this->assertTrue(Tenancy::current()->is($this->b));
    }

    public function test_login_with_unknown_email_fails_cleanly(): void
    {
        Filament::setCurrentPanel('admin');

        Livewire::test(Login::class)
            ->fillForm(['email' => 'yok@example.com', 'password' => 'herhangi-bir'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertNull(Tenancy::current());
    }

    public function test_panel_request_sees_only_its_firm(): void
    {
        Tenancy::run($this->a, fn () => Party::create(['name' => 'A Betoncu']));
        $userB = Tenancy::run($this->b, function () {
            Party::create(['name' => 'B Demirci']);

            return User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => 'b-firmasi-sifre']);
        });

        // actingAs DEĞİL, gerçek oturum: kullanıcı oturumdan yüklenir — firma ondan ÖNCE seçilmeli
        // (AuthenticateSession önce çalışınca boş veritabanına gidiyordu).
        $this->withSession([
            IdentifyTenant::SESSION_KEY => $this->b->id,
            'login_web_' . sha1(SessionGuard::class) => $userB->id,
        ])
            ->get('/admin/parties')
            ->assertOk()
            ->assertSee('B Demirci')
            ->assertDontSee('A Betoncu');
    }

    public function test_switching_firm_only_among_password_verified_firms(): void
    {
        $userA = Tenancy::run($this->a, fn () => User::create(['name' => 'Ortak', 'email' => 'ortak@example.com', 'password' => 'a-firmasi-sifre']));
        Tenancy::run($this->b, fn () => User::create(['name' => 'Ortak', 'email' => 'ortak@example.com', 'password' => 'b-firmasi-sifre']));
        Filament::setCurrentPanel('admin');
        Tenancy::activate($this->a);
        $this->actingAs($userA);
        session([IdentifyTenant::SESSION_KEY => $this->a->id, IdentifyTenant::CHOICES_KEY => [$this->a->id]]);

        $this->assertFalse(SwitchFirm::canAccess(), 'tek doğrulanmış firma → menü/sayfa yok');
        try {
            (new SwitchFirm())->switchTo($this->b->id);
            $this->fail('şifresi doğrulanmamış firmaya geçilmemeli');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertTrue(Tenancy::current()->is($this->a));

        session([IdentifyTenant::CHOICES_KEY => [$this->a->id, $this->b->id]]);
        Livewire::test(SwitchFirm::class)->call('switchTo', $this->b->id);
        $this->assertSame($this->b->id, session(IdentifyTenant::SESSION_KEY));
        $this->assertTrue(Tenancy::current()->is($this->b));
    }

    public function test_inactive_firm_session_is_logged_out(): void
    {
        $user = Tenancy::run($this->b, fn () => User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => 'b-firmasi-sifre']));
        $this->b->update(['is_active' => false]);

        $this->actingAs($user)
            ->withSession([IdentifyTenant::SESSION_KEY => $this->b->id])
            ->get('/admin/parties')
            ->assertRedirect('/admin/login');
    }

    public function test_whatsapp_message_is_handled_inside_the_phone_firm(): void
    {
        HubPhone::create(['phone' => '05321234567', 'hub_firm_id' => $this->b->id]);

        $res = $this->post('/whatsapp/webhook', ['From' => 'whatsapp:+905321234567', 'Body' => 'evet', 'NumMedia' => '0'])
            ->assertOk()->getContent();

        $this->assertStringContainsString('👍', $res); // bekleyen kayıt yok → sohbet kapanışı (19.10)
        $this->assertStringContainsString('Hoş geldin', $res, 'B firmasında ilk mesaj → karşılama');
        // Gelen + iki giden (👍, karşılama) — hepsi telefonun firmasında; A firmasına hiçbir kayıt düşmez.
        $this->assertSame(['in', 'out', 'out'], HubMessageLog::where('hub_firm_id', $this->b->id)->orderBy('id')->pluck('direction')->all());
        $this->assertSame(0, HubMessageLog::where('hub_firm_id', $this->a->id)->count());
        $this->assertNull(Tenancy::current(), 'istek bitince firma bağlamı kapanmalı');
    }

    public function test_unknown_phone_gets_intro_and_touches_no_firm(): void
    {
        $res = $this->post('/whatsapp/webhook', ['From' => 'whatsapp:+905009998877', 'Body' => 'merhaba'])
            ->assertOk()->getContent();

        $this->assertStringContainsString('bir hesaba bağlı değil', $res);
    }

    public function test_signed_statement_link_opens_its_firm_and_cannot_be_redirected(): void
    {
        $party = Tenancy::run($this->a, fn () => Party::create(['name' => 'A Betoncu']));
        $url = Tenancy::run($this->a, fn () => URL::temporarySignedRoute('whatsapp.statement.pdf', now()->addMinutes(10), [
            'party' => $party->id, 'file' => 'Ekstre.pdf', 'firm' => Tenancy::current()->id,
        ]));

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Üretimde her istek sıfırdan başlar; testte önceki isteğin firma seçimi kalmasın.
        Tenancy::end();

        // Firma parametresini değiştirmek imzayı bozar → 403 (imza, {party} veritabanından
        // yüklenmeden ÖNCE kontrol edilmeli; yoksa firma seçilmemiş sorgu 500 veriyordu).
        $this->get(str_replace('firm=' . $this->a->id, 'firm=' . $this->b->id, $url))->assertForbidden();
        Tenancy::end();
        $this->get(preg_replace('/&signature=.*/', '', $url))->assertForbidden();
    }

    public function test_provisioner_creates_ready_firm_database_with_own_db_user(): void
    {
        $firm = HubFirm::create(['name' => 'Yeni Nalbur', 'database' => 'hesap_test_new']);
        $this->dropTestDatabase('hesap_test_new');

        try {
            FirmProvisioner::create($firm, 'Nalbur Bey', 'nalbur@example.com', 'gecici-sifre-1');

            $this->assertTrue(FirmUser::where('hub_firm_id', $firm->id)->where('email', 'nalbur@example.com')->exists());
            $this->assertSame(1, Tenancy::run($firm, fn () => User::count()));
            $this->assertGreaterThan(0, Tenancy::run($firm, fn () => DB::table('units')->count()));

            $this->assertSame('hesap_test_new', $firm->fresh()->db_username, 'firma kendi kullanıcısıyla bağlanmalı');
            $this->assertCannotReadOtherFirm($firm->fresh());
        } finally {
            Tenancy::end();
            $this->dropTestDatabase('hesap_test_new');
        }
    }

    public function test_existing_firm_gets_own_db_user(): void
    {
        $this->dropTestDatabase('hesap_test_old');
        $firm = HubFirm::create(['name' => 'Ortak Kullanıcılı', 'database' => 'hesap_test_old']);
        DB::connection(FirmProvisioner::adminConnection())->statement('CREATE DATABASE hesap_test_old');

        try {
            FirmProvisioner::migrate($firm);
            Tenancy::run($firm, fn () => User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'gizli-sifre-1']));
            $this->assertNull($firm->db_username);

            $this->artisan('tenants:create-db-user', ['--firm' => $firm->id])->assertSuccessful();

            $firm = $firm->fresh();
            $this->assertSame('hesap_test_old', $firm->db_username);
            $this->assertSame(1, Tenancy::run($firm, fn () => User::count()), 'veri yerinde, yeni kullanıcıyla okunuyor');
            $this->assertCannotReadOtherFirm($firm);
        } finally {
            Tenancy::end();
            $this->dropTestDatabase('hesap_test_old');
        }
    }

    /** Firmanın kendi kullanıcısı başka firmanın (A) tablosunu okuyamamalı. */
    private function assertCannotReadOtherFirm(HubFirm $firm): void
    {
        config(['database.connections.probe' => array_merge(config('database.connections.central'), [
            'database' => self::DB_A, 'username' => $firm->db_username, 'password' => $firm->db_password,
        ])]);
        DB::purge('probe');

        try {
            DB::connection('probe')->table('parties')->count();
            $this->fail("{$firm->db_username} başka firmanın veritabanını okuyabildi");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::purge('probe');
        }
    }

    private function dropTestDatabase(string $name): void
    {
        DB::purge('tenant');
        $admin = DB::connection(FirmProvisioner::adminConnection());
        $admin->statement("DROP DATABASE IF EXISTS {$name} WITH (FORCE)");
        $admin->statement("DROP ROLE IF EXISTS {$name}");
    }

    public function test_hub_features_screen_saves_firm_settings(): void
    {
        // Özellikler penceresi (plan 26.10): kutular saklanır, paket adı kutulardan türetilir.
        $this->b->saveFeaturesForm([
            'feat_stock' => true, 'feat_expenses' => true, 'feat_places' => true, 'project_label' => 'Şube',
            'asst_smart_assistant' => true,
            'cari_lock' => true, 'cari_lock_minutes' => 10, 'check_reminder_days' => '5, 1,0',
            'sector' => 'bakery', 'business_note' => '3 şubeli fırın',
        ]);

        $s = $this->b->fresh()->settingsWithDefaults();
        $this->assertSame('trade', $s['profile'], 'Stok açık → stoklu (türetilir)');
        $this->assertTrue($s['features']['stock']);
        $this->assertSame([], $s['modules'], 'eski ezmeler artık yok');
        $this->assertSame([5, 1, 0], $s['check_reminder_days']);

        Tenancy::run($this->b->fresh(), function () {
            $this->assertTrue(config('modules.stock'));
            $this->assertTrue(config('modules.report_monthly'), 'Stok\'la birlikte gelir');
            $this->assertTrue(config('modules.projects'));
            $this->assertFalse(config('modules.report_project'), 'alt kutu işaretlenmedi');
            $this->assertTrue(config('modules.checks'), 'çekirdek');
            $this->assertFalse(config('modules.quotes'), 'eski modül işaretsiz');
            $this->assertFalse(config('modules.agreements'));
            $this->assertSame('Şube', config('app.project_label'));
            $this->assertTrue(config('app.cari_lock'));
            $this->assertSame(10, config('app.cari_lock_minutes'));
            $this->assertSame('bakery', config('app.sector'), 'işletme türü firmaya geçer');
            $this->assertSame('3 şubeli fırın', config('app.business_note'));
        });
    }

    public function test_hub_features_window_shows_boxes_and_current_state(): void
    {
        // Çevrilmemiş firma (Akkuş ayarı): pencere bugünkü durumu kutulara çevrilmiş gösterir.
        $this->b->update(['settings' => ['profile' => 'trade', 'modules' => ['projects' => true]]]);
        $data = $this->b->fresh()->featuresFormData();

        $this->assertTrue($data['feat_stock']);
        $this->assertTrue($data['feat_places']);
        $this->assertFalse($data['feat_place_reports']);
        $this->assertFalse($data['feat_agreements']);
        $this->assertSame('Şube', $data['project_label']);
        $this->assertFalse($data['legacy_quotes']);
        foreach (\App\Support\FeatureSettings::FEATURES as $k) {
            $this->assertArrayHasKey("feat_{$k}", $data, "pencerede {$k} kutusu dolar");
        }
    }

    public function test_hub_features_screen_land_share_opens_places(): void
    {
        $this->b->saveFeaturesForm(['feat_land_share' => true, 'feat_places' => false, 'project_label' => 'Proje']);

        $this->assertSame('mimar', $this->b->fresh()->settingsWithDefaults()['profile']);
        Tenancy::run($this->b->fresh(), fn () => $this->assertTrue(config('modules.projects'), 'Hisse Yerler\'i açar'));
    }

    public function test_convert_command_dry_run_writes_nothing_then_write_converts(): void
    {
        // Akkuş'un canlı ayarı (stoklu + şube) ve Ferhat'ınki (mimar).
        $this->b->update(['settings' => ['profile' => 'trade', 'modules' => ['projects' => true]]]);
        $this->a->update(['settings' => ['profile' => 'mimar', 'modules' => ['checks' => true, 'cari_supplier' => true]]]);
        $before = $this->b->fresh()->settings;

        $this->assertSame(0, Artisan::call('firms:features-convert'));
        $this->assertSame($before, $this->b->fresh()->settings, 'deneme kipi yazmaz');
        $this->assertStringContainsString('checks: kapalı → açık', Artisan::output());

        $this->assertSame(0, Artisan::call('firms:features-convert', ['--write' => true]));
        $b = $this->b->fresh()->settingsWithDefaults();
        $this->assertTrue($b['features']['stock']);
        $this->assertSame('Şube', $b['project_label'], 'görünen ad saklandı');
        $this->assertSame('trade', $b['profile']);
        Tenancy::run($this->b->fresh(), function () {
            $this->assertTrue(config('modules.checks'), 'çek açıldı (karar)');
            $this->assertTrue(config('modules.projects'));
            $this->assertFalse(config('modules.report_project'), 'Raporlar açılmadı');
        });
        Tenancy::run($this->a->fresh(), function () {
            $this->assertTrue(config('modules.land_share'));
            $this->assertFalse(config('modules.expenses'), 'mimarda gider kapalı kaldı');
        });

        $this->assertSame(0, Artisan::call('firms:features-convert'));
        $this->assertStringContainsString('zaten yeni düzende', Artisan::output());
    }

    /**
     * Pasif firma deploy'da güncellenmez; yeniden aktif edilince eksik kolonla kalıyordu ve --firm ile de güncellenemiyordu
     * (pasif liste dışıydı). Şimdi aktif edildiği an migration çalışır; --firm pasifi de kapsar.
     */
    public function test_reactivated_firm_gets_its_database_updated(): void
    {
        $this->b->update(['is_active' => false]);
        $this->assertFalse(\App\Console\Commands\TenantsRun::firms()->contains('id', $this->b->id), 'toplu çalışmada pasif yok');
        $this->assertTrue(\App\Console\Commands\TenantsRun::firms((string) $this->b->id)->contains('id', $this->b->id), '--firm pasifi de bulur');

        Tenancy::run($this->b, fn () => Artisan::call('migrate:rollback', ['--database' => 'tenant', '--step' => 2, '--force' => true]));
        $this->assertFalse(Tenancy::run($this->b, fn () => Schema::connection('tenant')->hasColumn('reminders', 'notify_time')), 'eski veritabanı');

        $this->b->update(['is_active' => true]);

        $this->assertTrue(Tenancy::run($this->b, fn () => Schema::connection('tenant')->hasColumn('reminders', 'notify_time')), 'aktif edilince güncellendi');
    }

    public function test_convert_command_stops_on_unexpected_difference(): void
    {
        // Paketsiz firma (hepsi açık): kutulardan türetilen paket "mimar" olur → üslup değişir → beklenmeyen.
        $this->a->update(['settings' => ['profile' => 'mimar']]);
        $this->b->update(['settings' => []]);
        $aBefore = $this->a->fresh()->settings;

        $this->assertSame(1, Artisan::call('firms:features-convert', ['--write' => true]));
        $this->assertStringContainsString('HİÇBİR firma yazılmadı', Artisan::output());
        $this->assertSame($aBefore, $this->a->fresh()->settings, 'sorunsuz firma da yazılmaz');
        $this->assertNull($this->b->fresh()->settingsWithDefaults()['features']);
    }

    public function test_hub_adds_user_into_firm_database(): void
    {
        $this->bootHubPanel();

        Livewire::test(UsersRelationManager::class, ['ownerRecord' => $this->b, 'pageClass' => EditHubFirm::class])
            ->callTableAction('addUser', data: ['name' => 'Nalbur Bey', 'email' => 'nalbur@example.com', 'password' => 'gecici-sifre-1'])
            ->assertHasNoTableActionErrors();

        $this->assertTrue(Tenancy::run($this->b, fn () => User::where('email', 'nalbur@example.com')->exists()));
        $this->assertFalse(Tenancy::run($this->a, fn () => User::where('email', 'nalbur@example.com')->exists()));
        $this->assertTrue(FirmUser::where('hub_firm_id', $this->b->id)->where('email', 'nalbur@example.com')->exists());
    }

    private function bootHubPanel(): void
    {
        if (! isset(Filament::getPanels()['hub'])) {
            // Uygulamada TENANCY açıkken açılışta yüklenir; testte sonradan.
            $this->app->register(HubPanelProvider::class);
        }
        Filament::setCurrentPanel('hub');
        $this->actingAs(HubUser::forceCreate(['name' => 'Hub', 'email' => 'hub-' . uniqid() . '@example.com', 'password' => 'x']), 'hub');
    }
}
