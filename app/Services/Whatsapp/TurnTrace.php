<?php

namespace App\Services\Whatsapp;

use App\Support\Phone;
use GuzzleHttp\Promise\Create;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * WhatsApp turu teşhis kaydı (23.10, Adım 1): "mesajı model mi yanlış anladı, eksik bilgi mi aldı, kod mu değiştirdi?"
 * sorusu kayıtlardan cevaplanabilsin. Davranışa DOKUNMAZ: iz taslağa ya da modele giden hiçbir yere yazılmaz, ayrı dosyada durur
 * (storage/app/wa-trace/{firma}/{gün}/{tur}.json); büyük tekrar eden metinler (sistem talimatı, araç şeması, fotoğraf) sha256 ile
 * blobs/ altında bir kez.
 *
 * Kaydedilenler: modele giden istek gövdesi + yanıt (başlıklar asla), işlem hattının her adımında değişen alanlar (step), karar
 * kodunun OKUDUĞU veri ve hesapladığı sonuç — yeniden hesap yok, okuma sarılır (read / statement), soru kaynağı (note), kayıtsız
 * kalan karar sorguları (replay "tam değil"). Teşhis kodunun her hatası yutulur (uyarı logu); asıl işlemin hatası aynen geçer.
 * Açma: WHATSAPP_TRACE=true.
 */
final class TurnTrace
{
    /** Kararı etkileyen tablolar: bunlara sarılmamış okuma yapılırsa iz "tam yeniden oynatılamaz" sayılır. */
    public const DECISION_TABLES = ['parties', 'party_ledger_entries', 'purchases', 'sales', 'expenses', 'contracts', 'contract_payments',
        'agreements', 'agreement_items', 'products', 'stock_movements', 'assistant_memories', 'whatsapp_pending_expenses', 'reminders',
        'projects', 'checks', 'expense_categories', 'record_reviews'];

    /** Kayıt yazılırken işlem geçmişi için yapılan okumalar karar değildir (ActivityLog: cari adı, ayrıntı). */
    private const LOGGING_CALLERS = ['ActivityLog::', 'PartyLedgerEntry::activityDetails', 'Expense::activityDetails', 'Sale::activityDetails',
        'Purchase::activityDetails'];

    private const MAX_SEGMENT_BYTES = 4_000_000;

    private const MAX_BLOB_BYTES = 40_000_000;

    /** Bundan uzun metinler (talimat blokları, şema, fotoğraf) blob olur. */
    private const BLOB_MIN = 1500;

    private static ?TraceContext $ctx = null;

    /** @var array<string,mixed>|null açık bölüm */
    private static ?array $seg = null;

    /** @var list<array>|null read() sırasında toplanan kaynak kayıtlar */
    private static ?array $collector = null;

    private static int $readDepth = 0;

    private static int $coverDepth = 0;

    /** Kancaların kurulduğu uygulama (her uygulama örneğinde bir kez — testlerde her test yeni uygulama). */
    private static ?int $registeredOn = null;

    private static ?string $git = null;

    /** Test kancası: teşhis kodunun içinden çağrılır (hata yalıtımı testi). */
    public static ?\Closure $failHook = null;

    /** Test kancası: bloblar hazır, tur dosyası henüz yazılmadı (temizlikle yarış testi). */
    public static ?\Closure $beforeTurnFile = null;

    /** Ölçüm: son yazımların süresi (ms) — süre etkisi raporu. */
    public static array $flushMs = [];

    public static function enabled(): bool
    {
        return (bool) config('services.whatsapp.trace');
    }

    /**
     * Bu firmada iz tutulur mu (pilot: yalnız seçilen firmalar). Firma sistemi açıkken yalnız WHATSAPP_TRACE_FIRMS listesindeki
     * kimlikler; liste boşsa hiçbir firma. Firma sistemi kapalı (yerel / tek firma) → genel anahtar yeter. Firma seçilmemişse yok.
     */
    public static function enabledFor(?string $firmId): bool
    {
        if (! self::enabled()) {
            return false;
        }
        if (! config('tenancy.enabled')) {
            return true;
        }

        return $firmId !== null && in_array($firmId, array_map('strval', (array) config('services.whatsapp.trace_firms', [])), true);
    }

