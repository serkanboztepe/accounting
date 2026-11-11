<?php

namespace Tests\Feature\Eval;

use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Models\UsageLog;
use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;
use App\Services\Agent\AgentLog;
use App\Support\ModuleProfiles;
use App\Support\PartyStatement;
use App\Support\Phone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * AJAN ASİSTAN ölçümü (plan v5 §7, Kapı 1) — ÜCRETLİ, gerçek model. Normal `php artisan test` içinde ÇALIŞMAZ.
 *
 *   EVAL_AGENT=storage/app/eval-private/agent-cases.php php artisan test --group=eval-agent
 *   EVAL_AGENT_ONLY=kaya-eski-borc,kaya-kaldi   (yalnız bu vakalar)   EVAL_AGENT_RUNS=3 (her vaka 3 kez; pass^3)
 *
 * Her vaka: durum kurulur, saat sabitlenir, ajan canlı açılır. Müşteriyi ucuz bir model OYNAR (sanal müşteri): elinde gizli gerçek
 * (ne oldu, ne istiyor) ve gerçek müşterinin yazış biçimi vardır; ajanın sorularına ve kartlarına bir esnaf gibi cevap verir.
 * Böylece ajan eski asistandan farklı sorsa da konuşma sürer. Puan KONUŞMAYA değil SON DURUMA bakar (kişi bakiyeleri, satır
 * sayıları) + konuşma kuralları (tur sayısı, soru tekrarı, kayıtsız "kaydettim"). Vaka dosyası git dışında (gerçek adlar).
 * Sonuç: storage/app/eval/agent-<zaman>.json (konuşma, ajanın adımları, maliyet, süre). Her vaka işlem içinde, geri alınır.
 */
