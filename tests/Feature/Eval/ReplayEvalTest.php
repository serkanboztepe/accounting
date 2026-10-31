<?php

namespace Tests\Feature\Eval;

use App\Models\AssistantMemory;
use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use App\Models\Project;
use App\Models\Sale;
use App\Services\Whatsapp\ExpenseContext;
use App\Support\ModuleProfiles;
use App\Support\PartyStatement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Gerçek konuşmaların yeniden oynatılması (ölçüm; ücretli, gerçek AI). Vaka dosyası git DIŞINDA:
 *
 *   EVAL_REPLAY=storage/app/eval-private/replay.php EVAL_REPLAY_OUT=… WHATSAPP_TRACE=true WHATSAPP_TRACE_PATH=… \
 *     ANTHROPIC_MODEL=… ANTHROPIC_CHAT_MODEL=… ANTHROPIC_CHECK_MODEL=… php artisan test --group=eval-replay
 *
 * Her vaka: olay anındaki durum (cari + hareketler) kurulur, saat olay anına sabitlenir, müşterinin gerçek mesajları sırayla
 * gönderilir; asistan senaryoda olmayan bir şey sorarsa vakadaki koşullu cevap verilir (en çok 10 tur). Puanlama üç ayrı sonuç
 * (ReplayScore): beklenen kayıt · beklenmeyen/çift kayıt · gereksiz soru/döngü. Her vaka işlem içinde, geri alınır.
 */
#[Group('eval-replay')]
class ReplayEvalTest extends TestCase
{
    use DatabaseTransactions;
    use ReplayRequests;

    private static array $results = [];

    private array $partyIds = [];

    private static function data(): array
    {
        $file = getenv('EVAL_REPLAY') ?: '';
        $path = str_starts_with($file, '/') ? $file : dirname(__DIR__, 3) . '/' . $file;

        return $file !== '' && is_file($path) ? require $path : ['firms' => [], 'cases' => []];
    }

    public static function cases(): array
    {
        $out = [];
        foreach (self::data()['cases'] as $case) {
            $out[$case['id']] = [$case];
        }

        return $out ?: ['(vaka dosyası yok)' => [[]]];
    }

    #[DataProvider('cases')]
    public function test_replay(array $case): void
    {
        if ($case === []) {
            $this->markTestSkipped('EVAL_REPLAY verilmedi');
        }
        $firm = self::data()['firms'][$case['firm']];
        config([
            'modules' => ModuleProfiles::resolve($firm['profile'], ($firm['modules'] ?? []) + ['smart_assistant' => true]),
            'app.profile' => $firm['profile'], 'app.project_label' => null, 'app.sector' => $firm['sector'] ?? null,
            'app.agreement_direction' => $firm['agreement_direction'] ?? 'purchase',
            'app.business_note' => $firm['business_note'] ?? null,
            'services.twilio.verify_signature' => false,
            'services.twilio.allowed_phones' => ['0555 999 00 01'],
            'services.dev_usage.report_in_tests' => true,
        ]);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SMtest'], 201)]); // AI çağrıları gerçek
        // Olay anı (İstanbul saati) → uygulama saati.
        $this->travelTo(\Illuminate\Support\Carbon::parse($case['now'], 'Europe/Istanbul')->timezone(config('app.timezone')));
        $this->setUpState($firm, $case);

        $marks = ['ledger' => (int) PartyLedgerEntry::max('id'), 'expenses' => (int) Expense::max('id'), 'sales' => (int) Sale::max('id'),
            'parties' => (int) Party::max('id')];
        $t0 = microtime(true);
        $transcript = $this->converse($case);
        $result = $this->grade($case, $marks, $transcript);
        $result += ['seconds' => round(microtime(true) - $t0, 1), 'transcript' => $transcript];
        self::$results[$case['id']] = $result;
        $this->addToAssertionCount(1); // puanlama sonuç dosyasına yazılır; vaka "düştü" diye test kırılmaz
    }