    public static function active(): bool
    {
        return self::$seg !== null;
    }

    public static function context(): ?TraceContext
    {
        return self::$ctx;
    }

    public static function root(): string
    {
        return rtrim((string) (config('services.whatsapp.trace_path') ?: storage_path('app/wa-trace')), '/');
    }

    /** Uygulama açılışında bir kez: model çağrılarını ve karar sorgularını gözleyen kancalar (iz kapalıyken hemen döner). */
    public static function register(): void
    {
        $app = spl_object_id(app());
        if (self::$registeredOn === $app) {
            return;
        }
        self::$registeredOn = $app;
        Http::globalMiddleware(self::middleware());
        DB::listen(fn (QueryExecuted $q) => self::query($q));
    }

    // ---------------------------------------------------------------- tur / bölüm

    public static function begin(string $turnId, string $phone, array $meta = []): void
    {
        self::$ctx = null;
        self::$seg = null;
        self::$collector = null;
        self::$readDepth = 0;
        self::$coverDepth = 0;
        $firm = \App\Tenancy\Tenancy::current();
        if (! self::enabledFor($firm ? (string) $firm->id : null)) {
            return;
        }
        $t = hrtime(true);
        try {
            self::hook('begin');
            self::$ctx = new TraceContext($firm ? (string) $firm->id : 'local', preg_replace('/[^A-Za-z0-9_-]/', '_', $turnId) ?: 'tur',
                Phone::normalize($phone), now('Europe/Istanbul')->toDateString());
            self::open('request');
            self::$seg['meta'] = $meta + self::environment();
        } catch (\Throwable $e) {
            self::$ctx = null;
            self::$seg = null;
            self::fail($e);
        }
        self::spent($t);
    }

    /** Bu turun devamı arka planda gelecek (ertelenen tur): bölüm gelmezse iz "eksik" görünür. */
    public static function expectContinuation(string $why): void
    {
        if (self::$seg === null || self::$ctx === null) {
            return;
        }
        self::$ctx->expected++;
        self::note('continuation', $why);
    }

    /** Bölümü kapat; istekte yazım istek bittikten sonra (terminating), arka planda hemen. */
    public static function finish(array $result = [], bool $background = false): void
    {
        if (self::$seg === null || self::$ctx === null) {
            self::$ctx = null;
            self::$seg = null;

            return;
        }
        $t = hrtime(true);
        try {
            self::hook('finish');
            self::$seg['result'] = self::summarize($result, 0);
        } catch (\Throwable $e) {
            self::fail($e);
        }
        self::spent($t);
        $ctx = self::$ctx;
        $seg = self::close();
        self::$ctx = null;
        self::$seg = null;
        self::schedule($ctx, $seg, $background);
    }

    /**
     * Arka plan devamı: bağlam AÇIKÇA verilir (closure'a alınan). null → bu iş hiçbir tura yazılmaz (başka turla karışmasın).
     *
     * @return array önceki durum — suspend()'e geri verilir
     */
    public static function resume(?TraceContext $ctx, string $label): array
    {
        $prev = [self::$ctx, self::$seg, self::$collector, self::$readDepth, self::$coverDepth];
        self::$ctx = null;
        self::$seg = null;
        self::$collector = null;
        self::$readDepth = 0;
        self::$coverDepth = 0;
        if ($ctx === null || ! self::enabledFor($ctx->firmId === 'local' ? null : $ctx->firmId)) {
            return $prev;
        }
        try {
            self::$ctx = $ctx;
            self::open($label);
            self::$seg['meta'] = self::environment();
        } catch (\Throwable $e) {
            self::$ctx = null;
            self::$seg = null;
            self::fail($e);
        }

        return $prev;
    }