#[Group('eval')]
#[Group('eval-agent')]
class AgentEvalTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905559990077';

    private const CUSTOMER_MODEL = 'claude-sonnet-5-5';

    private static array $results = [];

    private string $logDir = '';

    /** @var array{sales:int, reminders:int} vaka başındaki son kayıt id'leri */
    private array $baseline = ['sales' => 0, 'reminders' => 0];

    private static function file(): string
    {
        $file = getenv('EVAL_AGENT') ?: '';

        return $file === '' ? '' : (str_starts_with($file, '/') ? $file : dirname(__DIR__, 3) . '/' . $file);
    }

    public static function cases(): array
    {
        $path = self::file();
        if ($path === '' || ! is_file($path)) {
            return ['(vaka dosyası yok — EVAL_AGENT=…)' => [[], 1]];
        }
        $only = array_filter(explode(',', (string) getenv('EVAL_AGENT_ONLY')));
        $runs = max(1, (int) (getenv('EVAL_AGENT_RUNS') ?: 1));
        $out = [];
        foreach (require $path as $case) {
            if ($only !== [] && ! in_array($case['id'], $only, true)) {
                continue;
            }
            for ($run = 1; $run <= $runs; $run++) {
                $out[$case['id'] . ($runs > 1 ? ' #' . $run : '')] = [$case, $run];
            }
        }

        return $out ?: ['(seçilen vaka yok)' => [[], 1]];
    }

    protected function tearDown(): void
    {
        if ($this->logDir !== '') {
            File::deleteDirectory($this->logDir);
        }
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$results !== []) {
            $dir = dirname(__DIR__, 3) . '/storage/app/eval';
            @mkdir($dir, 0775, true);
            $file = $dir . '/agent-' . date('Ymd-His') . '.json';
            file_put_contents($file, json_encode(self::$results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
            $pass = count(array_filter(self::$results, fn ($r) => $r['passed']));
            fwrite(STDERR, sprintf("\nAJAN ÖLÇÜMÜ: %d/%d geçti · %.2f $ · %s\n", $pass, count(self::$results),
                array_sum(array_column(self::$results, 'cost_usd')), $file));
        }
        parent::tearDownAfterClass();
    }

    #[DataProvider('cases')]
    public function test_agent_case(array $case, int $run): void
    {
        if ($case === []) {
            $this->markTestSkipped('Vaka dosyası yok (EVAL_AGENT).');
        }
        if ((string) config('services.anthropic.api_key') === '') {
            $this->markTestSkipped('ANTHROPIC_API_KEY yok.');
        }

        // ── Ortam: ajan canlı, Twilio sahte, model gerçek ──
        $this->logDir = sys_get_temp_dir() . '/wa-agent-eval-' . uniqid();
        config(['modules' => ModuleProfiles::resolve($case['profile'] ?? 'muteahhit', ['smart_assistant' => true, 'agent_live' => true] + ($case['modules'] ?? [])),
            'app.profile' => $case['profile'] ?? 'muteahhit', 'app.sector' => $case['sector'] ?? 'construction', 'app.business_note' => $case['business_note'] ?? null,
            'app.project_label' => $case['project_label'] ?? 'Proje',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 999 00 77'],
            'services.twilio.sid' => 'ACeval', 'services.twilio.token' => 'tok', 'services.whatsapp.from' => '+10000000000',
            'services.whatsapp.agent' => 'on', 'agent.log_path' => $this->logDir, 'agent.daily_cost_usd' => 1000]);
        $files = array_map(fn ($f) => __DIR__ . '/fixtures/' . $f, (array) ($case['first_media'] ?? []));
        $media = $files[0] ?? null;
        $mime = $media && str_ends_with($media, '.pdf') ? 'application/pdf' : 'image/jpeg';
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SMeval'], 201), 'messaging.twilio.com/*' => Http::response([], 200),
            'media.eval/*' => fn ($req) => Http::response((string) file_get_contents($files[(int) basename($req->url())]), 200, ['Content-Type' => $mime])]);
        Cache::flush();
        $this->travelTo(\Illuminate\Support\Carbon::parse($case['now'], 'Europe/Istanbul')->setTimezone(config('app.timezone')));
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        WhatsappMessage::where('phone', Phone::normalize(self::PHONE))->delete();
        // İlk mesaj karşılaması araya girmesin.
        WhatsappMessage::create(['phone' => Phone::normalize(self::PHONE), 'direction' => 'out', 'body' => 'Merhaba 👋 Bugün aklında bir şey var mı?', 'kind' => 'nudge']);

        $ids = $this->setUpState($case['state'] ?? []);
        $costBefore = (float) UsageLog::sum('cost_usd');
        $started = microtime(true);

        // ── Konuşma ──
        $transcript = [];
        $maxTurns = (int) ($case['max_turns'] ?? 10);
        $message = $case['first'];
        for ($turn = 1; $turn <= $maxTurns && $message !== null; $turn++) {
            $lastId = (int) WhatsappMessage::max('id');
            // Birlikte gönderilen sayfalar: gerçekte ilk fotoğraf okunurken sıraya girerler.
            if ($turn === 1 && count($files) > 1) {
                Cache::put('wa-photo-queue:' . Phone::normalize(self::PHONE), array_map(fn ($i) => ['_queued_at' => microtime(true), 'From' => self::PHONE, 'Body' => '', 'NumMedia' => 1,
                    'MessageSid' => 'SMpage' . $i . uniqid(), 'MediaUrl0' => 'https://media.eval/' . $i, 'MediaContentType0' => $mime], range(1, count($files) - 1)), now()->addHour());
            }
            $this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $message, 'MessageSid' => 'SM' . uniqid()] + ($turn === 1 && $media
                ? ['NumMedia' => 1, 'MediaUrl0' => 'https://media.eval/0', 'MediaContentType0' => $mime] : ['NumMedia' => 0]))->assertOk();
            (fn () => $this->terminatingCallbacks = [])->call($this->app);
            $reply = WhatsappMessage::where('phone', Phone::normalize(self::PHONE))->where('direction', 'out')->where('id', '>', $lastId)
                ->orderBy('id')->pluck('body')->implode("\n---\n");
            $transcript[] = ['müşteri' => $message, 'asistan' => $reply];
            $this->travel(40)->seconds();
            $message = $this->customer($case, $transcript);
        }

        // ── Son durum + puan ──
        $state = $this->finalState($ids);
        $log = AgentLog::read(now('Europe/Istanbul')->toDateString());
        $checks = $this->score($case['expect'] ?? [], $state, $transcript, $log);
        $failed = array_keys(array_filter($checks, fn ($ok) => $ok !== true));
        self::$results[] = [
            'id' => $case['id'], 'run' => $run, 'passed' => $failed === [], 'failed_checks' => array_intersect_key($checks, array_flip($failed)),
            'turns' => count($transcript), 'cost_usd' => round((float) UsageLog::sum('cost_usd') - $costBefore, 4),
            'seconds' => round(microtime(true) - $started, 1),
            'agent_seconds' => array_column($log, 'seconds'),
            'transcript' => $transcript, 'final_state' => $state,
            'agent_steps' => array_map(fn ($l) => ['message' => $l['message'] ?? null, 'error' => $l['error'] ?? null, 'stop' => $l['stop'] ?? null,
                'steps' => $l['steps'] ?? []], $log),
        ];

        $this->assertSame([], $failed, $case['id'] . ' — tutmayan: ' . json_encode(array_intersect_key($checks, array_flip($failed)), JSON_UNESCAPED_UNICODE)
            . "\nKonuşma:\n" . collect($transcript)->map(fn ($t) => '← ' . $t['müşteri'] . "\n→ " . $t['asistan'])->implode("\n"));
    }

    /** @return array<string, int> ad → kişi id */
    private function setUpState(array $state): array
    {
        $ids = [];
        foreach ($state['projects'] ?? [] as $name) {
            Project::firstOrCreate(['name' => $name], ['status' => 'active']);
        }
        foreach ($state['products'] ?? [] as $name => $price) {
            \App\Models\Product::create(['name' => $name, 'type' => \App\Models\Product::TYPE_PRODUCT, 'default_price' => $price, 'is_active' => true]);
        }
        foreach ($state['employees'] ?? [] as $name => $e) {
            $ids[$name] = Party::create(['name' => $name, 'is_employee' => true, 'salary_amount' => $e['salary'], 'salary_day' => $e['day'], 'salary_auto' => true])->id;
            foreach ($e['rows'] ?? [] as $row) {
                PartyLedgerEntry::create(['party_id' => $ids[$name], 'notes' => 'eval'] + $row);
            }
        }
        foreach ($state['parties'] ?? [] as $name => $rows) {
            $party = Party::create(['name' => $name]);
            $ids[$name] = $party->id;
            foreach ($rows as $row) {
                PartyLedgerEntry::create(['party_id' => $party->id, 'notes' => 'eval'] + $row);
            }
        }

        foreach ($state['agreements'] ?? [] as $a) {
            $ag = \App\Models\Agreement::create(['party_id' => $ids[$a['party']] ?? Party::firstOrCreate(['name' => $a['party']])->id, 'direction' => $a['direction'] ?? 'purchase',
                'title' => $a['title'], 'agreement_date' => $a['date'], 'status' => 'open']);
            foreach ($a['items'] as $i => $item) {
                $product = \App\Models\Product::firstOrCreate(['name' => $item['name']], ['type' => \App\Models\Product::TYPE_SERVICE, 'is_active' => true]);
                $ag->items()->create(['product_id' => $product->id, 'quantity' => $item['quantity'], 'unit_price' => $item['unit_price'],
                    'amount' => $item['quantity'] * $item['unit_price'], 'is_lump' => false, 'sort' => $i]);
            }
        }
        foreach ($state['checks'] ?? [] as $c) {
            \App\Models\Check::create(['party_id' => Party::where('name', $c['party'])->value('id'), 'amount' => $c['amount'], 'due_date' => $c['due'],
                'issue_date' => $c['issued'] ?? $c['due'], 'status' => $c['status'] ?? 'issued', 'notes' => 'eval']);
        }
        $this->baseline = ['sales' => (int) \App\Models\Sale::max('id'), 'reminders' => (int) \App\Models\Reminder::max('id')];
        return $ids;
    }

    /** Sanal müşteri: sıradaki mesaj ya da null (konuşma bitti). */
    private function customer(array $case, array $transcript): ?string
    {
        $system = "Bir WhatsApp konuşmasında MÜŞTERİYİ oynuyorsun: Türkiye'de küçük bir işletmenin sahibi, telefonda, işinin başında. Karşındaki bir hesap asistanı.\n\n"
            . "GERÇEK (yalnız sen biliyorsun; asistan sorarsa buna göre cevap ver, sormadıkça kendiliğinden hepsini dökme):\n" . $case['truth'] . "\n\n"
            . "İSTEDİĞİN: " . $case['goal'] . "\n\n"
            . "NASIL YAZARSIN: çok kısa (çoğu mesaj 1–6 kelime), noktalama yok, ara sıra yazım hatası, rakamı çıplak yazarsın (742000, 120 bin). "
            . "Muhasebe terimi bilmezsin. Asistanın sorusu numaralı seçenekliyse bazen numarayla, bazen kendi kelimenle cevap verirsin. "
            . "Asistan aynı şeyi tekrar sorarsa ya da anlamazsa sabırsızlanır, aynı şeyi başka kelimelerle söylersin. Örnek yazışların: " . ($case['style'] ?? '"evet", "ordan düş", "742000 borç var idi"') . "\n\n"
            . "KURALLAR: Asistan bir kayıt kartı gösterip onay isterse karttaki rakamları GERÇEK ile karşılaştır: doğruysa onay ver (kart tutar yazmanı ya da \"sil\" yazmanı "
            . "istiyorsa onu yaz); yanlışsa kısa itiraz et (neyin yanlış olduğunu kendi kelimenle). Asistan \"kaydedildi\" dedi ve isteğin tamamen karşılandıysa ya da "
            . "sorduğun soruya cevap aldıysan yalnızca <BİTTİ> yaz. Asistan senin istemediğin bir şey kaydettiyse düzeltmesini iste. "
            . "Yalnız müşterinin bir sonraki mesajını yaz — açıklama, tırnak, ön ek yok.";
        $messages = [];
        foreach ($transcript as $t) {
            $messages[] = ['role' => 'assistant', 'content' => $t['müşteri']];
            $messages[] = ['role' => 'user', 'content' => $t['asistan'] !== '' ? $t['asistan'] : '(asistan cevap vermedi)'];
        }
        array_unshift($messages, ['role' => 'user', 'content' => '(konuşma başlıyor — ilk mesajını yaz)']);

        $response = Http::withHeaders(['x-api-key' => (string) config('services.anthropic.api_key'), 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'])
            ->timeout(60)->post('https://api.anthropic.com/v1/messages', ['model' => self::CUSTOMER_MODEL, 'max_tokens' => 2000,
                'output_config' => ['effort' => 'low'], 'system' => $system, 'messages' => $messages]);
        if ($response->failed()) {
            $this->fail('Sanal müşteri cevap vermedi: ' . mb_substr($response->body(), 0, 300));
        }
        UsageLog::recordAi(self::CUSTOMER_MODEL, (array) $response->json('usage'));
        $text = trim(implode('', array_map(fn ($b) => ($b['type'] ?? null) === 'text' ? $b['text'] : '', (array) $response->json('content'))));

        return $text === '' || str_contains($text, '<BİTTİ>') ? null : $text;
    }

    private function finalState(array $ids): array
    {
        $parties = [];
        foreach (Party::where('created_at', '>=', now()->subDay())->orWhereIn('id', array_values($ids))->get() as $party) {
            $rows = PartyLedgerEntry::where('party_id', $party->id)->orderBy('id')->get();
            $parties[$party->name] = [
                'balance' => round((float) PartyStatement::build($party)['balance'], 2),
                'rows' => $rows->map(fn ($r) => [$r->type, (float) $r->amount, (string) $r->description, $r->project_id])->all(),
            ];
        }

        return ['parties' => $parties,
            'sales' => \App\Models\Sale::where('id', '>', $this->baseline['sales'])->get()->map(fn ($x) => [(float) $x->total_amount, $x->party_id !== null])->all(),
            'reminders' => \App\Models\Reminder::where('id', '>', $this->baseline['reminders'])->get()
                ->map(fn ($x) => [(string) $x->text, $x->next_fire_at?->timezone('Europe/Istanbul')->format('Y-m-d H:i'), $x->amount !== null ? (float) $x->amount : null])->all(),
            'checks' => \App\Models\Check::where('updated_at', '>=', now()->subDay())->get()
                ->map(fn ($c) => [$c->status, (float) $c->amount, $c->due_date?->toDateString()])->all(),
            'check_numbers' => \App\Models\Check::where('created_at', '>=', now()->subDay())->pluck('check_number')->filter()->values()->all(),
            'agreements' => \App\Models\Agreement::where('created_at', '>=', now()->subDay())->with('items')->get()
                ->map(fn ($a) => [$a->direction, round((float) $a->agreedTotal(), 2)])->all(),
            'pdf_sent' => collect(Http::recorded(fn ($r) => str_contains($r->url(), 'api.twilio.com') && ! empty($r['MediaUrl'])))->count(),
            'employees' => Party::where('is_employee', true)->where('updated_at', '>=', now()->subDay())->get()
                ->mapWithKeys(fn ($p) => [$p->name => [(float) $p->salary_amount, (int) $p->salary_day]])->all(),
            'expenses' => Expense::where('notes', 'like', 'WhatsApp%')->where('created_at', '>=', now()->subDay())->get()
                ->map(fn ($e) => [(float) $e->amount, (string) $e->description])->all(),
            'open_proposals' => WhatsappPendingExpense::agentOpen(self::PHONE)->count()];
    }

    /**
     * Beklentiler (hepsi isteğe bağlı):
     *   balances: [ad parçası => bakiye]            (− biz borçluyuz)
     *   rows: [ad parçası => [[tür, tutar], …]]     (o kişinin TÜM satırları, sırasız)
     *   no_party_like: [ad parçası, …]              (böyle bir kişi açılmamalı)
     *   expenses: [tutar, …]                        (WhatsApp'tan yazılan giderler, sırasız)
     *   sales_total: n (yeni satış belgeleri toplamı) · reminder_at: 'Y-m-d H:i' · employees: [ad parçası => [maaş, gün]]
     *   checks: [[durum, tutar, vade]…] (bugün değişen çekler) · agreement_total: n · pdf: true (ekstre PDF'i gitti)
     *   handover: true                              (ajan bu işi eski asistana devretmeli; yoksa devretmemeli)
     *   max_turns: n · reply_must_contain: [metin…] (herhangi bir asistan cevabında) · reply_must_not_contain: […]
     *
     * @return array<string, true|string>
     */
    private function score(array $expect, array $state, array $transcript, array $log): array
    {
        $checks = [];
        $find = function (string $like) use ($state): ?array {
            foreach ($state['parties'] as $name => $p) {
                if (str_contains(ReplayScore::fold($name), ReplayScore::fold($like))) {
                    return $p;
                }
            }

            return null;
        };
        foreach ($expect['balances'] ?? [] as $like => $balance) {
            $p = $find($like);
            $checks["bakiye:$like"] = $p !== null && abs($p['balance'] - $balance) < 0.01 ? true : 'beklenen ' . $balance . ', olan ' . json_encode($p['balance'] ?? 'kişi yok');
        }
        foreach ($expect['rows'] ?? [] as $like => $rows) {
            $p = $find($like);
            $have = collect($p['rows'] ?? [])->map(fn ($r) => $r[0] . ':' . round($r[1], 2))->sort()->values()->all();
            $want = collect($rows)->map(fn ($r) => $r[0] . ':' . round((float) $r[1], 2))->sort()->values()->all();
            $checks["satırlar:$like"] = $have === $want ? true : 'beklenen ' . json_encode($want) . ', olan ' . json_encode($have);
        }
        foreach ($expect['no_party_like'] ?? [] as $like) {
            $checks["kişi_yok:$like"] = $find($like) === null ? true : 'açılmış';
        }
        if (isset($expect['expenses'])) {
            $have = collect($state['expenses'])->map(fn ($e) => round($e[0], 2))->sort()->values()->all();
            $want = collect($expect['expenses'])->map(fn ($e) => round((float) $e, 2))->sort()->values()->all();
            $checks['giderler'] = $have === $want ? true : 'beklenen ' . json_encode($want) . ', olan ' . json_encode($have);
        }
        if (isset($expect['sales_total'])) {
            $sum = round(array_sum(array_column($state['sales'], 0)), 2);
            $checks['satış_belgesi'] = abs($sum - $expect['sales_total']) < 0.01 ? true : 'beklenen ' . $expect['sales_total'] . ', olan ' . $sum;
        }
        if (isset($expect['reminder_at'])) {
            $checks['hatırlatma'] = in_array($expect['reminder_at'], array_column($state['reminders'], 1), true) ? true : 'olan ' . json_encode($state['reminders'], JSON_UNESCAPED_UNICODE);
        }
        foreach ($expect['employees'] ?? [] as $like => $want) {
            $hit = collect($state['employees'])->first(fn ($v, $name) => str_contains(ReplayScore::fold($name), ReplayScore::fold($like)));
            $checks["personel:$like"] = $hit === $want ? true : 'olan ' . json_encode($hit);
        }
        if (isset($expect['checks'])) {
            $have = collect($state['checks'])->map(fn ($c) => implode('|', $c))->sort()->values()->all();
            $want = collect($expect['checks'])->map(fn ($c) => implode('|', [$c[0], (float) $c[1], $c[2]]))->sort()->values()->all();
            $checks['çekler'] = $have === $want ? true : 'beklenen ' . json_encode($want) . ', olan ' . json_encode($have);
        }
        if (isset($expect['check_numbers'])) {
            $missing = array_values(array_diff($expect['check_numbers'], $state['check_numbers']));
            $checks['çek_numaraları'] = $missing === [] && count($state['check_numbers']) === count($expect['check_numbers']) ? true
                : 'eksik ' . json_encode($missing) . ', olan ' . json_encode($state['check_numbers']);
        }
        if (isset($expect['agreement_total'])) {
            $checks['anlaşma'] = in_array((float) $expect['agreement_total'], array_column($state['agreements'], 1), false) ? true : 'olan ' . json_encode($state['agreements']);
        }
        if (! empty($expect['pdf'])) {
            $checks['ekstre_pdf'] = $state['pdf_sent'] > 0 ? true : 'PDF gönderilmedi';
        }
        if (isset($expect['max_turns'])) {
            $checks['tur_sayısı'] = count($transcript) <= $expect['max_turns'] ? true : count($transcript) . ' tur (en çok ' . $expect['max_turns'] . ')';
        }
        $all = implode("\n", array_column($transcript, 'asistan'));
        foreach ($expect['reply_must_contain'] ?? [] as $text) {
            $checks["cevapta:$text"] = mb_stripos($all, $text) !== false ? true : 'yok';
        }
        foreach ($expect['reply_must_not_contain'] ?? [] as $text) {
            $checks["cevapta_olmamalı:$text"] = mb_stripos($all, $text) === false ? true : 'var';
        }
        // Her vakada: cevapsız tur yok, hata yok, aynı asistan mesajı iki kez gitmedi (soru döngüsü), açık kart kalmadı.
        $replies = array_filter(array_column($transcript, 'asistan'), 'strlen');
        $checks['cevapsız_tur_yok'] = count($replies) === count($transcript) ? true : 'cevapsız tur var';
        $checks['özür_yok'] = ! str_contains($all, 'toparlayamadım') ? true : 'ajan hata verdi';
        $checks['tekrar_yok'] = count($replies) === count(array_unique($replies)) ? true : 'aynı mesaj iki kez gitti';
        $checks['hata_yok'] = array_filter(array_column($log, 'error')) === [] ? true : json_encode(array_filter(array_column($log, 'error')), JSON_UNESCAPED_UNICODE);
        // Devir: ajan kendi işini eski asistana atmamalı; onun işi olanı (maaş, hatırlatma, stok…) devretmeli.
        $handed = array_values(array_filter(array_map(fn ($l) => $l['outcome']['handover'] ?? null, $log)));
        $checks['devir'] = ! empty($expect['handover'])
            ? ($handed !== [] ? true : 'devretmedi (eski asistanın işiydi)')
            : ($handed === [] ? true : 'gereksiz devretti: ' . implode(', ', $handed));
        if (($expect['open_proposals'] ?? 0) !== 'any') {
            $checks['açık_kart_kalmadı'] = $state['open_proposals'] === (int) ($expect['open_proposals'] ?? 0) ? true : $state['open_proposals'] . ' açık kart';
        }

        return $checks;
    }
}
