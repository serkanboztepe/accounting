<?php

namespace App\Services\Whatsapp;

use App\Models\UsageLog;
use App\Models\WhatsappMessage;
use App\Support\Phone;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sohbet asistanı (deneme): tek mesajı form gibi doldurmak yerine son konuşmayı GÖRÜR ve araç kullanır —
 * kayıt öner / bekleyen kaydı onayla / vazgeç / giderlerde ara. Burak vakası (11 Ekim): "Verceyim … Yusuf
 * 67000" + "Sılaj aldım" — eski çıkarıcı ikinci mesajda yalnız kendi özetini görüp "ödenmiş gider" yazdı.
 *
 * Güvenlik yine kontrolcüde: kayıt yalnız onayla, özet her zaman gösterilir, kapalı modül kodda engellenir
 * (ExpenseExtractor::fitToModules). Bu sınıf hiçbir şey KAYDETMEZ; yalnız ne yapılacağını döndürür.
 */
class ChatAssistant
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const TIMEZONE = 'Europe/Istanbul';

    /** Konuşma geçmişi penceresi: eski konuşmanın carisi yeni mesaja sızmasın (bkz. ConversationContext). */
    // 17.10: 30 → 180 (Emre: 08:00 hatırlatmaya 10:21 "Ödendi" anlaşılmadı). Eski mesaj karışmasın diye her mesajda saat yazılı.
    public const HISTORY_MINUTES = 180;

    private const MAX_HISTORY = 16;

    /** Araç turu sınırı (arama → cevap); Twilio 15 sn bekler. */
    private const MAX_STEPS = 5;

    /** Okuma aracından sonraki AI turu için gereken süre; senkron sürede bu kadar kalmadıysa tur arka plana. */
    private const READ_TURN_SECONDS = 6;

    /** Faz 5: zor soru (karşılaştırma / neden / öneri) — firma başına günde en çok bu kadar büyük model turu. */
    private const DEEP_PER_DAY = 40;

    /** Bu soru büyük modele geçsin mi (günlük sınır içinde). */
    private static function allowDeep(): bool
    {
        $model = (string) config('services.anthropic.analysis_model');
        if ($model === '') {
            return false;
        }
        $key = \App\Tenancy\Tenancy::key('deep-turns:' . now()->toDateString());
        \Illuminate\Support\Facades\Cache::add($key, 0, now()->addDays(2));

        return \Illuminate\Support\Facades\Cache::increment($key) <= self::DEEP_PER_DAY;
    }

    public function __construct(private ExpenseExtractor $extractor) {}

    /**
     * Twilio cevabı ~15 sn bekler. Bu ana (microtime) kadar bitmeyecek tur ertelenir (DeferTurn): kişiye "bakıyorum"
     * gider, iş istek kapandıktan sonra süresiz sürer, sonuç ayrı mesajla gelir. null = süre sınırı yok (arka plan).
     */
    public static ?float $deadline = null;

    public static function enabled(): bool
    {
        // Firma bazında hub → Modüller → "Akıllı WhatsApp asistanı"; .env bayrağı yerel deneme için.
        return (bool) config('modules.smart_assistant') || (bool) config('services.anthropic.chat_assistant');
    }

    /**
     * @param  array|null  $pending  onay bekleyen taslak (extracted)
     * @return array{type:string,text?:string,data?:array,paid?:bool|null}
     *         type: reply (düz cevap) | entry (taslak öner) | confirm | cancel
     */
    public function respond(string $phone, string $text, ?array $pending, bool $fresh = false): array
    {
        $apiKey = config('services.anthropic.api_key');
        if (empty($apiKey)) {
            throw new RuntimeException('ANTHROPIC_API_KEY tanımlı değil (.env).');
        }

        $model = config('services.anthropic.chat_model') ?: config('services.anthropic.model');
        // $fresh: mesaj kendi başına tam bir kayıt (kasa) — geçmişteki soru ("Hangi şube?") ona cevap sanılmasın.
        // Geçmiş ve talimat bağlamının okumaları modele giden istekte saklanıyor (teşhis kaydı: TurnTrace).
        $messages = $fresh ? [['role' => 'user', 'content' => $text]] : TurnTrace::cover('chat.history', fn () => $this->history($phone, $text));
        $system = TurnTrace::cover('chat.systemPrompt', fn () => $this->systemPrompt($pending, $phone));

        $maxTokens = 1024;
        $deep = false;
        $claimRetried = false;
        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $timeout = 60;
            if (self::$deadline !== null) {
                $timeout = (int) floor(self::$deadline - microtime(true));
                if ($timeout < 2) {
                    throw new DeferTurn('süre doldu (adım ' . $step . ')');
                }
            }
            try {
                $response = $this->call($apiKey, $timeout, [
                    'model' => $model,
                    'max_tokens' => $maxTokens,
                    'system' => $system,
                    'tools' => $this->tools(),
                    // Her zaman bir araç: düz metinle kendi "özetini" yazıp taslaksız onay istiyordu (Nevzat, bal).
                    // Yeni modeller zorunlu aracı desteklemiyor (400) → serbest; düz metin "reply" sayılır.
                    'tool_choice' => ['type' => self::forcesTools((string) $model) ? 'any' : 'auto'],
                    'messages' => $messages,
                ]);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                if (self::$deadline !== null) {
                    throw new DeferTurn('yapay zekâ ' . $timeout . ' sn içinde cevap vermedi');
                }
                throw $e;
            }

            if ($response->failed()) {
                \App\Support\CreditAlert::fromApiError($response->body()); // kredi bittiyse yöneticiye hemen haber
            throw new RuntimeException('Anthropic API hatası: ' . $response->status() . ' ' . $response->body());
            }
            UsageLog::recordAi((string) $model, (array) $response->json('usage', []));

            $content = (array) $response->json('content', []);
            $said = trim(implode("\n", array_map(fn ($b) => $b['text'] ?? '', array_filter($content, fn ($b) => ($b['type'] ?? null) === 'text'))));
            $call = collect($content)->first(fn ($b) => ($b['type'] ?? null) === 'tool_use');

            if (! $call) {
                if (self::claimsDone($said)) {
                    if ($claimRetried) {
                        return ['type' => 'reply', 'text' => self::NOT_DONE_REPLY];
                    }
                    $claimRetried = true;
                    $messages[] = ['role' => 'assistant', 'content' => self::echoable($content)];
                    $messages[] = ['role' => 'user', 'content' => self::CLAIM_REJECTED];
                    continue;
                }

                return ['type' => 'reply', 'text' => $said !== '' ? self::whatsappFormat($said) : 'Tam anlayamadım, biraz daha açık yazar mısın?'];
            }

            $input = (array) ($call['input'] ?? []);
            TurnTrace::note('assistant_tool', $call['name']);
            switch ($call['name']) {
                case 'propose_entry':
                    $data = $this->extractor->normalizeEntry($input);
                    $data['is_new_entry'] = (bool) ($input['is_new_entry'] ?? true);
                    $data['confidence'] = 'high'; // yazılı tutar belirsiz değildir (eski çıkarıcıdaki gibi)
                    // Aynı mesajdaki kalan kalem(ler): onaydan sonra sıradaki taslak olarak önerilir.
                    // Kodun eklediği sıradaki kalem (ör. çok üründe "günde 16 gidiyor — hangisi?") silinmesin.
                    $data['next_items'] = trim(trim((string) ($input['next_items'] ?? '')) . ' ' . trim((string) ($data['next_items'] ?? ''))) ?: null;
                    // Kayıttan sonra "Hatırlayayım mı?" diye sorulacak kalıcı bilgi (Faz 2).
                    $ms = is_array($input['memory_suggestion'] ?? null) ? $input['memory_suggestion'] : [];
                    $data['memory_suggestion'] = ! empty($ms['fact']) && $data['kind'] !== ExpenseExtractor::KIND_REMEMBER
                        ? ['kind' => (string) ($ms['kind'] ?? 'note'), 'subject' => mb_substr((string) ($ms['subject'] ?? ''), 0, 120),
                            'fact' => mb_substr((string) $ms['fact'], 0, 500)]
                        : null;

                    return ['type' => 'entry', 'data' => $data, 'text' => $said];
                case 'confirm_pending':
                    // İki anahtar: AI "onay" dese de mesajda gerçek bir onay kelimesi yoksa KAYDETME
                    // ("Sılajcı yusuf" isim düzeltmesi onay sayılıp kaydedilmişti).
                    if (! self::hasAssent($text)) {
                        return ['type' => 'ask_confirm'];
                    }

                    return ['type' => 'confirm', 'paid' => isset($input['paid']) ? (bool) $input['paid'] : null];
                case 'cancel_pending':
                    // İki anahtar (onaydaki gibi): mesajda iptal sözü yoksa iptal ETME. Akkuş 15.10 21:51: açık taslak varken
                    // "Ergenekon kasa / Nakit 5900 / Pos 21540" iptal sanıldı, kasa kaydedilmedi. Mesaj yeni işlemse AI'a bir tur daha.
                    if (self::hasCancel($text)) {
                        return ['type' => 'cancel'];
                    }
                    $messages[] = ['role' => 'assistant', 'content' => self::echoable($content)];
                    $messages[] = ['role' => 'user', 'content' => self::toolResults($content, [$call['id'] => 'İPTAL EDİLMEDİ: kullanıcı iptal/vazgeç demedi. Bekleyen taslağa dokunma. Bu mesaj yeni bir işlemse '
                            . 'propose_entry (is_new_entry=true) ile öner; düzeltmeyse düzeltilmiş taslağı öner; değilse reply.'])];
                    break;
                case 'undo_last':
                    // Düzeltme: kaydın doğru hâli verildiyse tek onayla sil + yeniden yaz.
                    $replacement = is_array($input['replacement'] ?? null) && ! empty($input['replacement']['kind'])
                        ? ['confidence' => 'high'] + $this->extractor->normalizeEntry($input['replacement'])
                        : null;

                    // K3: son kayıt değil, tarif edilen eski kayıt ("kategorisiz olan 25.113", "14.10'daki gider").
                    $find = array_filter([
                        'amount' => isset($input['find_amount']) && (float) $input['find_amount'] > 0 ? (float) $input['find_amount'] : null,
                        'date' => is_string($input['find_date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['find_date']) ? $input['find_date'] : null,
                        'text' => is_string($input['find_text'] ?? null) && trim($input['find_text']) !== '' ? trim($input['find_text']) : null,
                        'party' => is_string($input['find_party'] ?? null) && trim($input['find_party']) !== '' ? trim($input['find_party']) : null,
                    ]);
                    // "son satışı" — aranan kişinin/tutarın en yeni kaydı (yalnız başka bir arama ölçütüyle birlikte).
                    if ($find && ! empty($input['find_latest'])) {
                        $find['latest'] = true;
                    }
                    // Birden çok aday çıkarsa mesajdaki sayı ("50 litre süt düş") hangisi olduğunu seçer (kodda; AI ara sıra vermiyor).
                    if ($find) {
                        $find['message'] = $text;
                    }

                    // Onay bekleyen taslak varken "son kaydı düzelt" = TASLAĞI düzelt (henüz kayıt yok). "Recep değil Hüseyin"
                    // ara sıra geri alma sanılıp "geri alacak kayıt yok" deniyordu, taslak eski kalıyordu (deneme seti 16.10).
                    if ($pending !== null && ! $find) {
                        if ($replacement !== null) {
                            return ['type' => 'entry', 'data' => ['is_new_entry' => false] + $replacement, 'text' => $said];
                        }
                        $messages[] = ['role' => 'assistant', 'content' => self::echoable($content)];
                        $messages[] = ['role' => 'user', 'content' => self::toolResults($content, [$call['id'] => 'Onay bekleyen TASLAK var (henüz kaydedilmedi). Kullanıcı taslağı düzeltiyorsa düzeltilmiş TAM kaydı '
                                . 'propose_entry (is_new_entry=false) ile öner; taslaktan vazgeçiyorsa cancel_pending. Gerçekten daha önce '
                                . 'KAYDEDİLMİŞ başka bir kaydı silmek istiyorsa undo_last\'u find_amount / find_date / find_text ile tekrar çağır.'])];
                        break;
                    }

                    return ['type' => 'undo', 'replacement' => $replacement, 'find' => $find ?: null];
                case 'review_answer':
                    return ['type' => 'review_ok', 'verdict' => ($input['verdict'] ?? null) === 'declined' ? 'declined' : 'correct', 'text' => $said];
                case 'return_items':
                    // İade (19.10): asıl kayıt durur, yanına iade yazılır. Yön bulunan kayıttan gelir; side yalnız ipucu.
                    $side = in_array($input['side'] ?? null, ['sale', 'purchase'], true) ? $input['side'] : null;

                    return ['type' => 'return', 'find' => array_filter([
                        'party' => is_string($input['find_party'] ?? null) && trim($input['find_party']) !== '' ? trim($input['find_party']) : null,
                        'text' => is_string($input['find_text'] ?? null) && trim($input['find_text']) !== '' ? trim($input['find_text']) : null,
                        'date' => is_string($input['find_date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['find_date']) ? $input['find_date'] : null,
                        'side' => $side,
                        'quantity' => isset($input['quantity']) && (float) $input['quantity'] > 0 ? (float) $input['quantity'] : null,
                        'amount' => isset($input['amount']) && (float) $input['amount'] > 0 ? (float) $input['amount'] : null,
                        'money_back' => ! empty($input['money_back']) ? true : null,
                    ], fn ($v) => $v !== null)];
                case 'reply':
                    // Düz cevapta yapılmamış işi "yaptım" demesin (Çiftlik 16.10: "zaten kaydettim, otomatik düşüyor" — ayar yoktu):
                    // bir tur daha (kayıt yalnız propose_entry ile); yine derse sabit, doğru cevap.
                    if (self::claimsDone((string) ($input['text'] ?? ''))) {
                        if ($claimRetried) {
                            return ['type' => 'reply', 'text' => self::NOT_DONE_REPLY];
                        }
                        $claimRetried = true;
                        $messages[] = ['role' => 'assistant', 'content' => self::echoable($content)];
                        $messages[] = ['role' => 'user', 'content' => self::toolResults($content, [$call['id'] => self::CLAIM_REJECTED])];
                        break;
                    }

                    return ['type' => 'reply', 'text' => self::whatsappFormat(trim((string) ($input['text'] ?? ''))) ?: 'Tam anlayamadım, biraz daha açık yazar mısın?'];
                case 'query_records':
                case 'search_conversations':
                    // Faz 5: karşılaştırma / neden / öneri → cevabı büyük model yazar (günlük sınır içinde).
                    if (! $deep && ! empty($input['deep']) && self::allowDeep()) {
                        $deep = true;
                        $model = (string) config('services.anthropic.analysis_model');
                        $maxTokens = 1500;
                    }
                    // Okuma bir tur daha ister: senkron sürede yeterli zaman yoksa arka plana bırak (kişiye "bakıyorum" gider).
                    // Büyük model daha yavaş: daha çok süre ister.
                    if (self::$deadline !== null && self::$deadline - microtime(true) < ($deep ? self::READ_TURN_SECONDS + 3 : self::READ_TURN_SECONDS)) {
                        throw new DeferTurn('okuma aracı' . ($deep ? ' (büyük model)' : ''));
                    }
                    // Salt okuma: sonucu modele ver, cevabı o yazsın (rakamlar sonuçtan, AI toplamaz). Aynı turda birden çok okuma
                    // (iki dönemi karşılaştırma) hepsi çalışır ve sonuçlar birlikte döner — her tool_use'a cevap şart (review D).
                    $results = [];
                    foreach ($content as $block) {
                        if (($block['type'] ?? null) === 'tool_use' && in_array($block['name'] ?? '', ['query_records', 'search_conversations'], true)) {
                            try {
                                // Sonuç modele araç sonucu olarak gider (teşhis kaydında istek gövdesinde).
                                $data = TurnTrace::cover('chat.' . $block['name'], fn () => $block['name'] === 'query_records'
                                    ? RecordsQuery::run((array) ($block['input'] ?? [])) : ConversationLookup::run((array) ($block['input'] ?? [])));
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::error('Okuma aracı hatası', ['tool' => $block['name'], 'msg' => $e->getMessage()]);
                                $data = ['hata' => 'Okuma yapılamadı; kullanıcıya şu an bakamadığını söyle.'];
                            }
                            $results[$block['id']] = json_encode($data, JSON_UNESCAPED_UNICODE);
                        }
                    }
                    $messages[] = ['role' => 'assistant', 'content' => self::echoable($content)];
                    $messages[] = ['role' => 'user', 'content' => self::toolResults($content, $results)];
                    break;
                default:
                    return ['type' => 'reply', 'text' => $said ?: 'Tam anlayamadım, biraz daha açık yazar mısın?'];
            }
        }

        return ['type' => 'reply', 'text' => 'Şu an toparlayamadım, birazdan tekrar dener misin? 🙏'];
    }

    /** WhatsApp biçimi: kalın tek yıldız (*x*) — model **x** / ## başlık yazarsa müşteri yıldızları görür. */
    public static function whatsappFormat(string $text): string
    {
        $text = preg_replace('/\*\*(.+?)\*\*/su', '*$1*', $text) ?? $text;

        return preg_replace('/^#{1,6}\s*(.+)$/mu', '*$1*', $text) ?? $text;
    }

    /**
     * Asistan mesajındaki HER tool_use için tool_result (API şartı — paralel çağrıda biri eksikse 400, review D).
     * Cevabı verilmeyen çağrıya "çalıştırılmadı" yazılır.
     *
     * @param  array<string,string>  $answers  tool_use id → sonuç metni
     */
    private static function toolResults(array $content, array $answers): array
    {
        $out = [];
        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'tool_use') {
                $out[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'],
                    'content' => $answers[$block['id']] ?? 'Bu çağrı bu turda çalıştırılmadı (aynı anda yalnız okuma araçları birlikte çalışır); gerekiyorsa tek başına tekrar çağır.'];
            }
        }

        return $out;
    }

    private function call(string $apiKey, int $timeout, array $payload): \Illuminate\Http\Client\Response
    {
        $request = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->connectTimeout(min(5, $timeout))->timeout($timeout);
        // Süre sınırlıyken tekrar deneme yok (zaman yetmez; arka planda yeniden denenir).
        if (self::$deadline === null) {
            $request = $request->retry(2, 300, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException, throw: false);
        }

        return $request->post(self::ENDPOINT, $payload);
    }

    /** claude-sonnet-5-5 / claude-opus-5-5 ve sonrası tool_choice any/tool kabul etmiyor. */
    /** Zorunlu araç seçimini (tool_choice any/tool) destekleyen eski modeller; yeni nesil (…-5-5, 6+) yalnız auto kabul eder. */
    public static function forcesTools(string $model): bool
    {
        return ! preg_match('/-5-5\b|-[6-9]\b|-[6-9]-/', $model);
    }

    /** Onay sayılan kelimeler — tek kelimelik cevap (kontrolcü) ve cümle içi (hasAssent) aynı listeyi kullanır. "0k" Erkan, 13 Ekim. */
    public const ASSENT_WORDS = ['evet', 'tamam', 'tamamdır', 'tamamdir', 'olur', 'kaydet', 'kaydedin', 'onay', 'onayla', 'onaylıyorum',
        'ok', '0k', 'okey', 'okay', 'tmm', 'e', 'yaz'];

    /** Düz cevapta asistanın kendi adına "yaptım" sözü — kayıt/ayar yalnız onaylı taslakla olur, düz cevap hiçbir şey yapmaz. */
    public static function claimsDone(string $text): bool
    {
        $lower = mb_strtolower(strtr($text, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');

        return (bool) preg_match('/(?<!\p{L})(kaydett?im|kayda geçtim|kayda geçirdim|ayarladım|ekledim|güncelledim|sildim|değiştirdim|işledim|'
            . 'not ettim|not aldım|not düştüm|girdim|tanımladım|kurdum|hallettim|düzelttim|açtım)(?!\p{L})/u', $lower);
    }

    private const CLAIM_REJECTED = 'GERİ ÇEVRİLDİ — kullanıcıya GİTMEDİ: cevabında bir şeyi yaptığını/kaydettiğini söyledin ama düz cevap HİÇBİR ŞEY '
        . 'kaydetmez; kayıt ve ayar yalnız propose_entry + kullanıcının onayıyla olur. Kullanıcı bir şeyin yapılmasını istiyorsa propose_entry '
        . 'ile öner (ör. "her gün düş" → stock_setting + stock_items[usage_amount]; miktarı bilmiyorsan question ile sor). Yalnız bilgi veriyorsan bağlamda '
        . '(ÜRÜNLER, CARİLER) GÖRDÜĞÜNÜ "yaptım" demeden anlat; bağlamda yazmayan ayar YOKTUR.';

    private const NOT_DONE_REPLY = 'Bunu henüz kaydetmedim. Ne yapmamı istediğini kısaca yazar mısın? (ör. "yem günde 16 adet gidiyor")';

    /** Çok kelimeli onay ("Ödendi kayıt et" — Oktay 16.10: onay sayılmayıp bir daha sorulmuştu). */
    public const ASSENT_PHRASES = ['kayıt et', 'kayit et', 'kayda geç', 'kayda gec', 'işle', 'isle'];

    /** "Doğru mu?" sorusuna teyit — yalnız kartta tutar uyarısı / kontrol sorusu varken onay sayılır (kontrolcü bakar). */
    public const CHECK_ASSENT_WORDS = ['doğru', 'dogru', 'doğrudur', 'dogrudur', 'aynen', 'evet'];

    public static function affirmsCheck(string $text): bool
    {
        $lower = mb_strtolower(strtr($text, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
        $words = preg_split('/[\s\p{P}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Kısa teyit ("Dogru", "evet doğru"); "doğru değil" / uzun düzeltme cümlesi sayılmaz.
        return $words !== [] && count($words) <= 3 && ! array_intersect($words, ['değil', 'degil', 'yanlış', 'yanlis', 'hayır', 'hayir'])
            && array_intersect($words, self::CHECK_ASSENT_WORDS) !== [];
    }

    /** Modelin cevabını geçmişe geri koyarken boş araç girdisi JSON'da {} olmalı ([] → API 400 "Input should be an object"). */
    private static function echoable(array $content): array
    {
        return array_map(function ($b) {
            if (($b['type'] ?? null) === 'tool_use' && (($b['input'] ?? []) === [] || ! isset($b['input']))) {
                $b['input'] = (object) [];
            }

            return $b;
        }, $content);
    }

    /** İptal sayılan sözler — AI "vazgeçiyor" dese de mesajda bunlardan biri yoksa iptal edilmez. */
    public const CANCEL_WORDS = ['iptal', 'vazgeç', 'vazgec', 'vazgeçtim', 'vazgectim', 'gerek yok', 'boşver', 'bosver', 'boş ver',
        'sil', 'silin', 'kaydetme', 'istemiyorum', 'hayır', 'hayir', 'olmasın', 'olmasin', 'yanlış', 'yanlis', 'kalsın', 'kalsin'];

    public static function hasCancel(string $text): bool
    {
        $lower = mb_strtolower(strtr($text, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
        foreach (self::CANCEL_WORDS as $w) {
            if (preg_match('/(^|[^\p{L}])' . preg_quote($w, '/') . '/u', $lower)) {
                return true;
            }
        }

        return false;
    }

    /** Mesajda onay kelimesi var mı: evet / tamam / ok / olur / kaydet / onay — ya da tek harf yazım hatası ("evert"). */
    public static function hasAssent(string $text): bool
    {
        $lower = mb_strtolower(strtr($text, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
        foreach (self::ASSENT_PHRASES as $phrase) {
            if (preg_match('/(^|[^\p{L}])' . preg_quote($phrase, '/') . '([^\p{L}]|$)/u', $lower)) {
                return true;
            }
        }
        $words = preg_split('/[\s\p{P}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $w) {
            foreach (self::ASSENT_WORDS as $yes) {
                if ($w === $yes || (mb_strlen($yes) >= 4 && mb_strlen($w) >= 3 && levenshtein($w, $yes) <= 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Son konuşma (in → user, out → assistant). Gelen mesaj kontrolcüde zaten kaydedildiği için
     * geçmişin sonundaki aynı metin atlanır, mesaj en sona eklenir.
     *
     * @return list<array{role:string,content:string}>
     */
    private function history(string $phone, string $text): array
    {
        $rows = WhatsappMessage::where('phone', Phone::normalize($phone))
            ->where('created_at', '>=', now()->subMinutes(self::HISTORY_MINUTES))
            ->whereNotNull('body')
            ->orderByDesc('id')
            ->limit(self::MAX_HISTORY + 1)
            ->get()
            ->reverse()
            ->values();

        $last = $rows->last();
        if ($last && $last->direction === 'in' && trim((string) $last->body) === $text) {
            $rows->pop();
        }

        $messages = [];
        foreach ($rows as $row) {
            $role = $row->direction === 'in' ? 'user' : 'assistant';
            // Saat: asistan eski konuşmanın şimdiki mesajla ilgili olup olmadığına kendisi karar versin.
            $this->append($messages, $role, '[' . $row->created_at->copy()->timezone(self::TIMEZONE)->format('H:i') . '] ' . (string) $row->body);
        }
        $this->append($messages, 'user', $text);

        // API: ilk mesaj kullanıcıdan olmalı.
        while ($messages !== [] && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }

        return $messages;
    }

    private function append(array &$messages, string $role, string $body): void
    {
        $body = trim($body);
        if ($body === '') {
            return;
        }
        $n = count($messages);
        if ($n > 0 && $messages[$n - 1]['role'] === $role) {
            $messages[$n - 1]['content'] .= "\n\n" . $body;
        } else {
            $messages[] = ['role' => $role, 'content' => $body];
        }
    }

    /** @return list<array{type:string,text:string,cache_control?:array}> sistem blokları (önbellek katmanları) */
    private function systemPrompt(?array $pending, ?string $phone = null): array
    {
        $now = now(self::TIMEZONE)->locale('tr');
        $today = $now->format('Y-m-d') . ' (' . $now->translatedFormat('l') . ', saat ' . $now->format('H:i') . ')';
        $kinds = ExpenseExtractor::kindGuide();
        $context = ExpenseContext::build($phone);
        // Stok kartı kodun iç türüdür; AI onu sayım olarak görür (düzeltmede stock_items ile yeniden önerir).
        if ($pending !== null && ($pending['kind'] ?? null) === ExpenseExtractor::KIND_STOCK_UPDATE) {
            $pending['kind'] = ExpenseExtractor::KIND_STOCK_COUNT;
            $pending['stock_items'] = array_map(fn ($r) => array_diff_key($r, ['new' => 1, 'similar' => 1]), (array) ($pending['stock_items'] ?? []));
        }
        $draft = $pending !== null
            ? json_encode(array_intersect_key($pending, array_flip([
                'kind', 'amount', 'description', 'date', 'due_date', 'party_id', 'party_name', 'project_id', 'project_name',
                'category_id', 'category_name', 'paid', 'debt_side', 'payment_purpose', 'items', 'payment_type',
                // Stoklu satış taslağı düzeltilirken bunlar kaybolmasın (nakit/kart, sayım, ürün ayarı).
                'product_id', 'cash_amount', 'card_amount', 'counted_quantity', 'daily_usage', 'reorder_level', 'month',
                'purchase_price', 'product_name', 'unit', 'next_items',
                // Önce anla: sorulan soru ve anlaşılanlar (cevap gelince aynı kayıt tamamlanır).
                'question', 'question_about', 'meaning', 'work_status', 'work_status_known', 'party_certain', 'checks', 'check_plan', 'stock_items',
                // Anlaşma taslağı (sözleşme fotoğrafı): düzeltmede kalemler/ödemeler görünsün.
                'agreement_direction', 'agreement_items', 'agreement_payments', 'has_payments', 'document_total', 'total_ok', 'agreement_notes', 'party_phone', 'party_address', 'new_projects', 'remaining_only', 'unreadable', 'delivery_lines', 'delivery_candidates',
            ])), JSON_UNESCAPED_UNICODE)
            : 'yok';
        $modules = [];
        if (! config('modules.expenses')) {
            $modules[] = '- Harcama (gider) takibi KAPALI: kişisiz harcamayı kaydedemezsin; birinden mal/hizmet alındıysa "purchase".';
        }
        if (! config('modules.cari_supplier')) {
            $modules[] = '- Tedarikçi tarafı KAPALI: yalnız müşteri tarafı (yaptığımız işler, bize gelen paralar, alacaklar) tutulur; birine ödeme ya da birinden alış kaydedilemez — bunu söyle.';
        }
        // Fiilsiz mesajın varsayılan anlamı işletmeye göre (eski çıkarıcıdaki kural): müteahhitte maliyet, mimarda satış.
        $modules[] = config('modules.expenses')
            ? '- Fiil YOKSA ("Abdullah Uçar 2 milyon kaba inşaat", "Ahmet 50 bin sıva") → yaptırdığımız iş = "expense". "sale" yalnız açık fiille ("yaptım", "sattım", "fatura kestim").'
            : '- Fiil YOKSA ya da "… ekle / … yaz" ise ("Bahadır Pir 20000 EKB belgesi ekle", "Ali Bey 80 bin proje") → müşteriye yaptığımız iş = "sale".';
        // Stoklu firmada "stok tutamıyorum" demesin (Çiftlik, 15 Ekim: "elimde ne kadar saman var" reddedildi).
        $stock = in_array(ExpenseExtractor::KIND_STOCK_QUERY, ExpenseExtractor::allowedKinds(), true);
        if ($stock) {
            $modules[] = '- Stok takibi AÇIK: "ne kadar kaldı" (stock_query), "elimde X var" (stock_count), günlük kullanım / "kaç kalınca haber ver" (stock_setting), ürün tanıtma (product_add) kaydedilir — "stok tutamıyorum" deme. Üretim girişi ("ürettim", "sağdım", "kaldırdım") henüz yok: elde kalan miktarı söylemesini iste, sayım (stock_count) olarak kaydet.';
        }
        // Kartı olmayan mal alışında stok sorusunu KOD sorar (StockChoice, 21.10 Akkuş "börek unu ödemesi" stoğa girmedi).
        if ($stock && config('modules.purchases')) {
            $modules[] = '- Alış ya da ödeme TEK bir mal içinse (un, yağ, susam, şeker, lastik…) `goods_name` alanına malın adını yaz — adet/fiyat söylenmese de, cari olsun olmasın, gider olarak yazsan bile ("25.000 börek unu ödemesi yapıldı" → "Börek unu"; "3000 tl susam aldım nakit" → "Susam"; "Tokgöz\'e yağ parası verdim" → "Yağ"). Hizmet, işçilik, nakliye, kira, fatura, yakıt, yemek ve karışık market/toptancı alışverişinde null. Malın nerede olduğunu ya da kaç tane geldiğini SEN sorma — kod numaralı soruyla sorar.';
        }
        if (config('modules.agreements')) {
            // 21.10: anlaşma kayıttır (kart, kalan); hafızadaki "X'le Y'den anlaştık → remember" kuralını bu hesapta ezer.
            $modules[] = '- ANLAŞMALAR AÇIK: cariyle ileride gelecek mal / yapılacak iş için fiyat ya da tutar kararlaştırıldıysa ("Ali Usta\'yla '
                . 'tesisatı 1 milyona anlaştık", "Kuşakla 500 m³ betonu 3800\'den anlaştık", "tuğlanın adedini 8,5\'ten anlaştık") → propose_entry '
                . 'kind "agreement" (remember DEĞİL): agreement_items + anlaşmayla birlikte verilen peşinat/çek/senet agreement_payments. Mal geldi / '
                . 'iş yapıldı deniyorsa agreement değil. Anlaşma taslağında okunamayan yer soruldu ve kullanıcı cevapladıysa düzeltilmiş taslağı '
                . 'öner (değişen kalemi yaz, `unreadable`\'dan çıkar). Bağlamdaki AÇIK ANLAŞMALAR\'dan mal geldi / iş yapıldı / teslim '
                . 'edildi → kind "agreement_delivery" (delivery_lines); soruya cevap ("44,5", "yarısı") gelince aynı teslimatı miktarıyla öner. '
                . 'Anlaşmanın durumu sorulursa ("fayansta ne kaldı", "Ali Usta\'yla ne durumdayız") → propose_entry kind "agreement_query" '
                . '(rakamları sen hesaplama, sistem cevaplar).';
        }
        $modules[] = '- Listede olmayan kişi için yeni cari SEN açabilirsin: `party_name` yaz, onayla birlikte açılır. "Cariye ekle" bunu ister — "panelden ekle" deme.';
        $cannot = $stock ? 'hayvan sayımı, hayvan listesi' : 'hayvan sayımı, stok listesi';
        $understand = Understanding::RULES;
        $modules = "BU HESAPTA:\n" . implode("\n", $modules) . "\n";

        $rules = <<<PROMPT
        Sen Hesap Asistanım'sın: esnafın WhatsApp'tan yazdığı alış, satış, ödeme, tahsilat, borç-alacak ve
        harcamaları kayda geçiren asistan. Kullanıcı hızlı ve yazım hatalarıyla yazar ("verceyim" = vereceğim,
        "alcakgım" = alacağım, "evert" = evet, "ekstra/extre/ekistre/döküm/Ali pdf" = ekstre). Ne demek istediğini konuşmanın bütününden anla.
        Türkçe, kısa ve samimi yaz — WhatsApp mesajı gibi, en fazla 2-3 cümle. Bugünün tarihi ve saati en altta (ŞU AN).

        NASIL ÇALIŞIRSIN
        - Konuşma geçmişini oku; kullanıcının önceki mesajları bağlamdır. Ama her yeni işlem kendi başınadır:
          önceki işlemin kişisi/tutarı yalnız kullanıcı açıkça ona dönüyorsa ("ona", "bunu", "aynı kişi") taşınır.
        - Her mesajda BİR araç çağır. Bir işlemi kaydetmek için `propose_entry`: özeti ve "kaydedeyim mi?"
          sorusunu SİSTEM yazar — sen özet metni YAZMA (geçmişteki özetler sistemindir, taklit etme).
          `question` YALNIZ gerçekten eksik/şüpheli bir bilgi için (ör. yön belirsiz); "onaylıyor musun?",
          "yeni cari açayım mı?" gibi soru YAZMA — onayı sistem zaten soruyor.
          Düz mesaj (soru, bilgi, yapamadığın şey) → `reply`. Sen ASLA "kaydettim" deme.
        - HAFIZA: bağlamdaki "İŞLETME HAFIZASI" kullanıcının sana öğrettikleridir — UY (takma adı cariye eşle, anlaşılan
          fiyatı kullan, tercihe göre yaz). Yeni kalıcı bilgi öğretilirse ("bundan sonra…", "unutma…", "X'le Y'den anlaştık")
          → propose_entry kind "remember" (sistem "Hatırlayayım mı?" diye sorar). Gelecekteki kayıtların NASIL yazılacağına dair
          kalıcı istek de öğretmedir ("Kaya Petrol fişlerinden plakayı da kaydet", "açıklamaya hep … yaz") → remember (preference);
          "yapamıyorum" DEME — hafızadaki tercihe sonraki kayıtlarda uyarsın. Tek kayda yönelikse ("BU fişe plakayı ekle") düzeltmedir.
          Hafıza KENDİLİĞİNDEN İŞ YAPMAZ: "her gün / her ay … ekle, düş, kaydet, hatırlat" hafıza notu DEĞİLDİR — stoktan günlük
          düşme → stock_setting (stock_items); her gün/hafta/ay aynı GİDER → recurring_expense (gider takibi açıksa); tarihli tek
          hatırlatma → reminder; hafızaya "her gün kaydedilecek" diye söz YAZMA.
          Kendin "hatırlarım / not aldım" DEME;
          kaydı sistem onayla yapar. İşlem mesajında kalıcı bir bilgi geçiyorsa (kişi düzeltmesi "kalfa değil, Mehmet Kaya";
          anlaşma "280'den anlaştık") işlemi öner ve `memory_suggestion` doldur — işlem kaydolunca sistem sorar.
        - Bir İŞLEM için eksik bilgi (tutar, kişi, yön) soracaksan `reply` KULLANMA: `propose_entry` ile bildiğin
          alanları doldur, tutar bilinmiyorsa `amount`=0, soruyu `question`'a yaz ("Doktor'a verdiğin peynir, kavurma
          ve mumbarın toplamı ne kadar?"). Böylece taslak açılır, kullanıcının cevabı ona bağlanır; `reply` ile
          sorarsan kayıt kaybolur. Türü tutar varmış gibi aynı kurallarla seç ("X'e yem verdim" = sale, "X'ten un aldım" = purchase).
        - Bizim sorduğumuz kayıt kontrol sorusuna ("Bir kaydınızı kontrol etmek istiyorum: …") cevap gelir ve kayıt YANLIŞ çıkarsa:
          `undo_last` + `find_date` / `find_amount` / `find_text` ile o kaydı bul ve `replacement` ile doğru hâlini öner (müşteri onaylar).
          Kayıt doğruysa kısa teşekkür et, bir şey değiştirme.
        - "Fotoğrafını / fişi / dekontu atıyorum, gönderiyorum, birazdan atacağım" = belge BİRAZDAN gelecek: kısa `reply` ile
          "Tamam, bekliyorum" de; "göremedim / takılmış" DEME, kayıt önerme (belge gelince birlikte okunur).
        - Bekleyen taslak YOKKEN kısa bir "ödendi / tamam / yaptım / verdim" gelirse: "BUGÜN SENİN KENDİLİĞİNDEN GÖNDERDİKLERİN"e ve
          konuşmaya bak — tek aday varsa o işin kaydını öner (maaş hatırlatması → o kişinin `salary`'si, kira → gider, borç/vade → ödeme;
          tutar hatırlatmadaki). Birden çok aday varsa "Hangisi: X mi, Y mi?" diye sor. "Neyin ödendiğini anlayamadım" DEME.
          Mesajların başındaki [SS:DD] saattir; saatlerce önceki konuşma yeni işleme karışmasın.
        - Bekleyen taslak varken kullanıcı onaylıyorsa ("evet", "tamam", "ok", "olur", "kaydet", "evert",
          "Evet gider yaz") → `confirm_pending`. Onayla birlikte ödeme durumu söylediyse ("Ödendi evet") `paid` ver.
        - Kullanıcı taslakta bir şeyi düzeltiyorsa ("ödendi", "tutar 5000", "kategori çobanlar", "adı Silajcı
          Yusuf", "kg 600") → düzeltilmiş TAM kaydı `propose_entry` ile yeniden öner (`is_new_entry`=false).
          Vazgeçiyorsa → `cancel_pending`.
        - Para yönünü ve ödenip ödenmediğini KULLANICININ KENDİ SÖZLERİNDEN çıkar, önceki taslağı doğru sayma:
          "vereceğim / verecem / ödeyeceğim" → para henüz VERİLMEDİ (borç, ödenmedi); "verdim / ödedim" → verildi;
          "X'ten borç aldım" → para bize geldi, biz X'e borçlandık. Emin değilsen tahmin etme, kısa bir soru sor.
        - Miktar ve birim fiyat varsa çarp (34 kg × 600 = 20.400) ve açıklamaya yaz.
        - Tek mesajda birden fazla kalem varsa ("5400 parça, 10.000 işçilik"): satışta `items` kullan; diğer
          türlerde İLK kalemi öner ve kalanları `next_items`'a yaz — kullanıcı onaylayınca sistem sıradakini sorar.
        - TAKAS / iki yönlü işlem: aynı kişiden hem bir şey ALDIK hem ona bir şey VERDİK ("yemleme aleti aldım 12 bin,
          5 kilo peynir verdim 600'den, hesaptan düşecek") → ilk kalemi öner, diğerini MUTLAKA `next_items`'a yaz
          ("Recep'e 5 kg peynir verdim × 600 = 3.000"). İkisini tek kayıtta birleştirme, birini atlama.
        - Taslak düzeltilirken mesajda taslakta OLMAYAN yeni bir işlem varsa ("5 kilo peynirin parasını almadım 600'den")
          onu da kaybetme: taslağı düzelt ve yeni işlemi `next_items`'a ekle (taslaktaki mevcut `next_items`'ı koru, sonuna ekle).
        - Açıklamayı (`description`) kullanıcının söylediği işten kısa yaz; söylemediği bilgi UYDURMA
          ("önceki dönemden", "devreden" gibi). "alacağım / alınacak" → o bize borçlu; yön belli, sorma.
        - Onay bekleyen TASLAK varken "X değil Y", "tutar yanlış" → taslağı düzelt (`propose_entry`, is_new_entry=false);
          `undo_last` yalnız KAYDEDİLMİŞ işlem içindir.
        - KAYDEDİLMİŞ son işlemi silmek ("son kaydı sil") → `undo_last`. DÜZELTMEK ("bunu satış olarak değiştir",
          "8000 değil 10000 olacaktı", "kişi Ali değil Veli") → `undo_last` + `replacement` = kaydın doğru tam hâli
          (geri kalan her şey önceki kayıttaki gibi). Sistem ikisini gösterip tek onay ister. SON kayıt değil de DAHA ESKİ
          bir kayıt tarif ediliyorsa ("kategorisiz olan 25.113 yanlış", "14.10'daki gideri sil", "Ergenekon gideri yanlış") → yine
          `undo_last` + `find_amount` / `find_date` / `find_text` (sistem bulup gösterir). Kayıt bilgisi istemek için `reply` KULLANMA.
        - Kullanıcının söylediğini GENEL BİLGİYLE SORGULAMA ("fırın süt satmaz", "bu mantıklı değil" DEME — Oktay 18.10): CARİLER,
          hafıza ve geçmiş kayıtlar esastır; anlamadıysan kibarca ne demek istediğini sor. Kaydedilmiş bir işlemi kişiyle anıp
          "düş / iptal et / sil / yanlış oldu / saymayalım" diyorsa ("Akkuş fırından 50 litre süt düş",
          "Akkuş'a giden son satışı iptal et") → `undo_last` + `find_party` (+ `find_amount` / `find_text` / `find_latest`); sistem kaydı
          bulup gösterir, kullanıcı onaylar.
        - İADE ≠ İPTAL: kayıt DOĞRUYDU ama mal sonradan geri geldi / geri gitti ("Ahmet'e sattığım yemin 3 çuvalını geri aldım",
          "müşteri 2 kasayı geri getirdi", "Oktay'dan aldığım sütün 20 litresini geri verdim", "bozuk çıktı, tedarikçiye iade ettim")
          → `return_items` (kayıt silinmez, iade yazılır; miktar söylenmediyse null = tamamı). Kayıt YANLIŞ girilmişse / hiç
          olmadıysa ("iptal et", "sil", "yanlış girdim", "öyle bir satış yok") → `undo_last`. Onay bekleyen iade kartına ek bilgi
          ("parası verildi", "4 çuvaldı") → `return_items`'ı aynı kayıtla, düzeltilmiş alanlarla tekrar çağır.
        - Kategoriyi her kayıt için KENDİ içeriğine göre seç; önceki kaydın kategorisini taşıma (tosun/hayvan alımı ≠ "Çobanlar").
        - OKUMA (kayıtlara bakıp cevap): liste / ayrıntı / karşılaştırma / "en son" / "kime" / "hangi" / "neler" / "neden"
          soruları → `query_records` ("kime ne kadar ödedim" = types odeme + group_by party; "un en son kimden kaçtan" =
          product_id + types alis + limit 1; "bugünkü cirolar neler" = types satis + bugün + group_by project; "traktöre ne
          gitti" = text "trakt" + types gider,alis; "bu ay neden eksi" = önce bu ayın gider+alış+maaş'ını amount_desc ile oku,
          en büyük kalemleri ve şüpheli olanları (ör. "çek ödemesi" gider yazılmış) açıkla). Eski konuşma / "bu kayıt nereden
          çıktı" → `search_conversations` (ref ile). Cevapta rakamları ARAÇ SONUCUNDAN aynen al, kendin toplama/hesap yapma;
          kısa yaz (en çok ~8 satır), "toplam X kayıt" de; bulunamadıysa açıkça söyle, uydurma. Genel sorularda
          ("ne almışım", "neler sattım", "giderlerim neler") geri soru SORMA: son 30 günün kayıtlarını oku ve listele.
          Araç sonucu boşsa "hiç yok" deme — "bu süzgeçle bulamadım" de (başka türde/tarihte olabilir).
          Saat 00:00–05:00 arası "bugün" = biten iş günü (önceki gün): esnaf gün sonu kasasını gece girer. Cevapta "bugünkü" deme,
          günü tarihiyle yaz: "Dünkü (21 Ekim) ciro: …" (Akkuş 22.10 00:37: 21 Ekim cirosuna "Bugünkü toplam ciro" denmişti).
          "Kaç gün / kaç günlük?" → takvimden HESAPLAMA: araç sonucundaki `kayıt_günleri` ya da sonuçtaki "📅 Satış girilen gün"
          satırıyla cevap ver (Akkuş 18.10: 5 günlük ciroya "1 Ekim'den bugüne 18 günlük" denmişti).
          ZOR SORU (karşılaştırma "geçen haftayla", "hangi şube daha çok", eğilim, "neden", "ne yapmalıyım") → `deep`=true;
          gerekirse iki dönemi ayrı ayrı oku. Cevap: önce net sonuç (1 cümle), sonra 2-4 madde gerekçe rakamlarla,
          sonda varsa 1 kısa öneri. Rakamları araçtan al; yüzde hesaplayacaksan iki rakamı da yaz.
        - Basit tek rakam soruları eskisi gibi `propose_entry` ile: bakiye, toplam alacak/borç, ekstre, cari listesi, takvim,
          hatırlatma, "bu ay ne harcadım" (expense_summary), "ciro ne kadar / ay sonucu" (month_result).
        - Konusu yazılmamış kısa takip sorusu ("genel toplam", "toplamı", "hepsi", "ya dün?", "bu ay?") SON KONUŞULAN konunun
          devamıdır (Akkuş 22.10: ciro konuşulurken "Genel toplam" → alacak toplamı verildi, ciro istenmişti): az önce ciro
          konuşulduysa ciro (month_result), gider konuşulduysa gider (expense_summary), alacak/borç konuşulduysa totals_query.
          Son konuşma yoksa ya da konu belli değilse TAHMİN ETME, kısa `reply` ile sor: "Ciro toplamı mı, alacak-borç toplamı mı?".
        - Ekstre (kişinin hesap dökümü) PDF olarak gönderilir; "PDF" isteğinde hangi kişinin ekstresi olduğunu sor.
        - Yapamadığın bir şeyse ({$cannot}…) kibarca söyle ve yapabildiğin bir örnek ver.

        {$understand}
        - Soru sorduğun taslağa kullanıcının cevabı gelince ("daha takılmadı", "işi bitirdi", "anlaştık") aynı kaydı cevaba göre
          `work_status` + `work_status_known`=true ile yeniden öner (is_new_entry=false). Cevap da belirsizse ("asansör bedeli")
          known=false bırak ve soruyu başka sözlerle tekrar sor.

        İŞLEM TÜRLERİ (`kind`):
        {$kinds}
        {$modules}
        PROMPT;

        // Önbellek (prompt caching): üç katman, değişmeyenden sık değişene. Anthropic işarete kadar olan
        // metni harfi harfine karşılaştırır — aynıysa %10 fiyatla okur, farklıysa kendiliğinden yeniden yazar
        // (deploy / modül değişikliği elle güncelleme istemez). Saat ve taslak EN SONDA: her dakika değişir.
        //   1) kurallar + işlem türleri (+ araçlar): aynı modül ayarındaki firmalar paylaşır
        //   2) firmanın listeleri (cari, kategori, ürün): yeni cari eklenene kadar aynı
        //   3) şu an + bekleyen taslak: önbelleksiz
        return [
            ['type' => 'text', 'text' => $rules, 'cache_control' => ['type' => 'ephemeral']],
            ['type' => 'text', 'text' => trim($context), 'cache_control' => ['type' => 'ephemeral']],
            ['type' => 'text', 'text' => "ŞU AN: {$today}\n\nBEKLEYEN TASLAK (kullanıcıya özeti gösterildi, onay bekliyor): {$draft}"
                . self::todaysOutbound($phone)
                . ($phone && ($review = \App\Models\RecordReview::activeFor($phone)) ? $review->promptBlock() : '')],
        ];
    }

    /** Bizim kendiliğimizden gönderdiğimiz bugünkü mesajlar (hatırlatma, soru, uyarı) — saatler sonra gelen "Ödendi" bunlara cevap olabilir. */
    public const OUTBOUND_KINDS = ['reminder', 'due_reminder', 'stock_reminder', 'data_question', 'lesson_question', 'record_review', 'draft_followup'];

    private static function todaysOutbound(?string $phone): string
    {
        if (! $phone) {
            return '';
        }
        $start = now(self::TIMEZONE)->startOfDay()->timezone(config('app.timezone'));
        $rows = WhatsappMessage::where('phone', Phone::normalize($phone))->where('direction', 'out')
            ->whereIn('kind', self::OUTBOUND_KINDS)->where('created_at', '>=', $start)->orderBy('id')->limit(6)->get();
        if ($rows->isEmpty()) {
            return '';
        }

        return "\n\nBUGÜN SENİN KENDİLİĞİNDEN GÖNDERDİKLERİN (kısa bir \"ödendi / tamam / yaptım\" bunlardan birine cevap olabilir):\n"
            . $rows->map(fn ($r) => '  [' . $r->created_at->copy()->timezone(self::TIMEZONE)->format('H:i') . '] ' . mb_substr(str_replace("\n", ' ', (string) $r->body), 0, 220))->implode("\n");
    }

    private function tools(): array
    {
        $entry = $this->extractor->entrySchema();
        $entry['properties']['next_items'] = [
            'type' => ['string', 'null'],
            'description' => 'Kullanıcının aynı mesajında bu kayıttan SONRA kaydedilecek başka kalem(ler) varsa onların kullanıcı sözleriyle kısa tarifi ("10.000 TL traktör ustalık parası ödedim"); yoksa null.',
        ];
        $entry['properties']['memory_suggestion'] = [
            'type' => ['object', 'null'],
            'description' => 'İşlem mesajında KALICI bir bilgi geçiyorsa (kişi düzeltmesi "kalfa değil Mehmet Kaya", anlaşma "280\'den anlaştık", "bundan sonra"): kind alias|agreement|price|preference|note, subject, fact. Yoksa null.',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => ['alias', 'agreement', 'price', 'preference', 'note']],
                'subject' => ['type' => 'string'],
                'fact' => ['type' => 'string'],
            ],
        ];
        $entry['properties']['is_new_entry'] = [
            'type' => 'boolean',
            'description' => 'Bekleyen taslağın düzeltilmiş hâli ise false; ayrı, yeni bir işlem ise true.',
        ];

        $tools = [
            [
                'name' => 'reply',
                'description' => 'Kullanıcıya düz mesaj: soru sor, bilgi ver, yapamadığını söyle. ASLA kayıt özeti ya da "kaydedeyim mi?" yazma — kayıt için propose_entry.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['text' => ['type' => 'string', 'description' => 'Kısa WhatsApp mesajı (en fazla 2-3 cümle)']],
                    'required' => ['text'],
                ],
            ],
            [
                'name' => 'propose_entry',
                'description' => 'Bir işlemi (ya da soruyu: bakiye, toplam, ekstre, gider özeti, takvim, hatırlatma) sisteme ver. Kayıt türlerinde kullanıcıya özet gösterilir ve onay istenir; sorular hemen cevaplanır.',
                'input_schema' => $entry,
            ],
            [
                'name' => 'confirm_pending',
                'description' => 'Kullanıcı bekleyen taslağı ONAYLIYOR. Yalnız bekleyen taslak varken ve kullanıcı açıkça kabul ediyorsa.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'paid' => ['type' => ['boolean', 'null'], 'description' => 'Onayla birlikte ödeme durumu söylendiyse ("Ödendi evet" → true)'],
                    ],
                ],
            ],
            [
                'name' => 'undo_last',
                'description' => 'Kullanıcı KAYDEDİLMİŞ son işlemi silmek / geri almak ya da DÜZELTMEK istiyor ("geri al", "son kaydı sil", "yanlış girdim", "bunu satış yap", "8000 değil 10000"). Tek başına "geri al" = son kaydı geri al (iade DEĞİL). Düzeltmede `replacement` ile kaydın DOĞRU TAM hâlini ver; sistem eskisini ve yenisini gösterip tek onayla değiştirir.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'find_amount' => ['type' => ['number', 'null'], 'description' => 'Yalnız SON kayıt değilse: aranan kaydın tutarı (ör. 25113)'],
                        'find_date' => ['type' => ['string', 'null'], 'description' => 'Yalnız SON kayıt değilse: aranan kaydın tarihi YYYY-MM-DD'],
                        'find_text' => ['type' => ['string', 'null'], 'description' => 'Açıklamadaki bir kelime ya da mesajdaki miktar (ör. "süt", "Ergenekon", "50 litre" — "50 litre süt düş" → "50 litre")'],
                        'find_party' => ['type' => ['string', 'null'], 'description' => 'Kaydın kişisi (CARİLER\'deki ad, ör. "Akkuş Fırın") — "Akkuş\'a giden satış", "Recep\'ten alış"'],
                        'find_latest' => ['type' => ['boolean', 'null'], 'description' => 'true: "son / en son / az önceki" — bulunanlardan en yenisi'],
                        'replacement' => [
                            'type' => ['object', 'null'],
                            'description' => 'Yalnız düzeltmede: kaydın doğru hâli (propose_entry ile aynı alanlar). Önceki kayıttan kişi, tutar, açıklama, tarih KORUNUR; yalnız istenen değişir. Sadece silme isteniyorsa null.',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'return_items',
                'description' => 'İADE: satılan mal geri geldi ("Ahmet\'e sattığım yemin 3 çuvalını geri aldım", "müşteri 2 kasa geri getirdi") ya da alınan mal tedarikçiye geri verildi ("Oktay\'dan aldığım sütün 20 litresini geri verdim", "bozuk çıktı, iade ettim"). Asıl kayıt SİLİNMEZ, yanına iade yazılır; sistem kaydı bulup kartla onay ister. Kayıt yanlış girilmiş / hiç olmamışsa (iptal, sil, yanlış girdim) bu değil → undo_last.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'find_party' => ['type' => ['string', 'null'], 'description' => 'Kime satılmıştı / kimden alınmıştı (CARİLER\'deki ad)'],
                        'find_text' => ['type' => ['string', 'null'], 'description' => 'Mal adı ya da açıklamadaki kelime (ör. "yem", "süt")'],
                        'find_date' => ['type' => ['string', 'null'], 'description' => 'Asıl satış/alış tarihi söylendiyse YYYY-MM-DD'],
                        'side' => ['type' => ['string', 'null'], 'enum' => ['sale', 'purchase', null], 'description' => 'sale: bizim SATTIĞIMIZ mal geri geldi ("geri aldım", "geri getirdi"); purchase: bizim ALDIĞIMIZ mal geri gitti ("geri verdim", "tedarikçiye iade"). Belirsizse null.'],
                        'quantity' => ['type' => ['number', 'null'], 'description' => 'Geri gelen/giden miktar (ör. 3 çuval → 3). Söylenmediyse null (tamamı).'],
                        'amount' => ['type' => ['number', 'null'], 'description' => 'İade tutarı TL, yalnız söylendiyse'],
                        'money_back' => ['type' => ['boolean', 'null'], 'description' => 'true: parası da geri verildi/alındı ("parasını geri verdim", "parayı iade etti")'],
                    ],
                ],
            ],
            [
                'name' => 'review_answer',
                'description' => 'Yalnız KAYIT KONTROLÜ bloğu varken: correct = kullanıcı sorduğumuz kaydın DOĞRU olduğunu söylüyor; declined = önerdiğimiz düzeltmeyi istemiyor. İkisinde de kayda dokunulmaz. Yanlış deyip doğrusunu söylüyorsa bu değil → undo_last + replacement.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['verdict' => ['type' => 'string', 'enum' => ['correct', 'declined']]],
                    'required' => ['verdict'],
                ],
            ],
            [
                'name' => 'cancel_pending',
                'description' => 'Kullanıcı bekleyen taslaktan VAZGEÇİYOR.',
                'input_schema' => ['type' => 'object', 'properties' => (object) []],
            ],
        ];

        // Okuma araçları (Faz 1): her firmada — kayıt defteri + eski konuşmalar. Yalnız okuma.
        $tools[] = RecordsQuery::tool();
        $tools[] = ConversationLookup::tool();

        return $tools;
    }
}