    public static function suspend(array $prev, array $result = []): void
    {
        self::finish($result, background: true);
        [self::$ctx, self::$seg, self::$collector, self::$readDepth, self::$coverDepth] = $prev;
    }

    // ---------------------------------------------------------------- kayıt

    /** İşlem hattı adımı: değişen alanlar. Veri AYNEN döner. */
    public static function step(string $name, array $data): array
    {
        if (self::$seg === null) {
            return $data;
        }
        $t = hrtime(true);
        try {
            self::hook('step');
            $prev = self::$seg['_last'];
            $entry = ['n' => ++self::$seg['_order'], 'step' => $name];
            // "snapshot:" = tam görüntü (ör. önceki taslak), zincirin sonraki farkları ona göre hesaplanmaz.
            if (str_starts_with($name, 'snapshot:')) {
                $entry['data'] = $data;
                self::push('steps', $entry);
                self::spent($t);

                return $data;
            }
            // "baseline:" = tam görüntü ve sonraki farkların başlangıcı (ör. önceki taslak → bu turun değiştirdikleri).
            if ($prev === null || str_starts_with($name, 'ai_input') || str_starts_with($name, 'baseline:')) {
                $entry['data'] = $data;
            } elseif (($diff = self::diff($prev, $data)) === []) {
                $entry['same'] = true;
            } else {
                $entry['changed'] = $diff;
            }
            self::$seg['_last'] = $data;
            self::$seg['_last_step'] = $name;
            self::push('steps', $entry);
        } catch (\Throwable $e) {
            self::fail($e);
        }
        self::spent($t);

        return $data;
    }

    public static function note(string $key, mixed $value): void
    {
        if (self::$seg === null) {
            return;
        }
        $t = hrtime(true);
        try {
            self::hook('note');
            self::push('notes', ['n' => ++self::$seg['_order'], 'key' => $key, 'at_step' => self::$seg['_last_step'], 'value' => self::summarize($value, 0)]);
        } catch (\Throwable $e) {
            self::fail($e);
        }
        self::spent($t);
    }

    /**
     * Karar okuması: $fn BİR KEZ çalışır, sonucu aynen döner, istisnası aynen geçer. Kaydedilen: ne okundu, hangi adımda, sonuç
     * (model/koleksiyonsa kimlik + alanlar), okuma sırasında toplanan kaynak kayıtlar, süre.
     *
     * @param  (callable(mixed):mixed)|null  $project  sonucun izdeki hâli (ör. ekstrede satırlar yerine bakiye + kaynaklar)
     */
    public static function read(string $what, callable $fn, array $meta = [], ?callable $project = null): mixed
    {
        if (self::$seg === null) {
            return $fn();
        }
        $outer = self::$collector;
        self::$collector = [];
        self::$readDepth++;
        $t0 = hrtime(true);
        $result = null;
        $error = null;
        try {
            $result = $fn();

            return $result;
        } catch (\Throwable $e) {
            $error = $e;

            throw $e;
        } finally {
            $t1 = hrtime(true);
            $refs = self::$collector ?? [];
            self::$readDepth--;
            self::$collector = $outer === null ? null : [...$outer, ...$refs];
            try {
                self::hook('read');
                if (self::$seg !== null) {
                    $shown = $error === null && $project !== null ? $project($result) : $result;
                    self::push('reads', ['n' => ++self::$seg['_order'], 'what' => $what, 'at_step' => self::$seg['_last_step'], 'meta' => $meta,
                        'ms' => round(($t1 - $t0) / 1e6, 2), 'result' => $error === null ? self::summarize($shown, 0) : null,
                        'error' => $error ? get_class($error) : null, 'refs' => array_slice($refs, 0, 500),
                        'refs_truncated' => count($refs) > 500 ?: null]);
                    if (count($refs) > 500) {
                        self::reason('refs_truncated');
                    }
                }
            } catch (\Throwable $d) {
                self::fail($d);
            }
            self::spent($t1);
        }
    }

