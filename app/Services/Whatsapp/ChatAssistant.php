<?php

namespace App\Services\Whatsapp;

use App\Models\Expense;
use App\Models\UsageLog;
use App\Models\WhatsappMessage;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
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
    public const HISTORY_MINUTES = 30;

    private const MAX_HISTORY = 16;

    /** Araç turu sınırı (arama → cevap); Twilio 15 sn bekler. */
    private const MAX_STEPS = 3;

    public function __construct(private ExpenseExtractor $extractor) {}

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
    public function respond(string $phone, string $text, ?array $pending): array
    {
        $apiKey = config('services.anthropic.api_key');
        if (empty($apiKey)) {
            throw new RuntimeException('ANTHROPIC_API_KEY tanımlı değil (.env).');
        }

        $model = config('services.anthropic.chat_model') ?: config('services.anthropic.model');
        $messages = $this->history($phone, $text);
        $system = $this->systemPrompt($pending);

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
                ->connectTimeout(5)
                ->retry(2, 300, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException, throw: false)
                ->timeout(60)->post(self::ENDPOINT, [
                    'model' => $model,
                    'max_tokens' => 1024,
                    'system' => $system,
                    'tools' => $this->tools(),
                    // Her zaman bir araç: düz metinle kendi "özetini" yazıp taslaksız onay istiyordu (Nevzat, bal).
                    // Yeni modeller zorunlu aracı desteklemiyor (400) → serbest; düz metin "reply" sayılır.
                    'tool_choice' => ['type' => self::forcesTools((string) $model) ? 'any' : 'auto'],
                    'messages' => $messages,
                ]);

            if ($response->failed()) {
                throw new RuntimeException('Anthropic API hatası: ' . $response->status() . ' ' . $response->body());
            }
            UsageLog::recordAi((string) $model, (array) $response->json('usage', []));

            $content = (array) $response->json('content', []);
            $said = trim(implode("\n", array_map(fn ($b) => $b['text'] ?? '', array_filter($content, fn ($b) => ($b['type'] ?? null) === 'text'))));
            $call = collect($content)->first(fn ($b) => ($b['type'] ?? null) === 'tool_use');

            if (! $call) {
                return ['type' => 'reply', 'text' => $said !== '' ? $said : 'Tam anlayamadım, biraz daha açık yazar mısın?'];
            }

            $input = (array) ($call['input'] ?? []);
            switch ($call['name']) {
                case 'propose_entry':
                    $data = $this->extractor->normalizeEntry($input);
                    $data['is_new_entry'] = (bool) ($input['is_new_entry'] ?? true);
                    $data['confidence'] = 'high'; // yazılı tutar belirsiz değildir (eski çıkarıcıdaki gibi)
                    // Aynı mesajdaki kalan kalem(ler): onaydan sonra sıradaki taslak olarak önerilir.
                    $data['next_items'] = trim((string) ($input['next_items'] ?? '')) ?: null;

                    return ['type' => 'entry', 'data' => $data, 'text' => $said];
                case 'confirm_pending':
                    // İki anahtar: AI "onay" dese de mesajda gerçek bir onay kelimesi yoksa KAYDETME
                    // ("Sılajcı yusuf" isim düzeltmesi onay sayılıp kaydedilmişti).
                    if (! self::hasAssent($text)) {
                        return ['type' => 'ask_confirm'];
                    }

                    return ['type' => 'confirm', 'paid' => isset($input['paid']) ? (bool) $input['paid'] : null];
                case 'cancel_pending':
                    return ['type' => 'cancel'];
                case 'undo_last':
                    // Düzeltme: kaydın doğru hâli verildiyse tek onayla sil + yeniden yaz.
                    $replacement = is_array($input['replacement'] ?? null) && ! empty($input['replacement']['kind'])
                        ? ['confidence' => 'high'] + $this->extractor->normalizeEntry($input['replacement'])
                        : null;

                    return ['type' => 'undo', 'replacement' => $replacement];
                case 'reply':
                    return ['type' => 'reply', 'text' => trim((string) ($input['text'] ?? '')) ?: 'Tam anlayamadım, biraz daha açık yazar mısın?'];
                case 'search_expenses':
                    // Salt okuma: sonucu modele ver, cevabı o yazsın.
                    $messages[] = ['role' => 'assistant', 'content' => $content];
                    $messages[] = ['role' => 'user', 'content' => [[
                        'type' => 'tool_result',
                        'tool_use_id' => $call['id'],
                        'content' => json_encode($this->searchExpenses($input), JSON_UNESCAPED_UNICODE),
                    ]]];
                    break;
                default:
                    return ['type' => 'reply', 'text' => $said ?: 'Tam anlayamadım, biraz daha açık yazar mısın?'];
            }
        }

        return ['type' => 'reply', 'text' => 'Şu an toparlayamadım, birazdan tekrar dener misin? 🙏'];
    }

    /** claude-sonnet-5-5 / claude-opus-5-5 ve sonrası tool_choice any/tool kabul etmiyor. */
    private static function forcesTools(string $model): bool
    {
        return ! preg_match('/-5-5\b|-[6-9]\b|-[6-9]-/', $model);
    }

    /** Mesajda onay kelimesi var mı: evet / tamam / ok / olur / kaydet / onay — ya da tek harf yazım hatası ("evert"). */
    public static function hasAssent(string $text): bool
    {
        $lower = mb_strtolower(strtr($text, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
        $words = preg_split('/[\s\p{P}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $w) {
            foreach (['evet', 'tamam', 'olur', 'kaydet', 'onay', 'onayla', 'ok', 'tmm', 'e'] as $yes) {
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
            $this->append($messages, $role, (string) $row->body);
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

    private function systemPrompt(?array $pending): string
    {
        $now = now(self::TIMEZONE)->locale('tr');
        $today = $now->format('Y-m-d') . ' (' . $now->translatedFormat('l') . ', saat ' . $now->format('H:i') . ')';
        $kinds = ExpenseExtractor::kindGuide();
        $context = ExpenseContext::build();
        $draft = $pending !== null
            ? json_encode(array_intersect_key($pending, array_flip([
                'kind', 'amount', 'description', 'date', 'due_date', 'party_id', 'party_name', 'project_id', 'project_name',
                'category_id', 'category_name', 'paid', 'debt_side', 'payment_purpose', 'items', 'payment_type',
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
        $modules[] = '- Listede olmayan kişi için yeni cari SEN açabilirsin: `party_name` yaz, onayla birlikte açılır. "Cariye ekle" bunu ister — "panelden ekle" deme.';
        $modules = "BU HESAPTA:\n" . implode("\n", $modules) . "\n";

        return <<<PROMPT
        Sen Hesap Asistanım'sın: esnafın WhatsApp'tan yazdığı alış, satış, ödeme, tahsilat, borç-alacak ve
        harcamaları kayda geçiren asistan. Kullanıcı hızlı ve yazım hatalarıyla yazar ("verceyim" = vereceğim,
        "alcakgım" = alacağım, "evert" = evet). Ne demek istediğini konuşmanın bütününden anla.
        Türkçe, kısa ve samimi yaz — WhatsApp mesajı gibi, en fazla 2-3 cümle. Bugün: {$today}.

        NASIL ÇALIŞIRSIN
        - Konuşma geçmişini oku; kullanıcının önceki mesajları bağlamdır. Ama her yeni işlem kendi başınadır:
          önceki işlemin kişisi/tutarı yalnız kullanıcı açıkça ona dönüyorsa ("ona", "bunu", "aynı kişi") taşınır.
        - Her mesajda BİR araç çağır. Bir işlemi kaydetmek için `propose_entry`: özeti ve "kaydedeyim mi?"
          sorusunu SİSTEM yazar — sen özet metni YAZMA (geçmişteki özetler sistemindir, taklit etme).
          `question` YALNIZ gerçekten eksik/şüpheli bir bilgi için (ör. yön belirsiz); "onaylıyor musun?",
          "yeni cari açayım mı?" gibi soru YAZMA — onayı sistem zaten soruyor.
          Düz mesaj (soru, bilgi, yapamadığın şey) → `reply`. Sen ASLA "kaydettim" deme.
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
        - Açıklamayı (`description`) kullanıcının söylediği işten kısa yaz; söylemediği bilgi UYDURMA
          ("önceki dönemden", "devreden" gibi). "alacağım / alınacak" → o bize borçlu; yön belli, sorma.
        - KAYDEDİLMİŞ son işlemi silmek ("son kaydı sil") → `undo_last`. DÜZELTMEK ("bunu satış olarak değiştir",
          "8000 değil 10000 olacaktı", "kişi Ali değil Veli") → `undo_last` + `replacement` = kaydın doğru tam hâli
          (geri kalan her şey önceki kayıttaki gibi). Sistem ikisini gösterip tek onay ister. Daha eski kayıtlar panelden.
        - Kategoriyi her kayıt için KENDİ içeriğine göre seç; önceki kaydın kategorisini taşıma (tosun/hayvan alımı ≠ "Çobanlar").
        - "Traktöre ne harcadım" gibi bir şeye/kişiye göre harcama sorusu → `search_expenses` (yazım
          varyantlarıyla: traktör, traktor). Dönem toplamı ("bu ay ne harcadım") → propose_entry kind "expense_summary".
        - Bakiye, toplam, ekstre, cari listesi, takvim soruları ve hatırlatmalar da `propose_entry` ile (ilgili kind).
        - Ekstre (kişinin hesap dökümü) PDF olarak gönderilir; "PDF" isteğinde hangi kişinin ekstresi olduğunu sor.
        - Yapamadığın bir şeyse (hayvan sayımı, stok listesi…) kibarca söyle ve yapabildiğin bir örnek ver.

        İŞLEM TÜRLERİ (`kind`):
        {$kinds}
        {$modules}
        {$context}

        BEKLEYEN TASLAK (kullanıcıya özeti gösterildi, onay bekliyor): {$draft}
        PROMPT;
    }

    private function tools(): array
    {
        $entry = $this->extractor->entrySchema();
        $entry['properties']['next_items'] = [
            'type' => ['string', 'null'],
            'description' => 'Kullanıcının aynı mesajında bu kayıttan SONRA kaydedilecek başka kalem(ler) varsa onların kullanıcı sözleriyle kısa tarifi ("10.000 TL traktör ustalık parası ödedim"); yoksa null.',
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
                'description' => 'Kullanıcı KAYDEDİLMİŞ son işlemi silmek / geri almak ya da DÜZELTMEK istiyor ("son kaydı sil", "yanlış girdim", "bunu satış yap", "8000 değil 10000"). Düzeltmede `replacement` ile kaydın DOĞRU TAM hâlini ver; sistem eskisini ve yenisini gösterip tek onayla değiştirir.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'replacement' => [
                            'type' => ['object', 'null'],
                            'description' => 'Yalnız düzeltmede: kaydın doğru hâli (propose_entry ile aynı alanlar). Önceki kayıttan kişi, tutar, açıklama, tarih KORUNUR; yalnız istenen değişir. Sadece silme isteniyorsa null.',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'cancel_pending',
                'description' => 'Kullanıcı bekleyen taslaktan VAZGEÇİYOR.',
                'input_schema' => ['type' => 'object', 'properties' => (object) []],
            ],
        ];

        if (config('modules.expenses')) {
            $tools[] = [
                'name' => 'search_expenses',
                'description' => 'Kayıtlı harcamalarda ara (açıklama, kategori, kişi). Salt okuma; sonuçla kullanıcıya cevap yaz.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'terms' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Aranacak kelimeler ve yazım varyantları (traktör, traktor)'],
                        'date_from' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD; söylenmediyse null (tüm zamanlar)'],
                        'date_to' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD'],
                    ],
                    'required' => ['terms'],
                ],
            ];
        }

        return $tools;
    }

    private function searchExpenses(array $input): array
    {
        $terms = array_values(array_filter(array_map(fn ($t) => trim((string) $t), (array) ($input['terms'] ?? []))));
        if ($terms === []) {
            return ['error' => 'arama kelimesi yok'];
        }
        $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $rows = Expense::query()
            ->with(['category:id,name', 'party:id,name'])
            ->where(function ($q) use ($terms, $like) {
                foreach ($terms as $t) {
                    $q->orWhere('description', $like, "%{$t}%")
                        ->orWhereHas('category', fn ($c) => $c->where('name', $like, "%{$t}%"))
                        ->orWhereHas('party', fn ($p) => $p->where('name', $like, "%{$t}%"));
                }
            })
            ->when($input['date_from'] ?? null, fn ($q, $d) => $q->whereDate('expense_date', '>=', $d))
            ->when($input['date_to'] ?? null, fn ($q, $d) => $q->whereDate('expense_date', '<=', $d))
            ->orderBy('expense_date')
            ->limit(50)
            ->get();

        return [
            'count' => $rows->count(),
            'total' => round((float) $rows->sum('amount'), 2),
            'rows' => $rows->map(fn (Expense $e) => [
                'date' => $e->expense_date?->format('Y-m-d'),
                'amount' => (float) $e->amount,
                'description' => $e->description,
                'category' => $e->category?->name,
                'party' => $e->party?->name,
                'status' => $e->payment_status,
            ])->all(),
        ];
    }
}
