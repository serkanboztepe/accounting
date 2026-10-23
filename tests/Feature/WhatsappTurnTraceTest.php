<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\TraceContext;
use App\Services\Whatsapp\TurnTrace;
use App\Support\ModuleProfiles;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * WhatsApp tur teşhis kaydı (TurnTrace, 23.10 Adım 1). Asıl şart: iz AÇIK ve KAPALI iken aynı kontrollü girdiler aynı cevabı,
 * aynı taslağı, aynı kayıtları ve aynı AI isteklerini üretir. AI gerçek değil: HTTP katmanında taklit edilir (ChatAssistant ve
 * çıkarıcının kendi kodu çalışır, model çağrısı izde görünür). Her senaryo iki kez çalışır; arada veritabanı savepoint ile, dizi
 * sayaçları setval ile geri alınır — kimlikler de birebir aynı olur.
 */
class WhatsappTurnTraceTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905556660001';

    private string $dir;

    /** @var list<array|\Closure> sohbet modeline sırayla verilecek cevaplar */
    private array $ai = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/wa-trace-test-' . bin2hex(random_bytes(4));
        config(['modules' => ModuleProfiles::resolve('mimar', ['smart_assistant' => true, 'cari_supplier' => true]), 'app.profile' => 'mimar',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 666 00 01'],
            'services.twilio.sid' => 'ACtest', 'services.twilio.token' => 'tok', 'services.whatsapp.from' => '+14786665916',
            'services.anthropic.api_key' => 'test-key', 'services.whatsapp.trace_path' => $this->dir, 'services.whatsapp.trace' => false]);
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        Http::fake(fn (Request $r) => $this->fakeHttp($r));
        TurnTrace::$failHook = null;
        TurnTrace::$beforeTurnFile = null;
    }

    protected function tearDown(): void
    {
        TurnTrace::$failHook = null;
        TurnTrace::$beforeTurnFile = null;
        if (($keep = getenv('WA_TRACE_KEEP')) && is_dir($this->dir)) {
            // İnceleme için: izleri verilen klasöre taşı (ör. örnek iz çıktısı).
            @mkdir($keep, 0775, true);
            rename($this->dir, rtrim($keep, '/') . '/' . $this->name() . '-' . basename($this->dir));
        }
        $this->rmrf($this->dir);
        parent::tearDown();
    }

    // ------------------------------------------------------------------ taklit AI

    private function fakeHttp(Request $r)
    {
        if (! str_contains($r->url(), 'api.anthropic.com')) {
            return Http::response(['sid' => 'SMx'], 201);
        }
        $system = json_encode($r['system'] ?? '', JSON_UNESCAPED_UNICODE);
        if (str_contains($system, 'kayda çevrildi')) {
            return Http::response($this->toolUse('check', ['same' => true])); // süzgeç: çelişki yok
        }
        if (str_contains($system, 'Esnafa bir soru soruldu')) {
            return Http::response($this->toolUse('judge', ['answers' => true])); // ikinci göz: cevap soruyu cevaplıyor
        }
        $next = array_shift($this->ai);
        if ($next instanceof \Closure) {
            return $next($r);
        }

        return Http::response($this->toolUse($next['tool'] ?? 'reply', $next['input'] ?? ['text' => 'Tamam.']));
    }

    private function toolUse(string $name, array $input): array
    {
        return ['id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'test',
            'content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => $name, 'input' => $input]],
            'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 100, 'output_tokens' => 20]];
    }

    private function entry(array $o): array
    {
        return ['tool' => 'propose_entry', 'input' => array_merge(['items' => [], 'payment_type' => null, 'date' => '2026-10-23',
            'due_date' => null, 'project_id' => null, 'project_name' => null, 'party_id' => null, 'party_name' => null, 'category_id' => null,
            'category_name' => null, 'paid' => null, 'confidence' => 'high', 'question' => null, 'is_new_entry' => true,
            'work_status' => null, 'work_status_known' => null, 'party_certain' => true], $o)];
    }

    private function send(string $body, array $server = []): string
    {
        return html_entity_decode($this->withServerVariables($server)->post('/whatsapp/webhook',
            ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0, 'MessageSid' => 'SM' . md5($body . count($this->ai))])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    // ------------------------------------------------------------------ eşitlik düzeni

    /**
     * Senaryoyu iz kapalı ve açık çalıştır, gözlenebilir her şeyi döndür. Arada her şey geri alınır.
     *
     * @return array{0:array,1:array}
     */
    private function both(callable $scenario): array
    {
        return [$this->observe($scenario, false), $this->observe($scenario, true)];
    }

    private function observe(callable $scenario, bool $trace): array
    {
        config(['services.whatsapp.trace' => $trace]);
        $seqs = $this->sequences();
        $marks = ['pending' => (int) WhatsappPendingExpense::max('id'), 'ledger' => (int) PartyLedgerEntry::max('id'),
            'messages' => (int) WhatsappMessage::max('id'), 'parties' => (int) Party::max('id'), 'expenses' => (int) \App\Models\Expense::max('id')];
        $http = Http::recorded()->count();
        (fn () => $this->terminatingCallbacks = [])->call($this->app); // önceki çalıştırmanın istek-sonrası işleri tekrar çalışmasın
        Cache::flush();
        $this->travelTo('2026-10-23 11:00:00');
        DB::beginTransaction();
        try {
            $replies = $scenario();
            $requests = array_map(fn ($pair) => [$pair[0]->url(), $pair[0]->body()], Http::recorded()->slice($http)->values()->all());

            return [
                'replies' => $replies,
                'requests' => $requests,
                'drafts' => WhatsappPendingExpense::where('id', '>', $marks['pending'])->orderBy('id')->get()
                    ->map(fn ($p) => [$p->id, $p->status, $p->summary, $p->extracted])->all(),
                'ledger' => PartyLedgerEntry::where('id', '>', $marks['ledger'])->orderBy('id')->get()
                    ->map(fn ($l) => [$l->id, $l->party_id, $l->type, $l->direction, (float) $l->amount, $l->description, $l->purchase_id])->all(),
                'expenses' => \App\Models\Expense::where('id', '>', $marks['expenses'])->orderBy('id')->get()
                    ->map(fn ($e) => [$e->id, (float) $e->amount, $e->description, $e->payment_status, $e->party_id])->all(),
                'parties' => Party::where('id', '>', $marks['parties'])->orderBy('id')->pluck('name', 'id')->all(),
                'messages' => WhatsappMessage::where('id', '>', $marks['messages'])->orderBy('id')->get()
                    ->map(fn ($m) => [$m->direction, $m->body, $m->kind])->all(),
            ];
        } finally {
            DB::rollBack();
            $this->restoreSequences($seqs);
            $this->travelBack();
        }
    }

    private function sequences(): array
    {
        $out = [];
        foreach (DB::select('select sequence_name from information_schema.sequences where sequence_schema = current_schema()') as $s) {
            $row = DB::selectOne('select last_value, is_called from "' . $s->sequence_name . '"');
            $out[$s->sequence_name] = [(int) $row->last_value, (bool) $row->is_called];
        }

        return $out;
    }

    private function restoreSequences(array $seqs): void
    {
        foreach ($seqs as $name => [$value, $called]) {
            DB::select('select setval(?, ?, ?)', [$name, $value, $called]);
        }
    }

    /** @return list<array> yazılmış tur dosyaları */
    private function traces(): array
    {
        $files = glob($this->dir . '/*/*/*.json') ?: [];
        sort($files);

        return array_map(fn ($f) => json_decode((string) file_get_contents($f), true) + ['_file' => $f], $files);
    }

    private function assertSameRun(array $off, array $on, string $what): void
    {
        foreach (array_keys($off) as $k) {
            $this->assertSame($off[$k], $on[$k], "{$what}: iz açıkken '{$k}' farklı");
        }
        $this->assertNotSame([], $off['replies'], 'senaryo bir şey üretmeli');
    }

    // ------------------------------------------------------------------ senaryolar

    private function party(string $name, array $ledger = []): Party
    {
        $p = Party::create(['name' => $name]);
        foreach ($ledger as $row) {
            PartyLedgerEntry::create(['party_id' => $p->id, 'entry_date' => '2026-10-10'] + $row);
        }

        return $p;
    }

    private function scenarioWorkQuestion(): array
    {
        $m = $this->party('Mehmet Usta');
        $this->ai = [
            $this->entry(['kind' => 'payment', 'amount' => 20000, 'party_id' => $m->id, 'description' => 'Ödeme', 'work_status_known' => false,
                'question' => 'Mehmet Usta işi bitirdi mi, yoksa işe başlamadan mı ödedin?', 'meaning' => "Mehmet Usta'ya 20.000 ₺ ödedin"]),
            $this->entry(['kind' => 'payment', 'amount' => 20000, 'party_id' => $m->id, 'description' => 'İşçilik', 'work_status' => 'done',
                'work_status_known' => true, 'is_new_entry' => false, 'meaning' => 'Mehmet Usta işi bitirdi, 20.000 ₺ parasını verdin']),
        ];

        return [$this->send('Mehmet ustaya 20 bin ödedim'), $this->send('işi bitirdi, parasını verdim'), $this->send('evet')];
    }

    private function scenarioAbdullah(): array
    {
        $a = $this->party('ABDULLAH UÇAR', [['type' => PartyLedgerEntry::TYPE_COLLECTION, 'amount' => 1333000]]);
        $this->ai = [$this->entry(['kind' => 'purchase', 'amount' => 26500, 'party_id' => $a->id, 'paid' => true, 'is_service' => true,
            'description' => 'Necati Çimen ruhsat işi', 'work_status' => 'done', 'work_status_known' => true,
            'meaning' => "Abdullah Uçar'a Necati Çimen ruhsat işi için 26.500 TL ödedin"])];

        return [$this->send('Abdullah uçara 26500 tl Necati Çimen ruhsat için ödendi'), $this->send('1'), $this->send('evet')];
    }

    private function scenarioCorrection(): array
    {
        $m = $this->party('Mehmet Kaya');
        $a = $this->party('Ahmet Demir');
        $this->ai = [
            $this->entry(['kind' => 'payment', 'amount' => 20000, 'party_id' => $m->id, 'description' => 'İş için', 'payment_purpose' => 'advance',
                'work_status' => 'not_yet', 'work_status_known' => true, 'meaning' => "Mehmet'e 20.000 ₺ verdin, işi haftaya yapacak"]),
            $this->entry(['kind' => 'payment', 'amount' => 25000, 'party_id' => $a->id, 'description' => 'İş için', 'work_status' => 'not_yet',
                'work_status_known' => true, 'is_new_entry' => false, 'meaning' => "Ahmet'e 25.000 ₺ verdin, işi haftaya yapacak"]),
        ];

        return [$this->send("Mehmet'e 20 bin verdim, işi haftaya yapacak"), $this->send('20 değil 25 bin, kişi de Mehmet değil Ahmet'), $this->send('evet')];
    }

    private function scenarioOpenAdvance(): array
    {
        $o = $this->party('Asansör Osman', [['type' => PartyLedgerEntry::TYPE_PAYMENT, 'amount' => 100000, 'description' => 'Avans — asansör']]);
        $this->ai = [$this->entry(['kind' => 'purchase', 'amount' => 100000, 'party_id' => $o->id, 'description' => 'Asansör işi',
            'work_status' => 'done', 'work_status_known' => true, 'meaning' => 'Osman asansörü taktı'])];

        return [$this->send('Osman asansörü taktı'), $this->send('evet')];
    }

    private function scenarioOpenDebt(): array
    {
        $k = $this->party('Kuşak Beton', [['type' => PartyLedgerEntry::TYPE_PURCHASE, 'amount' => 50000, 'description' => 'Beton']]);
        $this->ai = [$this->entry(['kind' => 'payment', 'amount' => 30000, 'party_id' => $k->id, 'description' => 'Ödeme',
            'work_status_known' => false, 'meaning' => "Kuşak Beton'a 30.000 ₺ ödedin"])];

        return [$this->send('Kuşağa 30 bin ödedim'), $this->send('evet')];
    }

    private function scenarioOldPath(): array
    {
        config(['modules.smart_assistant' => false, 'modules.expenses' => true]);
        $mock = Mockery::mock(ExpenseExtractor::class)->makePartial();
        $mock->shouldReceive('extract')->andReturn(['kind' => 'expense', 'amount' => 5000.0, 'description' => 'Yakıt', 'paid' => true,
            'party_id' => null, 'party_name' => null, 'project_id' => null, 'project_name' => null, 'category_id' => null, 'category_name' => 'Yakıt',
            'date' => '2026-10-23', 'confidence' => 'high', 'question' => null, 'items' => [], 'is_new_entry' => true]);
        $this->app->instance(ExpenseExtractor::class, $mock);

        return [$this->send('5 bin mazot aldım'), $this->send('evet')];
    }

    private function scenarioHttpErrors(): array
    {
        $this->ai = [
            fn () => Http::response(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'Internal']], 500),
            fn () => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529),
            fn ($r) => (Http::failedConnection())($r),
        ];

        return [$this->send('Selam bugün ne var'), $this->send('Ahmet ne durumda'), $this->send('Kime ne borcum var')];
    }

    private function scenarioDeferred(): array
    {
        $p = $this->party('Ertelenen Cari');
        $this->ai = [$this->entry(['kind' => 'purchase', 'amount' => 12000, 'party_id' => $p->id, 'paid' => false, 'description' => 'Malzeme',
            'work_status' => 'done', 'work_status_known' => true, 'meaning' => 'Ertelenen Cari\'den 12.000 ₺ malzeme aldın'])];

        // İstek 9,5 sn önce gelmiş gibi: senkron süre (10 sn) dolmak üzere → tur arka plana ("Bakıyorum").
        return [$this->send('Ertelenen cariden 12 bin malzeme aldım', ['REQUEST_TIME_FLOAT' => microtime(true) - 9.5])];
    }

    // ------------------------------------------------------------------ 1. açık/kapalı eşitliği

    public static function scenarios(): array
    {
        return [
            'iş durumu sorusu + cevap' => ['scenarioWorkQuestion'],
            'Abdullah: müşteriden alış → adına ödeme' => ['scenarioAbdullah'],
            '20 değil 25, Mehmet değil Ahmet' => ['scenarioCorrection'],
            'açık avanslı cariye işi bitirdi' => ['scenarioOpenAdvance'],
            'borçlu cariye ödedim' => ['scenarioOpenDebt'],
            'eski yol (çıkarıcı)' => ['scenarioOldPath'],
            'HTTP 500 / 529 / bağlantı hatası' => ['scenarioHttpErrors'],
            'ertelenen tur' => ['scenarioDeferred'],
        ];
    }

    /** @dataProvider scenarios */
    public function test_trace_on_and_off_produce_identical_behaviour(string $scenario): void
    {
        [$off, $on] = $this->both(fn () => $this->{$scenario}());

        $this->assertSameRun($off, $on, $scenario);
        $traces = $this->traces();
        $this->assertNotSame([], $traces, 'iz açıkken tur dosyası yazılmalı');
        foreach ($traces as $t) {
            $this->assertStringNotContainsString('test-key', (string) file_get_contents($t['_file']), 'API anahtarı izde olmamalı');
        }
    }

    public function test_trace_off_writes_nothing(): void
    {
        $this->observe(fn () => $this->scenarioAbdullah(), false);

        $this->assertSame([], glob($this->dir . '/*') ?: []);
    }

    // ------------------------------------------------------------------ 2. iz içeriği

    public function test_abdullah_trace_shows_where_the_record_changed(): void
    {
        $this->observe(fn () => $this->scenarioAbdullah(), true);
        $traces = $this->traces();
        $this->assertCount(3, $traces, 'her tur ayrı dosya (taslak güncellense de önceki tur kalır)');

        [$first, $choice] = [$traces[0]['segments']['1'], $traces[1]['segments']['1']];
        // Model çağrısı: istek gövdesi (talimat blob'da) + yanıt; başlık yok.
        $call = collect($first['calls'])->firstWhere('caller', 'ChatAssistant::call');
        $this->assertNotNull($call);
        $this->assertSame(200, $call['status']);
        $this->assertArrayHasKey('$blob', $call['request']['system'][0]['text']);
        $this->assertSame('purchase', $call['response']['content'][0]['input']['kind'], 'modelin ham kararı');
        // Ham girdi → normalizasyon zinciri, soru kaynağı.
        $this->assertSame('purchase', collect($first['steps'])->firstWhere('step', 'ai_input')['data']['kind']);
        $question = collect($first['notes'])->firstWhere('key', 'question')['value'];
        $this->assertSame('on_behalf_choice', $question['source']);
        // Karar okuması: OnBehalfChoice'un gördüğü hareket türleri (müşteri: yalnız tahsilat) — sorunun sebebi izde.
        $types = collect($first['reads'])->firstWhere('what', 'OnBehalfChoice::applies.types');
        $this->assertSame([PartyLedgerEntry::TYPE_COLLECTION], $types['result']);
        $this->assertNotNull($types['at_step'], 'okunduğu adım kayıtlı');
        // Bakiye okuması ve onu oluşturan kaynak satır (1.333.000 tahsilat, kimliğiyle).
        $balance = collect($choice['reads'])->firstWhere('what', 'WhatsappWebhookController::buildLedgerSummary');
        $this->assertEqualsWithDelta(-1333000, $balance['result']['balance'], 0.01);
        $this->assertSame(PartyLedgerEntry::TYPE_COLLECTION, $balance['refs'][0]['type']);
        $this->assertIsInt($balance['refs'][0]['id']);
        // "1" turu: numaralı seçim ve türün alıştan ödemeye dönmesi.
        $this->assertSame('on_behalf:1', collect($choice['notes'])->firstWhere('key', 'choice')['value']);
        $change = collect($choice['steps'])->firstWhere('step', 'saveDraft.input')['changed']['kind'] ?? null;
        $this->assertSame(['purchase', 'payment'], $change);
    }

    public function test_same_party_read_twice_is_recorded_twice_with_source_rows(): void
    {
        $this->observe(fn () => $this->scenarioOpenDebt(), true);
        $first = $this->traces()[0]['segments']['1'];

        $reads = collect($first['reads'])->filter(fn ($r) => ($r['meta']['party_id'] ?? null) !== null && isset($r['result']['balance']));
        $this->assertGreaterThanOrEqual(2, $reads->count(), 'aynı cari her okumada ayrı kayıt');
        $refs = $reads->first()['refs'];
        $this->assertSame('party_ledger_entries', $refs[0]['src']);
        $this->assertSame('alis', $refs[0]['type']);
        $this->assertEqualsWithDelta(-50000, $reads->first()['result']['balance'], 0.01);
        $this->assertNotNull($reads->first()['at_step'], 'hangi adımda okunduğu');
        $gap = collect($first['notes'])->where('key', 'gap')->pluck('value')->all();
        $this->assertContains('unknown_work_but_open_debt:no_question', $gap, 'bakiye kısayolu izde görünür');
    }

    public function test_deferred_turn_is_two_segments_of_the_same_turn(): void
    {
        $this->observe(fn () => $this->scenarioDeferred(), true);
        $traces = $this->traces();

        $this->assertCount(1, $traces);
        $this->assertSame(['1', '2'], array_map('strval', array_keys($traces[0]['segments'])));
        $this->assertSame(2, $traces[0]['expected_segments']);
        $this->assertSame('deferred', $traces[0]['segments']['2']['label']);
        $this->assertNotEmpty($traces[0]['segments']['2']['calls'], 'arka plandaki model çağrısı ikinci bölümde');
        $this->assertSame([], $traces[0]['segments']['1']['calls'], 'süre dolduğu için ilk bölümde model çağrısı yok');
        $this->assertNotContains('missing_segment', $traces[0]['replay']['reasons']);
    }

    public function test_http_errors_are_recorded_and_not_swallowed(): void
    {
        $run = $this->observe(fn () => $this->scenarioHttpErrors(), true);

        // 500 / 529: mevcut hata cevabı. Bağlantı hatası: mevcut akışta tur arka plana bırakılır (DeferTurn) — iz bunu değiştirmez.
        $this->assertStringContainsString('Şu an okuyamadım', $run['replies'][0], 'gerçek API hatası mevcut akışa ulaşır');
        $this->assertStringContainsString('Şu an okuyamadım', $run['replies'][1]);
        $this->assertStringContainsString('Bakıyorum', $run['replies'][2]);
        $calls = collect($this->traces())->flatMap(fn ($t) => $t['segments']['1']['calls']);
        $this->assertSame([500, 529], $calls->pluck('status')->filter()->values()->all());
        $this->assertStringContainsString('Overloaded', json_encode($calls[1]['response']), 'hata yanıtının gövdesi izde');
        $this->assertStringContainsString('ConnectException', (string) $calls->last()['error']);
    }

    public function test_incomplete_trace_is_marked_not_replayable(): void
    {
        $this->observe(function () {
            $out = $this->scenarioOpenDebt();
            // Sarılmamış bir karar okuması (izde değeri yok) → tam yeniden oynatılamaz.
            TurnTrace::begin('elle', self::PHONE);
            Party::where('name', 'Kuşak Beton')->first();
            TurnTrace::finish([], background: true);

            return $out;
        }, true);
        $manual = collect($this->traces())->firstWhere('turn_id', 'elle');

        $this->assertFalse($manual['replay']['complete']);
        $this->assertContains('uncaptured_reads:parties', $manual['replay']['reasons']);
    }

    /** Elle açılan tek tur: değer not olarak yazılır, iz dosyası döner. */
    private function noteTrace(string $turn, mixed $value): array
    {
        config(['services.whatsapp.trace' => true]);
        TurnTrace::begin($turn, self::PHONE);
        TurnTrace::note('veri', $value);
        TurnTrace::finish([], background: true);

        return collect($this->traces())->firstWhere('turn_id', $turn);
    }

    /** Pilot şartı: kısaltılmış iz "tam" sayılmaz. Karşı örnek: 201 elemandan yalnız 200'ü yazılır → complete=false. */
    public function test_item_limit_marks_trace_incomplete_201_vs_200(): void
    {
        $over = $this->noteTrace('eleman201', range(1, 201));
        $this->assertFalse($over['replay']['complete']);
        $this->assertContains('truncated_items', $over['replay']['reasons']);
        $note = $over['segments']['1']['notes'][0]['value'];
        $this->assertCount(201, $note, '200 eleman + kalan sayısı');
        $this->assertSame(1, $note['…'], 'yazılmayan 1 eleman');
        $this->assertSame(200, $note[199]);

        // Pozitif kontrol: tam 200 eleman kısaltılmaz.
        $exact = $this->noteTrace('eleman200', range(1, 200));
        $this->assertNotContains('truncated_items', $exact['replay']['reasons']);
        $this->assertCount(200, $exact['segments']['1']['notes'][0]['value']);
    }

    public function test_collection_text_and_depth_limits_mark_trace_incomplete(): void
    {
        $this->assertContains('truncated_items', $this->noteTrace('koleksiyon', collect(range(1, 201)))['replay']['reasons']);
        $this->assertNotContains('truncated_items', $this->noteTrace('koleksiyon200', collect(range(1, 200)))['replay']['reasons']);

        $text = $this->noteTrace('metin', str_repeat('ş', 20001));
        $this->assertContains('truncated_text', $text['replay']['reasons']);
        $this->assertFalse($text['replay']['complete']);
        $this->assertNotContains('truncated_text', $this->noteTrace('metin20000', str_repeat('ş', 20000))['replay']['reasons'], 'çok baytlı harf: sınır karakter sayısı');

        $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => 1]]]]]]]];
        $this->assertContains('truncated_depth', $this->noteTrace('derin', $deep)['replay']['reasons']);
        $this->assertNotContains('truncated_depth', $this->noteTrace('sig', ['a' => ['b' => ['c' => 1]]])['replay']['reasons']);
    }

    // ------------------------------------------------------------------ 3. hata yalıtımı

    public function test_diagnostic_failures_never_change_the_turn(): void
    {
        $off = $this->observe(fn () => $this->scenarioAbdullah(), false);
        foreach (['begin', 'step', 'read', 'note', 'request', 'response', 'finish', 'write'] as $where) {
            TurnTrace::$failHook = function (string $at) use ($where) {
                if ($at === $where) {
                    throw new \RuntimeException('teşhis hatası: ' . $where);
                }
            };
            $on = $this->observe(fn () => $this->scenarioAbdullah(), true);
            $this->assertSameRun($off, $on, 'hata: ' . $where);
        }
    }

    public function test_read_runs_once_and_rethrows_the_real_exception(): void
    {
        config(['services.whatsapp.trace' => true]);
        TurnTrace::begin('okuma', self::PHONE);
        $calls = 0;
        $this->assertSame(7, TurnTrace::read('sayac', function () use (&$calls) {
            $calls++;

            return 7;
        }));
        $this->assertSame(1, $calls);

        $original = new \DomainException('asıl hata');
        TurnTrace::$failHook = fn () => throw new \RuntimeException('teşhis');
        try {
            TurnTrace::read('patlayan', fn () => throw $original);
            $this->fail('istisna geçmeliydi');
        } catch (\DomainException $e) {
            $this->assertSame($original, $e, 'aynı istisna nesnesi, teşhis hatası araya girmez');
        }
        TurnTrace::$failHook = null;
        TurnTrace::finish([], background: true);
    }

    // ------------------------------------------------------------------ 4. gövde konumu

    public function test_middleware_restores_body_position_and_never_consumes_unseekable_bodies(): void
    {
        config(['services.whatsapp.trace' => true]);
        TurnTrace::begin('govde', self::PHONE);
        $seen = null;
        $handler = function ($request) use (&$seen) {
            $seen = [$request->getBody()->tell(), (string) $request->getBody()->getContents()];
            $body = Utils::streamFor('{"content":[]}');
            $body->seek(3);

            return \GuzzleHttp\Promise\Create::promiseFor(new PsrResponse(200, [], $body));
        };
        $body = Utils::streamFor('{"model":"x","messages":[]}');
        $body->seek(5);
        $response = (TurnTrace::middleware())($handler)(new PsrRequest('POST', 'https://api.anthropic.com/v1/messages', [], $body), [])->wait();

        $this->assertSame(5, $seen[0], 'istek gövdesi başlangıç konumunda');
        $this->assertSame('"x","messages":[]}', substr($seen[1], 4));
        $this->assertSame(3, $response->getBody()->tell(), 'yanıt gövdesi başlangıç konumunda');

        $noSeek = new NoSeekStream(Utils::streamFor('{"model":"y"}'));
        (TurnTrace::middleware())(function ($request) use (&$seen) {
            $seen = (string) $request->getBody()->getContents();

            return \GuzzleHttp\Promise\Create::promiseFor(new PsrResponse(200, [], '{}'));
        })(new PsrRequest('POST', 'https://api.anthropic.com/v1/messages', [], $noSeek), [])->wait();
        $this->assertSame('{"model":"y"}', $seen, 'geri sarılamayan gövde tüketilmedi');

        TurnTrace::finish([], background: true);
        $trace = $this->traces()[0];
        $this->assertContains('body_not_seekable', $trace['replay']['reasons']);
        $this->assertFalse($trace['replay']['complete']);
    }

    // ------------------------------------------------------------------ 5. atomik ekleme

    public function test_segments_written_concurrently_and_out_of_order_all_survive(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl yok');
        }
        config(['services.whatsapp.trace' => true]);
        $ctx = new TraceContext('local', 'eszamanli', '905556660001', '2026-10-23');
        $seg = fn (int $n) => ['seq' => $n, 'label' => 'b' . $n, 'replay_reasons' => [], 'steps' => [['n' => 1, 'step' => str_repeat('x', 20000)]],
            '_blobs' => ['ab' . $n => str_repeat((string) $n, 5000)]];
        @mkdir($this->dir . '/local/2026-10-23', 0775, true);
        $hold = TurnTrace::lock($this->dir . '/local/2026-10-23/.lock', LOCK_EX); // gün kilidi elde: çocuklar beklemeli
        $children = [];
        foreach ([3, 2] as $n) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                TurnTrace::write($ctx, $seg($n));
                posix_kill(getmypid(), SIGKILL); // kapanış işleri (DB bağlantısı) ebeveyni bozmasın
            }
            $children[] = $pid;
        }
        usleep(300_000);
        $this->assertFileDoesNotExist($this->dir . '/local/2026-10-23/eszamanli.json', 'kilit varken yazılmamalı');
        flock($hold, LOCK_UN);
        fclose($hold);
        TurnTrace::write($ctx, $seg(1));
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $doc = json_decode((string) file_get_contents($this->dir . '/local/2026-10-23/eszamanli.json'), true);
        $this->assertSame(['1', '2', '3'], array_map('strval', array_keys($doc['segments'])), 'üç bölüm de var, sırayla');
        $this->assertSame([], glob($this->dir . '/local/2026-10-23/*.tmp') ?: [], 'yarım geçici dosya kalmadı');
    }

    // ------------------------------------------------------------------ 6. temizlik

    public function test_prune_removes_old_turns_and_their_unused_blobs_only(): void
    {
        $base = $this->dir . '/local';
        @mkdir($base . '/blobs', 0775, true);
        $old = now('Europe/Istanbul')->subDays(40)->toDateString();
        $new = now('Europe/Istanbul')->toDateString();
        $blob = fn (string $h) => $base . '/blobs/' . str_repeat($h, 64);
        $turn = function (string $day, string $id, array $hashes) use ($base) {
            @mkdir("{$base}/{$day}", 0775, true);
            file_put_contents("{$base}/{$day}/{$id}.json", json_encode(['segments' => ['1' => ['calls' => array_map(fn ($h) => ['request' => ['system' => [['text' => ['$blob' => str_repeat($h, 64)]]]]], $hashes)]]]));
        };
        foreach (['a', 'b', 'c', 'd'] as $h) {
            file_put_contents($blob($h), $h);
            touch($blob($h), time() - 3 * 86400);
        }
        file_put_contents($blob('e'), 'e'); // sahipsiz ama yeni (yazımı süren tura pay)
        $turn($old, 'eski', ['a', 'b']);   // a: yalnız eski tur · b: ortak
        $turn($new, 'yeni', ['b', 'c']);   // c: yalnız yeni tur · d: hiç kullanılmıyor

        Artisan::call('whatsapp:trace-prune', ['--days' => 30, '--wait' => 0]);

        $this->assertDirectoryDoesNotExist("{$base}/{$old}");
        $this->assertFileExists("{$base}/{$new}/yeni.json");
        $this->assertFileDoesNotExist($blob('a'), 'yalnız silinen tura ait blob silinir');
        $this->assertFileExists($blob('b'), 'kalan turun kullandığı ortak blob korunur');
        $this->assertFileExists($blob('c'));
        $this->assertFileDoesNotExist($blob('d'), 'hiçbir turun kullanmadığı eski blob silinir');
        $this->assertFileExists($blob('e'), 'yeni sahipsiz blob silinmez');
    }

    public function test_prune_waits_for_a_turn_that_reuses_an_old_shared_blob_before_its_file_is_written(): void
    {
        $base = $this->dir . '/local';
        $old = now('Europe/Istanbul')->subDays(40)->toDateString();
        @mkdir("{$base}/{$old}", 0775, true);
        @mkdir("{$base}/blobs", 0775, true);
        $content = str_repeat('ortak talimat ', 300);
        $hash = hash('sha256', $content);
        file_put_contents("{$base}/blobs/{$hash}", $content);
        touch("{$base}/blobs/{$hash}", time() - 40 * 86400);
        file_put_contents("{$base}/{$old}/eski.json", json_encode(['segments' => ['1' => ['x' => ['$blob' => $hash]]]]));

        // Yeni tur aynı blob'u kullanıyor; bloblar hazır, tur dosyası henüz yok → tam o anda temizlik çalışır.
        $during = null;
        TurnTrace::$beforeTurnFile = function () use (&$during, $base, $hash, $old) {
            $code = Artisan::call('whatsapp:trace-prune', ['--days' => 30, '--wait' => 0]);
            $during = [$code, Artisan::output(), is_file("{$base}/blobs/{$hash}"), is_dir("{$base}/{$old}")];
        };
        TurnTrace::write(new TraceContext('local', 'yeni', '905556660001', now('Europe/Istanbul')->toDateString()),
            ['seq' => 1, 'label' => 'request', 'replay_reasons' => [], 'calls' => [['request' => ['system' => [['text' => ['$blob' => $hash]]]]]],
                '_blobs' => [$hash => $content]]);
        TurnTrace::$beforeTurnFile = null;

        $this->assertStringContainsString('temizlik atlandı', $during[1], 'yazım sürerken temizlik kilidi alamaz');
        $this->assertTrue($during[2], 'blob silinmedi');
        $this->assertTrue($during[3], 'eski tur da o sırada silinmedi');
        $this->assertGreaterThan(time() - 60, filemtime("{$base}/blobs/{$hash}"), 'yeniden kullanılan blob tazelendi');

        Artisan::call('whatsapp:trace-prune', ['--days' => 30, '--wait' => 0]); // yazım bitti
        $this->assertDirectoryDoesNotExist("{$base}/{$old}");
        $this->assertFileExists("{$base}/blobs/{$hash}", 'yeni tur kullandığı için blob kalır');
    }

    // ------------------------------------------------------------------ 7. süre etkisi

    public function test_sync_overhead_is_small_and_reported(): void
    {
        TurnTrace::$flushMs = [];
        $overheads = [];
        foreach (['scenarioWorkQuestion', 'scenarioAbdullah', 'scenarioCorrection', 'scenarioOpenAdvance', 'scenarioOpenDebt'] as $s) {
            $this->rmrf($this->dir);
            $this->observe(fn () => $this->{$s}(), true);
            foreach ($this->traces() as $t) {
                foreach ($t['segments'] as $seg) {
                    $overheads[] = (float) $seg['overhead_sync_ms'];
                }
            }
        }
        sort($overheads);
        $flush = TurnTrace::$flushMs;
        sort($flush);
        $median = fn (array $a) => $a[intdiv(count($a), 2)] ?? 0;
        $report = ['turns' => count($overheads), 'sync_ms_median' => $median($overheads), 'sync_ms_max' => end($overheads),
            'flush_ms_median' => $median($flush), 'flush_ms_max' => end($flush)];
        fwrite(STDERR, "\nİZ SÜRE ETKİSİ: " . json_encode($report) . "\n");

        $this->assertLessThan(250, $report['sync_ms_max'], 'senkron ek süre küçük olmalı (Twilio 15 sn, senkron sınır 10 sn)');
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