    /** Cari ekstresi (bakiye) okuması: sonuç aynen; izde bakiye/toplamlar + kaynak satırlar (tablo, id, tutar…). */
    public static function statement(string $what, \App\Models\Party $party, array $filters = []): array
    {
        return self::read($what, fn () => \App\Support\PartyStatement::build($party, $filters), ['party_id' => $party->id] + ($filters ? ['filters' => $filters] : []),
            fn (array $s) => ['balance' => $s['balance'], 'total_borc' => $s['total_borc'], 'total_alacak' => $s['total_alacak'],
                'opening' => $s['opening'], 'row_count' => count($s['rows'])]);
    }

    /** Hesaplamanın içinden: bu okumada kullanılan kaynak kayıt (yalnız read() açıkken). */
    public static function collecting(): bool
    {
        return self::$collector !== null;
    }

    public static function collect(string $source, mixed $id, array $fields = []): void
    {
        if (self::$collector === null) {
            return;
        }
        try {
            self::$collector[] = ['src' => $source, 'id' => $id] + $fields;
        } catch (\Throwable $e) {
            self::fail($e);
        }
    }

    /** Çıktısı model girdisinde zaten kayıtlı olan okumalar (talimat bağlamı, konuşma geçmişi, araç sonucu). */
    public static function cover(string $what, callable $fn): mixed
    {
        if (self::$seg === null) {
            return $fn();
        }
        self::$coverDepth++;
        try {
            return $fn();
        } finally {
            self::$coverDepth--;
        }
    }

    // ---------------------------------------------------------------- model çağrıları (HTTP ara katmanı)

    public static function middleware(): callable
    {
        return function (callable $handler) {
            return function (RequestInterface $request, array $options) use ($handler) {
                $rec = null;
                if (self::$seg !== null) {
                    $t = hrtime(true);
                    try {
                        $rec = self::captureRequest($request);
                    } catch (\Throwable $e) {
                        self::fail($e);
                    }
                    self::spent($t);
                }
                if ($rec === null) {
                    return $handler($request, $options);
                }
                try {
                    $promise = $handler($request, $options);
                } catch (\Throwable $e) {
                    self::captureEnd($rec, null, $e);

                    throw $e;
                }

                return $promise->then(
                    function ($response) use ($rec) {
                        self::captureEnd($rec, $response instanceof ResponseInterface ? $response : null, null);

                        return $response;
                    },
                    function ($reason) use ($rec) {
                        self::captureEnd($rec, null, $reason);

                        return Create::rejectionFor($reason);
                    },
                );
            };
        };
    }

    private static function captureRequest(RequestInterface $request): ?array
    {
        if ($request->getUri()->getHost() !== 'api.anthropic.com') {
            return null;
        }
        self::hook('request');
        [$text, $why] = self::readStream($request->getBody());
        $json = $text !== null ? json_decode($text, true) : null;
        $payload = is_array($json) ? self::externalize($json) : ($text !== null ? ['raw' => self::blob($text)] : null);
        if ($why !== null) {
            self::reason($why);
        }
        $deadline = ChatAssistant::$deadline;

        return ['seq' => self::$seg['seq'], 'n' => ++self::$seg['_order'], 'caller' => self::caller(), 'at_step' => self::$seg['_last_step'],
            'path' => $request->getUri()->getPath(), 'deadline_left_s' => $deadline !== null ? round($deadline - microtime(true), 2) : null,
            'request' => $payload, 'request_unreadable' => $why, '_t' => hrtime(true)];
    }

    private static function captureEnd(array $rec, ?ResponseInterface $response, mixed $error): void
    {
        $t = hrtime(true);
        try {
            self::hook('response');
            if (self::$seg === null || self::$seg['seq'] !== $rec['seq']) {
                return; // bölüm kapandı (olmamalı) — başka bölüme yazma
            }
            $rec['ms'] = round(($t - $rec['_t']) / 1e6, 1);
            unset($rec['_t']);
            if ($response !== null) {
                [$text, $why] = self::readStream($response->getBody());
                $json = $text !== null ? json_decode($text, true) : null;
                $rec['status'] = $response->getStatusCode();
                $rec['response'] = is_array($json) ? self::externalize($json) : ($text !== null ? self::cut($text, 20000) : null);
                if ($why !== null) {
                    $rec['response_unreadable'] = $why;
                    self::reason($why);
                }
            }
            if ($error !== null) {
                $rec['error'] = is_object($error) ? get_class($error) . ': ' . self::cut((string) ($error instanceof \Throwable ? $error->getMessage() : ''), 300) : (string) $error;
            }
            self::push('calls', $rec);
        } catch (\Throwable $e) {
            self::fail($e);
        }
        self::spent($t);
    }

