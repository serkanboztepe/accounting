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
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SMeval'], 201), 'messaging.twilio.com/*' => Http::response([], 200)]);
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
            $this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $message, 'NumMedia' => 0, 'MessageSid' => 'SM' . uniqid()])->assertOk();
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
        foreach ($state['parties'] ?? [] as $name => $rows) {
            $party = Party::create(['name' => $name]);
            $ids[$name] = $party->id;
            foreach ($rows as $row) {
                PartyLedgerEntry::create(['party_id' => $party->id, 'notes' => 'eval'] + $row);
            }
        }

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
        if (($expect['open_proposals'] ?? 0) !== 'any') {
            $checks['açık_kart_kalmadı'] = $state['open_proposals'] === (int) ($expect['open_proposals'] ?? 0) ? true : $state['open_proposals'] . ' açık kart';
        }

        return $checks;
    }
}
