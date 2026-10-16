<?php

namespace Tests\Feature\Eval;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use App\Models\Project;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ExpenseContext;
use App\Support\ModuleProfiles;
use App\Support\TradeDesk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Asistan deneme seti — GERÇEK AI ile uçtan uca (ücretli; normal `php artisan test` çalıştırmaz).
 *
 *   php artisan test --group=eval --exclude-group=eval-capability   bozulma seti (hep geçmeli)
 *   php artisan test --group=eval --filter=test_capability          yetenek seti (Faz 1–5 hedefleri)
 *   EVAL_ONLY=akkus-kasa-sube php artisan test --group=eval           tek vaka
 *
 * Her vaka kendi işlemi içinde çalışır ve geri alınır (yerel DB'de iz kalmaz). Sonuç: storage/app/eval/son.json.
 */
#[Group('eval')]
class AssistantEvalTest extends TestCase
{
    use DatabaseTransactions;

    private static array $results = [];

    private array $ids = [];

    private string $phone = '';

    private static function data(): array
    {
        return require __DIR__ . '/cases.php';
    }

    private static function provide(string $group): array
    {
        $only = getenv('EVAL_ONLY') ?: null;
        $out = [];
        foreach (self::data()['cases'] as $case) {
            if ($case['group'] === $group && (! $only || $case['id'] === $only)) {
                $out[$case['id']] = [$case];
            }
        }

        return $out ?: ['(bu grupta vaka yok)' => [[]]];
    }

    public static function regression(): array
    {
        return self::provide('regression');
    }

    public static function capability(): array
    {
        return self::provide('capability');
    }

    /** "Önce anla, sonra kaydet" (plan once-anla-sonra-kaydet.md) — Taner vakaları; bugün kalması beklenir, bozulma setine girmez. */
    public static function understand(): array
    {
        return self::provide('understand');
    }

    #[DataProvider('understand')]
    #[Group('eval-capability')]
    #[Group('eval-understand')]
    public function test_understand(array $case): void
    {
        $this->runCase($case);
    }

    #[DataProvider('regression')]
    public function test_regression(array $case): void
    {
        $this->runCase($case);
    }

    #[DataProvider('capability')]
    #[Group('eval-capability')]
    public function test_capability(array $case): void
    {
        $this->runCase($case);
    }

    private function runCase(array $case): void
    {
        if ($case === []) {
            $this->markTestSkipped('süzgeçte bu grupta vaka yok');
        }
        $data = self::data();
        $firm = $data['firms'][$case['firm']];
        $digits = sprintf('%07d', crc32($case['id']) % 10000000);
        $this->phone = 'whatsapp:+90555' . $digits;
        config([
            'modules' => ModuleProfiles::resolve($firm['profile'], ($firm['modules'] ?? []) + ['smart_assistant' => true]
                + (getenv('EVAL_VOICE') ? ['natural_voice' => true] : [])),
            'app.profile' => $firm['profile'], 'app.project_label' => null,
            'services.twilio.verify_signature' => false,
            'services.twilio.allowed_phones' => ['0555 ' . substr($digits, 0, 3) . ' ' . substr($digits, 3, 2) . ' ' . substr($digits, 5, 2)],
        ]);
        Http::fake([
            // Fotoğraf/belge adımı: webhook medyayı Twilio'dan indirir → deneme dosyası döner.
            'api.twilio.com/eval-media/*' => fn ($r) => Http::response((string) file_get_contents(__DIR__ . '/fixtures/' . basename($r->url())), 200),
            'api.twilio.com/*' => Http::response(['sid' => 'SMtest'], 201),
        ]); // AI çağrıları gerçek
        $this->fixtures($firm);
        foreach ($data['seeds'][$case['seed'] ?? ''] ?? [] as $row) {
            $this->seedRow($row);
        }

        $start = ['ledger' => (int) PartyLedgerEntry::max('id'), 'sales' => (int) Sale::max('id'), 'expenses' => (int) Expense::max('id'),
            'pending' => (int) WhatsappPendingExpense::max('id')];
        $replies = [];
        $stepFails = [];
        $t0 = microtime(true);
        foreach ($case['steps'] as $step) {
            if (isset($step['pending'])) {
                WhatsappPendingExpense::create(['phone' => $this->phone, 'extracted' => $this->resolve($step['pending']),
                    'summary' => 'deneme', 'status' => 'awaiting_confirmation']);

                continue;
            }
            $form = ['From' => $this->phone, 'Body' => $step['send'] ?? '', 'NumMedia' => 0, 'MessageSid' => 'SM' . uniqid()];
            if (isset($step['media'])) {
                $form = ['NumMedia' => 1, 'MediaUrl0' => 'https://api.twilio.com/eval-media/' . $step['media'],
                    'MediaContentType0' => str_ends_with($step['media'], '.pdf') ? 'application/pdf' : 'image/jpeg'] + $form;
            }
            $res = $this->post('/whatsapp/webhook', $form);
            $replies[] = trim(strip_tags(html_entity_decode((string) $res->getContent(), ENT_QUOTES | ENT_XML1)));
            // "🔎 Bakıyorum" → asıl cevap istek sonrası ayrı mesajla gitti (konuşma kaydında son giden mesaj).
            if (str_contains(end($replies), 'Bakıyorum')) {
                $later = \App\Models\WhatsappMessage::where('phone', \App\Support\Phone::normalize($this->phone))->where('direction', 'out')
                    ->latest('id')->value('body');
                if ($later && ! str_contains($later, 'Bakıyorum')) {
                    $replies[count($replies) - 1] = '🔎 Bakıyorum… ⏩ ' . $later;
                }
            }
            if (getenv('EVAL_SHOW')) {
                fwrite(STDERR, "\n> " . (isset($step['media']) ? '[📎 ' . $step['media'] . '] ' : '') . ($step['send'] ?? '') . "\n< " . end($replies) . "\n");
            }
            // Adım beklentisi (ara cevap: "burada soru sormalıydı" gibi) — son cevap gibi puanlanır, hatalar birikir.
            if (isset($step['expect'])) {
                $stepDraft = WhatsappPendingExpense::where('phone', $this->phone)->where('id', '>', $start['pending'])->latest('id')->first();
                foreach ($this->grade($step['expect'], (string) end($replies), $stepDraft, $start) as $f) {
                    $stepFails[] = 'adım ' . count($replies) . ': ' . $f;
                }
            }
        }
        $seconds = round(microtime(true) - $t0, 1);
        $reply = (string) end($replies);
        $draft = WhatsappPendingExpense::where('phone', $this->phone)->where('id', '>', $start['pending'])->latest('id')->first();

        $fails = array_merge($stepFails, $this->grade($case['expect'] ?? [], $reply, $draft, $start));
        self::$results[$case['id']] = ['group' => $case['group'], 'ok' => $fails === [], 'fails' => $fails, 'seconds' => $seconds,
            'reply' => mb_substr($reply, 0, 400), 'draft' => $draft?->extracted ? array_intersect_key($draft->extracted,
                array_flip(['kind', 'amount', 'party_id', 'party_name', 'project_id', 'payment_purpose', 'question', 'cash_amount', 'card_amount'])) : null];

        $this->assertSame([], $fails, $case['id'] . "\nCEVAP: " . $reply . "\nTASLAK: " . json_encode($draft?->extracted, JSON_UNESCAPED_UNICODE));
    }

    /** @return list<string> */
    private function grade(array $expect, string $reply, ?WhatsappPendingExpense $draft, array $start): array
    {
        $fails = [];
        $low = mb_strtolower($reply);
        foreach ($expect['reply_has'] ?? [] as $needle) {
            if (! str_contains($low, mb_strtolower($needle))) {
                $fails[] = "cevapta yok: \"{$needle}\"";
            }
        }
        if (isset($expect['reply_any']) && ! collect($expect['reply_any'])->contains(fn ($n) => str_contains($low, mb_strtolower($n)))) {
            $fails[] = 'cevapta hiçbiri yok: ' . implode(' / ', $expect['reply_any']);
        }
        foreach ($expect['reply_not'] ?? [] as $needle) {
            if (str_contains($low, mb_strtolower($needle))) {
                $fails[] = "cevapta olmamalı: \"{$needle}\"";
            }
        }
        if (! empty($expect['no_new_draft']) && $draft && $draft->status === 'awaiting_confirmation') {
            $fails[] = 'yeni taslak açılmamalıydı (' . ($draft->extracted['kind'] ?? '?') . ')';
        }
        if (isset($expect['draft'])) {
            if (! $draft) {
                $fails[] = 'taslak yok';
            } else {
                foreach ($this->resolve($expect['draft']) as $field => $want) {
                    $got = $draft->extracted[$field] ?? null;
                    $options = is_array($want) ? $want : [$want];
                    $match = collect($options)->contains(fn ($w) => is_numeric($w) && is_numeric($got)
                        ? abs((float) $w - (float) $got) < 0.01 : $w === $got);
                    if (! $match) {
                        $fails[] = "taslak.{$field}: beklenen " . json_encode($want, JSON_UNESCAPED_UNICODE) . ', gelen ' . json_encode($got, JSON_UNESCAPED_UNICODE);
                    }
                }
            }
        }
        // Soru sordu mu / onaya hazır mı: onay cümlesi ("Kaydedeyim mi?", "Onaylamak için") kayıt hazır demektir.
        $ready = collect(['kaydedeyim mi', 'onaylamak için'])->contains(fn ($m) => str_contains($low, $m));
        $otherQuestions = substr_count(str_replace(['kaydedeyim mi?', 'kaydedeyim mi ?'], '', $low), '?');
        if (! empty($expect['asks']) && ($ready || $otherQuestions === 0)) {
            $fails[] = $ready ? 'soru sormadan kaydı onaya hazırladı (tahmin)' : 'soru sormadı';
        }
        if (! empty($expect['ready']) && (! $ready || $otherQuestions > 0)) {
            $fails[] = ! $ready ? 'kayıt onaya hazır değil' : 'açık mesajda gereksiz soru sordu';
        }
        // Anlam cümlesi: olayın düz anlatımı (ör. "henüz takılmadı", "teslim etti") — biri yeter.
        if (isset($expect['meaning_any']) && ! collect($expect['meaning_any'])->contains(fn ($n) => str_contains($low, mb_strtolower($n)))) {
            $fails[] = 'anlam cümlesi yok (beklenen biri: ' . implode(' / ', $expect['meaning_any']) . ')';
        }
        // Son bakiye: >0 cari bize borçlu, <0 biz ona borçluyuz (PartyStatement konvansiyonu).
        foreach ($expect['balance'] ?? [] as $ref => $want) {
            $name = preg_replace('/^@party:/u', '', $ref);
            $party = Party::find($this->ids['party'][$name] ?? 0) ?? Party::where('name', $name)->latest('id')->first();
            $got = $party ? (float) \App\Support\PartyStatement::build($party)['balance'] : null;
            if ($got === null || abs($got - (float) $want) > 0.01) {
                $fails[] = "bakiye {$name}: beklenen " . $want . ', gelen ' . json_encode($got);
            }
        }
        $now = ['ledger' => PartyLedgerEntry::where('id', '>', $start['ledger'])->count(), 'sales' => Sale::where('id', '>', $start['sales'])->count(),
            'expenses' => Expense::where('id', '>', $start['expenses'])->count()];
        foreach ($expect['saved'] ?? [] as $table => $count) {
            if ($now[$table] !== $count) {
                $fails[] = "kayıt {$table}: beklenen {$count}, oluşan {$now[$table]}";
            }
        }

        return $fails;
    }

    private function fixtures(array $firm): void
    {
        // Yerel deneme DB'sindeki eski projeler/ürünler menüye ve eşleşmeye karışmasın (işlem sonunda geri alınır).
        Project::query()->update(['status' => 'cancelled']);
        \App\Models\AssistantMemory::query()->update(['status' => 'deleted']);
        Product::query()->update(['is_active' => false]);
        $this->ids = ['party' => [], 'project' => [], 'product' => [], 'category' => []];
        foreach ($firm['projects'] ?? [] as $name) {
            $this->ids['project'][$name] = Project::create(['name' => $name, 'status' => 'active'])->id;
        }
        foreach ($firm['parties'] ?? [] as $name => $attrs) {
            $this->ids['party'][$name] = Party::create(['name' => $name] + $attrs)->id;
        }
        foreach ($firm['products'] ?? [] as [$name, $unit]) {
            $u = Unit::firstOrCreate(['code' => 'eval-' . $unit], ['name' => $unit]);
            $this->ids['product'][$name] = Product::create(['name' => $name, 'type' => Product::TYPE_PRODUCT, 'unit_id' => $u->id, 'is_active' => true])->id;
        }
        foreach ($firm['categories'] ?? [] as $name) {
            $this->ids['category'][$name] = ExpenseCategory::firstOrCreate(['name' => $name])->id;
        }
        ExpenseContext::$only = ['parties' => array_values($this->ids['party']), 'projects' => array_values($this->ids['project']),
            'products' => array_values($this->ids['product']), 'categories' => array_values($this->ids['category']), 'expenses' => [],
            '_after' => ['parties' => (int) Party::max('id'), 'projects' => (int) Project::max('id'), 'products' => (int) Product::max('id'),
                'categories' => (int) ExpenseCategory::max('id'), 'expenses' => (int) Expense::max('id')]];
    }

    private function seedRow(array $row): void
    {
        // "today" = iş günü: gece 00–05 asistan "bugün"ü önceki gün sayar (kural) — deneme verisi de öyle.
        $row = array_map(fn ($v) => $v === 'today' && now()->hour < 5 ? 'yesterday' : $v, $row);
        match ($row[0]) {
            'ledger' => PartyLedgerEntry::create(['party_id' => $this->ids['party'][$row[1]], 'type' => $row[2], 'amount' => $row[3],
                'entry_date' => now()->modify($row[4])->toDateString(), 'description' => $row[5]]),
            'sale' => TradeDesk::saveSale(['mode' => 'cash', 'manual_amount' => (string) $row[2], 'project_id' => $this->ids['project'][$row[1]],
                'sale_date' => now()->modify($row[3])->toDateString()]),
            'purchase' => TradeDesk::savePurchase(['mode' => 'party', 'party_id' => $this->ids['party'][$row[1]],
                'purchase_date' => now()->modify($row[5])->toDateString(),
                'lines' => [['product_id' => $this->ids['product'][$row[2]], 'quantity' => (string) $row[3], 'unit_price' => (string) $row[4]]]]),
            'memory' => \App\Models\AssistantMemory::create(['kind' => $row[1], 'subject' => $row[2], 'fact' => $row[3],
                'party_id' => isset($row[4]) ? $this->ids['party'][$row[4]] : null]),
            'expense' => Expense::create(['amount' => $row[1], 'expense_date' => now()->modify($row[2])->toDateString(),
                'description' => $row[3], 'payment_status' => 'paid']),
        };
    }

    private function resolve(array $fields): array
    {
        return array_map(function ($v) {
            if (is_array($v)) {
                return array_map(fn ($x) => $this->resolveOne($x), $v);
            }

            return $this->resolveOne($v);
        }, $fields);
    }

    private function resolveOne($v)
    {
        if (is_string($v) && preg_match('/^@(party|project|product|category):(.+)$/u', $v, $m)) {
            return $this->ids[$m[1]][$m[2]] ?? throw new \RuntimeException("deneme setinde yok: {$v}");
        }

        return $v;
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
        $dir = dirname(__DIR__, 3) . '/storage/app/eval';
        @mkdir($dir, 0775, true);
        $groups = collect(self::$results)->groupBy('group')->map(fn ($g) => ['geçti' => $g->where('ok', true)->count(), 'toplam' => $g->count(),
            'ort_sn' => round($g->avg('seconds'), 1), 'en_uzun_sn' => $g->max('seconds')]);
        file_put_contents($dir . '/son.json', json_encode(['tarih' => date('c'), 'özet' => $groups, 'vakalar' => self::$results],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fwrite(STDERR, "\nDENEME SETİ: " . $groups->map(fn ($g, $k) => "{$k} {$g['geçti']}/{$g['toplam']} (ort {$g['ort_sn']} sn, en uzun {$g['en_uzun_sn']} sn)")->implode(' · ') . "\n");
    }
}