    /**
     * Gövdeyi oku ve BAŞLANGIÇTAKİ konuma geri getir. Geri sarılamayan akış okunmaz (tüketilmesin).
     *
     * @return array{0:?string,1:?string} [metin, okunamadıysa sebep]
     */
    private static function readStream(StreamInterface $s): array
    {
        if (! $s->isSeekable()) {
            return [null, 'body_not_seekable'];
        }
        $pos = $s->tell();
        try {
            $s->rewind();

            return [$s->getContents(), null];
        } finally {
            $s->seek($pos);
        }
    }

    /** İstek/yanıt gövdesi: büyük metinler (sistem blokları, araç şeması, görsel/belge verisi) blob'a. */
    private static function externalize(array $json): array
    {
        if (isset($json['system'])) {
            $json['system'] = is_string($json['system']) ? self::maybeBlob($json['system'])
                : array_map(fn ($b) => is_array($b) && isset($b['text']) ? ['text' => self::maybeBlob((string) $b['text'])] + $b : $b, (array) $json['system']);
        }
        if (isset($json['tools'])) {
            $json['tools'] = self::blob((string) json_encode($json['tools'], JSON_UNESCAPED_UNICODE));
        }
        foreach (['messages'] as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                array_walk_recursive($json[$key], function (&$v, $k) {
                    if ($k === 'data' && is_string($v) && strlen($v) > self::BLOB_MIN) {
                        $v = self::blob($v);
                    }
                });
            }
        }

