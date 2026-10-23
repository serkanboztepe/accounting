<?php

namespace Tests\Feature;

use App\Http\Controllers\WhatsappWebhookController;
use App\Models\HubFirm;
use App\Services\Whatsapp\ChatAssistant;
use App\Services\Whatsapp\DeferTurn;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\Understanding;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\WhatsappPendingExpense;
use App\Tenancy\FirmProvisioner;
use App\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * Tur teşhis kaydı iki firmada (TurnTrace): A firmasının arka plana bırakılan turu, arada B firmasının turu çalışsa da kendi
 * firmasının kendi tur dosyasına yazılır — firma ve tur kimliği closure ile açıkça taşınır. İki ayrı test veritabanı
 * (TenancyTest ile aynı: hesap_test_a / hesap_test_b); iki firmada gelen mesajın kimliği aynı (1) — yalnız firma ayırır.
 */
class WhatsappTurnTraceTenancyTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['central'];

    private const DB_A = 'hesap_test_a';

    private const DB_B = 'hesap_test_b';

    private const PHONE = 'whatsapp:+905556660002';

    private static bool $prepared = false;

    private string $originalDefault;

    private string $dir;

    private HubFirm $a;

    private HubFirm $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/wa-trace-tenancy-' . bin2hex(random_bytes(4));
        config(['tenancy.enabled' => true, 'services.twilio.verify_signature' => false, 'services.twilio.sid' => 'ACtest',
            'services.twilio.token' => 'tok', 'services.whatsapp.from' => '+14786665916',
            'services.whatsapp.trace' => true, 'services.whatsapp.trace_path' => $this->dir]);
        $settings = ['profile' => 'mimar', 'modules' => ['smart_assistant' => true]];
        $this->a = HubFirm::create(['name' => 'A İz', 'database' => self::DB_A, 'settings' => $settings]);
        $this->b = HubFirm::create(['name' => 'B İz', 'database' => self::DB_B, 'settings' => $settings]);
        foreach ([$this->a, $this->b] as $firm) {
            if (! self::$prepared && ! FirmProvisioner::databaseExists($firm->database)) {
                Schema::connection(FirmProvisioner::adminConnection())->createDatabase($firm->database);
            }
            Tenancy::run($firm, function () {
                if (! self::$prepared) {
                    Artisan::call('migrate', ['--database' => 'tenant', '--force' => true]);
                }
                DB::statement('TRUNCATE parties, party_ledger_entries, whatsapp_pending_expenses, whatsapp_messages, usage_logs RESTART IDENTITY CASCADE');
            });
        }
        self::$prepared = true;
        config(['services.whatsapp.trace_firms' => [(string) $this->a->id, (string) $this->b->id]]); // pilot: seçili firmalar
        $this->originalDefault = DB::getDefaultConnection();
        config(['database.default' => 'tenant']);
        DB::setDefaultConnection('tenant');
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SMx'], 201)]);
    }

    protected function tearDown(): void
    {
        Tenancy::end();
        config(['database.default' => $this->originalDefault]);
        DB::setDefaultConnection($this->originalDefault);
        if (is_dir($this->dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    /** Hub'ın firmayı açıp kontrolcüyü çağırması (HubRouter / fotoğraf sırası ile aynı yol). */
    private function webhook(string $body, string $sid): void
    {
        $request = Request::create('/whatsapp/webhook', 'POST', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0, 'MessageSid' => $sid]);
        $request->attributes->set('via_hub', true);
        $request->server->set('REQUEST_TIME_FLOAT', microtime(true));
        app()->call([app()->make(WhatsappWebhookController::class), '__invoke'], ['request' => $request]);
    }

    /** Pilot: iz yalnız seçilen firmada. A seçili, B değil → B için dosya ve klasör yok; A'nın ertelenen bölümü de yazılır. */
    public function test_behavior_pilot_and_model_requests_are_isolated_between_firms(): void
    {
        $settings = $this->a->settings;
        $settings['modules']['assistant_pilot'] = true;
        $this->a->update(['settings' => $settings]);
        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            if (! str_contains($request->url(), 'api.anthropic.com')) {
                return Http::response(['sid' => 'SMpilot'], 201);
            }
            $firmId = Tenancy::current()->id;
            $requests[$firmId][] = $request->data();
            $party = Party::where('name', 'Pilot Müşteri')->sole();

            return Http::response(['content' => [['type' => 'tool_use', 'id' => 'tool-pilot', 'name' => 'propose_entry', 'input' => [
                'kind' => ExpenseExtractor::KIND_COLLECTION, 'amount' => 25000, 'party_id' => $party->id, 'is_new_entry' => true,
                'question' => 'Bu ödeme hangi iş için?', 'question_about' => 'purpose', 'work_status_known' => false, 'party_certain' => true,
            ]]]], 200);
        });
        foreach ([$this->a, $this->b] as $i => $firm) {
            Tenancy::run($firm, function () use ($i) {
                $this->assertSame($i === 0, Understanding::pilot());
                $party = Party::create(['name' => 'Pilot Müşteri']);
                PartyLedgerEntry::create(['party_id' => $party->id, 'type' => PartyLedgerEntry::TYPE_SALE,
                    'amount' => 120000, 'entry_date' => now()->toDateString()]);
                $this->webhook('Pilot Müşteri 25 bin gönderdi', 'SMpilot' . $i);
                $pending = WhatsappPendingExpense::latest('id')->first();
                $this->assertNotNull($pending);
                $i === 0 ? $this->assertNull($pending->extracted['question'])
                    : $this->assertSame('Bu ödeme hangi iş için?', $pending->extracted['question']);
                $scale = Understanding::amountScaleQuestion(['kind' => ExpenseExtractor::KIND_COLLECTION, 'amount' => 50000],
                    "Kalan 80 binin 50'sini gönderdi");
                $i === 0 ? $this->assertNull($scale) : $this->assertNotNull($scale);
            });
        }
        $this->assertStringContainsString('question_about', json_encode($requests[$this->a->id]));
        $this->assertStringNotContainsString('question_about', json_encode($requests[$this->b->id]));
        $this->assertNull(Tenancy::current());
    }

    /** Pilot: iz yalnız seçilen firmada. */
    public function test_trace_is_written_only_for_selected_firms(): void
    {
        config(['services.whatsapp.trace_firms' => [(string) $this->a->id]]);
        $this->deferredAThenB();

        $this->assertNotEmpty(glob("{$this->dir}/{$this->a->id}/*/*.json"));
        $this->assertDirectoryDoesNotExist("{$this->dir}/{$this->b->id}");
        $a = json_decode((string) file_get_contents(glob("{$this->dir}/{$this->a->id}/*/*.json")[0]), true);
        $this->assertSame(['1', '2'], array_map('strval', array_keys($a['segments'])), "A'nın arka plan bölümü de yazıldı");
        $this->assertStringNotContainsString('B firmasının', (string) json_encode($a, JSON_UNESCAPED_UNICODE));
    }

    public function test_empty_firm_list_traces_no_firm_even_when_switch_is_on(): void
    {
        config(['services.whatsapp.trace_firms' => []]);
        $this->deferredAThenB();

        $this->assertDirectoryDoesNotExist($this->dir, 'hiçbir firmada iz yok');
    }

    /** A'da ertelenen tur, ardından B'de tur, sonra arka plan işleri. */
    private function deferredAThenB(): void
    {
        $results = [new DeferTurn('test'), ['type' => 'reply', 'text' => 'B firmasının cevabı'], ['type' => 'reply', 'text' => 'A ertelenen cevap']];
        $chat = Mockery::mock(ChatAssistant::class);
        $chat->shouldReceive('respond')->andReturnUsing(function () use (&$results) {
            $r = array_shift($results);
            if ($r instanceof \Throwable) {
                throw $r;
            }

            return $r;
        });
        $this->app->instance(ChatAssistant::class, $chat);

        Tenancy::run($this->a, fn () => $this->webhook('A firmasının mesajı', 'SMa1'));
        Tenancy::run($this->b, fn () => $this->webhook('B firmasının mesajı', 'SMb1'));
        app()->terminate();
    }

    public function test_deferred_turn_of_firm_a_does_not_mix_with_firm_b(): void
    {
        $results = [new DeferTurn('test'), ['type' => 'reply', 'text' => 'B firmasının cevabı'], ['type' => 'reply', 'text' => 'A ertelenen cevap']];
        $chat = Mockery::mock(ChatAssistant::class);
        $chat->shouldReceive('respond')->andReturnUsing(function () use (&$results) {
            $r = array_shift($results);
            if ($r instanceof \Throwable) {
                throw $r;
            }

            return $r;
        });
        $this->app->instance(ChatAssistant::class, $chat);

        Tenancy::run($this->a, fn () => $this->webhook('A firmasının mesajı', 'SMa1'));
        Tenancy::run($this->b, fn () => $this->webhook('B firmasının mesajı', 'SMb1'));
        app()->terminate(); // A'nın arka plan turu + iki firmanın iz yazımı

        $day = now('Europe/Istanbul')->toDateString();
        $fileA = "{$this->dir}/{$this->a->id}/{$day}/1.json";
        $fileB = "{$this->dir}/{$this->b->id}/{$day}/1.json";
        $this->assertFileExists($fileA);
        $this->assertFileExists($fileB);
        $a = json_decode((string) file_get_contents($fileA), true);
        $b = json_decode((string) file_get_contents($fileB), true);

        $this->assertSame(['1', '2'], array_map('strval', array_keys($a['segments'])), 'A: istek + arka plan bölümü aynı turda');
        $this->assertNotContains('missing_segment', $a['replay']['reasons']);
        $this->assertSame(['1'], array_map('strval', array_keys($b['segments'])));
        $this->assertSame((string) $this->a->id, $a['firm_id']);
        $this->assertSame((string) $this->b->id, $b['firm_id']);
        $rawA = (string) file_get_contents($fileA);
        $rawB = (string) file_get_contents($fileB);
        $this->assertStringContainsString('A ertelenen cevap', $rawA);
        $this->assertStringNotContainsString('B firmasının', $rawA, 'A dosyasında B yok');
        $this->assertStringNotContainsString('A firmasının', $rawB, 'B dosyasında A yok');
        $this->assertStringNotContainsString('A ertelenen', $rawB);
    }
}