    /** Müşterinin gerçek mesajları + koşullu cevaplar (vakadaki answers). */
    private function converse(array $case): array
    {
        $queue = $case['steps'];
        $used = [];
        $transcript = [];
        $reply = null;
        for ($turn = 0; $turn < 10; $turn++) {
            $send = null;
            if ($reply !== null) {
                foreach ($case['answers'] ?? [] as $i => $rule) {
                    $when = $rule['when'] ?? 'any';
                    if (($when === 'end' && $queue !== []) || (! empty($rule['once']) && isset($used[$i]))) {
                        continue;
                    }
                    if (preg_match('/' . $rule['if'] . '/iu', $reply)) {
                        $send = $rule['send'];
                        $used[$i] = true;
                        break;
                    }
                }
            }
            if ($send === null) {
                if ($queue === []) {
                    break;
                }
                $send = array_shift($queue);
            }
            $reply = $this->sendMessage($send);
            $transcript[] = ['in' => $send, 'out' => $reply];
        }

        return $transcript;
    }

    private function setUpState(array $firm, array $case): void
    {
        // Yerel deneme DB'sindeki mevcut veriler bağlama karışmasın (işlem sonunda geri alınır).
        Project::query()->update(['status' => 'cancelled']);
        AssistantMemory::query()->update(['status' => 'deleted']);
        Product::query()->update(['is_active' => false]);
        $projects = [];
        foreach ($firm['projects'] ?? [] as $name) {
            $projects[] = Project::create(['name' => $name, 'status' => 'active'])->id;
        }
        $this->partyIds = [];
        foreach (array_merge($firm['parties'] ?? [], $case['parties'] ?? []) as $name => $attrs) {
            $this->partyIds[$name] = Party::create(['name' => $name] + $attrs)->id;
        }
        // ['party', type, amount, 'Y-m-d', description]
        foreach ($case['ledger'] ?? [] as [$party, $type, $amount, $date, $desc]) {
            PartyLedgerEntry::create(['party_id' => $this->partyIds[$party], 'type' => $type, 'amount' => $amount, 'entry_date' => $date,
                'description' => $desc]);
        }
        ExpenseContext::$only = ['parties' => array_values($this->partyIds), 'projects' => $projects, 'products' => [], 'categories' => [],
            'expenses' => [], '_after' => ['parties' => (int) Party::max('id'), 'projects' => (int) Project::max('id'),
                'products' => (int) Product::max('id'), 'categories' => (int) \App\Models\ExpenseCategory::max('id'), 'expenses' => (int) Expense::max('id')]];
    }