        return $json;
    }

    private static function maybeBlob(string $text): string|array
    {
        return strlen($text) > self::BLOB_MIN ? self::blob($text) : $text;
    }

    private static function blob(string $content): array
    {
        $hash = hash('sha256', $content);
        if (! isset(self::$seg['_blobs'][$hash])) {
            if (self::$seg['_blob_bytes'] + strlen($content) > self::MAX_BLOB_BYTES) {
                self::reason('truncated');

                return ['$blob' => null, 'sha256' => $hash, 'bytes' => strlen($content), 'dropped' => true];
            }
            self::$seg['_blobs'][$hash] = $content;
            self::$seg['_blob_bytes'] += strlen($content);
        }

        return ['$blob' => $hash, 'bytes' => strlen($content)];
    }

    // ---------------------------------------------------------------- sorgu gözlemi

    private static function query(QueryExecuted $q): void
    {
        if (self::$seg === null) {
            return;
        }
        $t = hrtime(true);
        try {
            $sql = ltrim($q->sql);
            if (! preg_match('/^select\b/i', $sql)) {
                return;
            }
            if (self::$readDepth > 0) {
                self::$seg['queries']['captured']++;
            } elseif (self::$coverDepth > 0) {
                self::$seg['queries']['covered']++;
            } else {
                preg_match_all('/\b(?:from|join)\s+[`"]?(\w+)[`"]?/i', $sql, $m);
                $hit = array_values(array_intersect(array_unique($m[1]), self::DECISION_TABLES));
                $caller = self::caller() ?? '?';
                if ($hit !== [] && collect(self::LOGGING_CALLERS)->contains(fn ($p) => str_starts_with($caller, $p))) {
                    $hit = [];
                }
                if ($hit === []) {
                    self::$seg['queries']['other']++;
                } else {
                    foreach ($hit as $table) {
                        self::$seg['queries']['uncaptured'][$table] = (self::$seg['queries']['uncaptured'][$table] ?? 0) + 1;
                    }
                    if (isset(self::$seg['queries']['uncaptured_by'][$caller]) || count(self::$seg['queries']['uncaptured_by']) < 50) {
                        self::$seg['queries']['uncaptured_by'][$caller] = (self::$seg['queries']['uncaptured_by'][$caller] ?? 0) + 1;
                    }
                    if (count(self::$seg['queries']['samples']) < 10) {
                        self::$seg['queries']['samples'][] = ['sql' => mb_substr($sql, 0, 300), 'caller' => $caller, 'at_step' => self::$seg['_last_step']];
                    }
                }
            }
        } catch (\Throwable $e) {
            self::fail($e);
        } finally {
            self::spent($t);
        }
    }

    // ---------------------------------------------------------------- yazım

    private static function schedule(TraceContext $ctx, array $seg, bool $background): void
    {
        $done = false;
        $write = function () use (&$done, $ctx, $seg) {
            if ($done) {
                return; // testlerde terminating geri çağrıları sonraki isteklerde de çalışır
            }
            $done = true;
            self::write($ctx, $seg);
        };
        if ($background) {
            $write();

            return;
        }
        try {
            app()->terminating($write);
        } catch (\Throwable $e) {
            self::fail($e);
        }
    }

    /** Bölümü tur dosyasına ekle: bloblar ortak kilit altında, tur dosyası gün kilidiyle; geçici dosya + rename (atomik). */
    public static function write(TraceContext $ctx, array $seg): void
    {
        $t = hrtime(true);
        try {
            self::hook('write');
            $base = self::root() . '/' . $ctx->firmId;
            $dir = $base . '/' . $ctx->day;
            foreach ([$base . '/blobs', $dir] as $d) {
                if (! is_dir($d) && ! @mkdir($d, 0775, true) && ! is_dir($d)) {
                    throw new \RuntimeException('klasör açılamadı: ' . $d);
                }
            }
            $gc = self::lock($base . '/.gc.lock', LOCK_SH);
            try {
                $blobs = $seg['_blobs'] ?? [];
                unset($seg['_blobs']);
                foreach ($blobs as $hash => $content) {
                    $path = $base . '/blobs/' . $hash;
                    is_file($path) ? @touch($path) : self::atomicPut($path, $content);
                }
                if (self::$beforeTurnFile) {
                    (self::$beforeTurnFile)($ctx, $seg);
                }
                $dayLock = self::lock($dir . '/.lock', LOCK_EX);
                try {
                    $file = $dir . '/' . $ctx->turnId . '.json';
                    $doc = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
                    if (! is_array($doc)) {
                        $doc = ['turn_id' => $ctx->turnId, 'firm_id' => $ctx->firmId, 'phone' => $ctx->phone, 'day' => $ctx->day, 'segments' => []];
                    }
                    $doc['segments'][(string) $seg['seq']] = $seg;
                    uksort($doc['segments'], fn ($a, $b) => (int) $a <=> (int) $b);
                    $doc['expected_segments'] = max((int) ($doc['expected_segments'] ?? 1), 1 + $ctx->expected);
                    $doc['replay'] = self::replaySummary($doc);
                    self::atomicPut($file, (string) json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
                } finally {
                    flock($dayLock, LOCK_UN);
                    fclose($dayLock);
                }
            } finally {
                flock($gc, LOCK_UN);
                fclose($gc);
            }
        } catch (\Throwable $e) {
            self::warn('WhatsApp izi yazılamadı', $e);
        }
        self::$flushMs[] = round((hrtime(true) - $t) / 1e6, 2);
    }

    /** @return resource */
    public static function lock(string $path, int $mode, ?float $waitSeconds = null)
    {
        $h = fopen($path, 'c');
        if ($h === false) {
            throw new \RuntimeException('kilit açılamadı: ' . $path);
        }
        if ($waitSeconds === null) {
            if (! flock($h, $mode)) {
                fclose($h);
                throw new \RuntimeException('kilit alınamadı: ' . $path);
            }

            return $h;
        }
        $until = microtime(true) + $waitSeconds;
        do {
            if (flock($h, $mode | LOCK_NB)) {
                return $h;
            }
            usleep(100_000);
        } while (microtime(true) < $until);
        fclose($h);

        return null;
    }

    private static function atomicPut(string $path, string $content): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $content) === false || ! rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('yazılamadı: ' . $path);
        }
    }

    private static function replaySummary(array $doc): array
    {
        $reasons = [];
        foreach ($doc['segments'] as $seq => $s) {
            foreach ((array) ($s['replay_reasons'] ?? []) as $r) {
                $reasons[] = $r;
            }
        }
        if (count($doc['segments']) < (int) ($doc['expected_segments'] ?? 1)) {
            $reasons[] = 'missing_segment';
        }
        $reasons = array_values(array_unique($reasons));

        return ['complete' => $reasons === [], 'reasons' => $reasons];
    }

    // ---------------------------------------------------------------- iç yardımcılar

    private static function open(string $label): void
    {
        self::$seg = ['seq' => ++self::$ctx->lastSeq, 'label' => $label, 'started_at' => now()->toIso8601String(),
            'steps' => [], 'reads' => [], 'calls' => [], 'notes' => [],
            'queries' => ['captured' => 0, 'covered' => 0, 'other' => 0, 'uncaptured' => [], 'uncaptured_by' => [], 'samples' => []],
            'replay_reasons' => [], 'overhead_ns' => 0, '_bytes' => 0, '_blobs' => [], '_blob_bytes' => 0,
            '_last' => null, '_last_step' => null, '_order' => 0, '_failed' => false];
    }

    private static function close(): array
    {
        $seg = self::$seg;
        try {
            if ($seg['queries']['uncaptured'] !== []) {
                $seg['replay_reasons'][] = 'uncaptured_reads:' . implode(',', array_keys($seg['queries']['uncaptured']));
            }
            if (($seg['meta']['git'] ?? null) === null) {
                $seg['replay_reasons'][] = 'no_code_version';
            }
            $seg['replay_reasons'] = array_values(array_unique($seg['replay_reasons']));
            $seg['overhead_sync_ms'] = round($seg['overhead_ns'] / 1e6, 2);
        } catch (\Throwable $e) {
            self::fail($e);
        }
        unset($seg['_last'], $seg['_order'], $seg['_bytes'], $seg['_blob_bytes'], $seg['_failed'], $seg['overhead_ns']);

        return $seg;
    }

    private static function environment(): array
    {
        $deadline = ChatAssistant::$deadline;

        return [
            'time_utc' => now()->utc()->toIso8601String(),
            'time_istanbul' => now('Europe/Istanbul')->toIso8601String(),
            'firm_id' => self::$ctx?->firmId,
            'modules' => (array) config('modules'),
            'app' => ['profile' => config('app.profile'), 'sector' => config('app.sector'), 'business_note' => config('app.business_note'),
                'project_label' => config('app.project_label')],
            'models' => array_intersect_key((array) config('services.anthropic'),
                array_flip(['model', 'chat_model', 'check_model', 'vision_model', 'analysis_model', 'chat_assistant'])),
            'git' => self::gitVersion(),
            'deadline_left_s' => $deadline !== null ? round($deadline - microtime(true), 2) : null,
        ];
    }

    private static function gitVersion(): ?string
    {
        if (self::$git !== null) {
            return self::$git ?: null;
        }
        try {
            $head = trim((string) @file_get_contents(base_path('.git/HEAD')));
            if (str_starts_with($head, 'ref: ')) {
                $ref = substr($head, 5);
                $hash = trim((string) @file_get_contents(base_path('.git/' . $ref)));
                if ($hash === '' && is_file(base_path('.git/packed-refs'))
                    && preg_match('/^([0-9a-f]{40}) ' . preg_quote($ref, '/') . '$/m', (string) file_get_contents(base_path('.git/packed-refs')), $m)) {
                    $hash = $m[1];
                }
                $head = $hash;
            }
            self::$git = preg_match('/^[0-9a-f]{40}$/', $head) ? $head : '';
        } catch (\Throwable) {
            self::$git = '';
        }

        return self::$git ?: null;
    }

    private static function push(string $list, array $entry): void
    {
        $bytes = strlen((string) json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        if (self::$seg['_bytes'] + $bytes > self::MAX_SEGMENT_BYTES) {
            self::reason('truncated');

            return;
        }
        self::$seg['_bytes'] += $bytes;
        self::$seg[$list][] = $entry;
    }

    private static function reason(string $why): void
    {
        if (self::$seg !== null && ! in_array($why, self::$seg['replay_reasons'], true)) {
            self::$seg['replay_reasons'][] = $why;
        }
    }

    /** @return array<string,array{0:mixed,1:mixed}> */
    private static function diff(array $a, array $b): array
    {
        $out = [];
        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $k) {
            $x = $a[$k] ?? null;
            $y = $b[$k] ?? null;
            if ($x !== $y || array_key_exists($k, $a) !== array_key_exists($k, $b)) {
                $out[$k] = [$x, $y];
            }
        }

        return $out;
    }

    /** Metni sınırda keser; kesildiyse iz tam yeniden oynatılamaz (truncated_text). */
    private static function cut(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        self::reason('truncated_text');

        return mb_substr($text, 0, $max) . '…';
    }

    /** Değer özeti. Her kısaltma (metin, eleman sayısı, derinlik) replay.complete=false yapar — kısaltılmış veri tam değildir. */
    private static function summarize(mixed $v, int $depth): mixed
    {
        if ($v === null || is_scalar($v)) {
            return is_string($v) ? self::cut($v, 20000) : $v;
        }
        if ($depth > 6) {
            self::reason('truncated_depth');

            return '…';
        }
        if ($v instanceof \Illuminate\Database\Eloquent\Model) {
            return ['@' => class_basename($v), 'id' => $v->getKey(), 'attrs' => $v->attributesToArray()];
        }
        if ($v instanceof \Illuminate\Support\Collection) {
            if ($v->count() > 200) {
                self::reason('truncated_items');
            }
            $items = $v->take(200)->map(fn ($x) => self::summarize($x, $depth + 1))->all();

            return ['@' => 'Collection', 'count' => $v->count(), 'items' => $items];
        }
        if (is_array($v)) {
            $out = [];
            $i = 0;
            foreach ($v as $k => $x) {
                if (++$i > 200) {
                    $out['…'] = count($v) - 200;
                    self::reason('truncated_items');
                    break;
                }
                $out[$k] = self::summarize($x, $depth + 1);
            }

            return $out;
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format(DATE_ATOM);
        }

        return ['@' => get_class($v)];
    }

    private static function caller(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60) as $f) {
            $class = $f['class'] ?? '';
            if ($class !== '' && $class !== self::class && str_starts_with($class, 'App\\') && ! str_starts_with($class, 'App\\Providers')) {
                return class_basename($class) . '::' . ($f['function'] ?? '?');
            }
        }

        return null;
    }

    private static function spent(int $start): void
    {
        if (self::$seg !== null) {
            self::$seg['overhead_ns'] += hrtime(true) - $start;
        }
    }

    private static function hook(string $where): void
    {
        if (self::$failHook !== null) {
            (self::$failHook)($where);
        }
    }

    private static function fail(\Throwable $e): void
    {
        try {
            if (self::$seg !== null) {
                self::reason('diagnostic_error');
                if (self::$seg['_failed']) {
                    return; // bölüm başına bir uyarı yeter
                }
                self::$seg['_failed'] = true;
            }
            self::warn('WhatsApp izi: teşhis hatası', $e);
        } catch (\Throwable) {
            // teşhis asla işi durdurmaz
        }
    }

    private static function warn(string $msg, \Throwable $e): void
    {
        try {
            Log::warning($msg, ['msg' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
        } catch (\Throwable) {
        }
    }
}