    /** Üç ayrı sonuç: beklenen kayıt · beklenmeyen/çift kayıt · gereksiz soru/döngü. */
    private function grade(array $case, array $marks, array $transcript): array
    {
        $expected = [];
        $unexpected = [];
        $facts = [];
        $targets = [];
        // Aday cariler: vakada kurulanlar + bu vakada açılanlar (Türkçe harf duyarlı eşleşme PHP'de).
        $names = $this->partyIds ? array_flip($this->partyIds) : [];
        foreach (Party::where('id', '>', $marks['parties'])->pluck('name', 'id') as $id => $name) {
            $names[$id] = $name;
        }
        foreach ($case['expect']['parties'] ?? [] as $like => $want) {
            $id = $this->partyIds[$like] ?? ReplayScore::findParty($like, $names);
            if (! $id || ! ($party = Party::find($id))) {
                $expected[] = "cari yok: {$like}";
                $facts[$like] = null;

                continue;
            }
            $targets[] = $party->id;
            $rows = PartyLedgerEntry::where('party_id', $party->id)->where('id', '>', $marks['ledger'])->orderBy('id')->get()
                ->map(fn ($e) => [$e->type, (float) $e->amount])->all();
            $balance = round((float) PartyStatement::build($party)['balance'], 2);
            $facts[$like] = ['name' => $party->name, 'new_rows' => $rows, 'balance' => $balance];
            $m = ReplayScore::matchRows($rows, $want);
            foreach ($m['missing'] as $x) {
                $expected[] = "{$party->name}: {$x} yok";
            }
            if (isset($want['balance']) && abs($balance - $want['balance']) > 0.01) {
                $expected[] = "{$party->name} bakiye: beklenen {$want['balance']}, gelen {$balance}";
            }
            foreach ($m['unexpected'] as $x) {
                $unexpected[] = "{$party->name}: fazladan {$x}";
            }
            foreach ($m['forbidden'] as $x) {
                $unexpected[] = "{$party->name}: olmaması gereken {$x}";
            }
        }
        // Hedef dışı carilere düşen yeni hareketler.
        foreach (PartyLedgerEntry::where('id', '>', $marks['ledger'])->whereNotIn('party_id', $targets)->get() as $e) {
            $unexpected[] = 'başka cari (' . ($names[$e->party_id] ?? "#{$e->party_id}") . "): {$e->type} " . (float) $e->amount;
        }
        $newExpenses = Expense::where('id', '>', $marks['expenses'])->count();
        $newSales = Sale::where('id', '>', $marks['sales'])->count();
        if ($newExpenses !== ($case['expect']['expenses'] ?? 0)) {
            $unexpected[] = 'gider: beklenen ' . ($case['expect']['expenses'] ?? 0) . ", oluşan {$newExpenses}";
        }
        if ($newSales !== ($case['expect']['sales'] ?? 0)) {
            $unexpected[] = 'satış belgesi: beklenen ' . ($case['expect']['sales'] ?? 0) . ", oluşan {$newSales}";
        }
        $q = ReplayScore::questions($transcript, $case['allowed_questions'] ?? []);
        // Gerekli soru (kişi/tutar/yön…): sorulan sorulardan biri kalıba uymalı; sorulmadıysa ayrı hata.
        $required = array_values(array_filter($case['required_questions'] ?? [],
            fn ($re) => ! collect($q['asked'])->contains(fn ($a) => preg_match('/' . $re . '/iu', $a['text']))));

        return [
            'required_ok' => $required === [], 'required_missing' => $required,
            'expected_ok' => $expected === [], 'expected_fails' => $expected,
            'unexpected_ok' => $unexpected === [], 'unexpected' => $unexpected,
            'questions_ok' => $q['unnecessary'] === [] && $q['loops'] === [], 'questions' => $q,
            'ok' => $expected === [] && $unexpected === [] && $q['unnecessary'] === [] && $q['loops'] === [] && $required === [],
            'facts' => $facts, 'model' => config('services.anthropic.chat_model'), 'check_model' => config('services.anthropic.check_model'),
        ];
    }

    protected function tearDown(): void
    {
        ExpenseContext::$only = null;
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$results === []) {
            return;
        }
        $out = getenv('EVAL_REPLAY_OUT') ?: dirname(__DIR__, 3) . '/storage/app/eval-private/replay-result.json';
        @mkdir(dirname($out), 0700, true);
        $r = collect(self::$results);
        $summary = ['beklenen_kayıt' => $r->where('expected_ok', true)->count(), 'beklenmeyen_kayıt_yok' => $r->where('unexpected_ok', true)->count(),
            'gereksiz_soru_yok' => $r->where('questions_ok', true)->count(), 'gerekli_soru_soruldu' => $r->where('required_ok', true)->count(),
            'toplam' => $r->count()];
        file_put_contents($out, json_encode(['tarih' => date('c'), 'özet' => $summary, 'vakalar' => self::$results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fwrite(STDERR, "\nYENİDEN OYNATMA: " . json_encode($summary, JSON_UNESCAPED_UNICODE) . " → {$out}\n");
    }
}
