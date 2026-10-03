<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Extracts an entry from free text and/or a document photo.
 * Uses the Claude Messages API with tool-use (guaranteed structured output).
 *
 * Entry kinds (`kind`):
 *   expense       — a cost (goods/service bought), paid or on account → Expense
 *   payment       — we paid money to a party (settles debt / advance) → ledger "odeme"
 *   sale          — we did work / sold to a party → ledger "satis" (one per item)
 *   collection    — a party paid us → ledger "tahsilat"
 *   balance_query — "how much do I owe X?" → read-only answer, nothing is saved
 *
 * Returns:
 *   kind (string), items (list<{description,amount}>), payment_type (string|null),
 *   amount (float), date (Y-m-d), due_date (Y-m-d|null), description (string),
 *   project_id (int|null), party_id (int|null),
 *   category_id (int|null), category_name (string|null),
 *   paid (bool), confidence ('high'|'low'),
 *   question (string|null)  — asked back to the user when a match is missing
 */
class ExpenseExtractor
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    public const KIND_EXPENSE = 'expense';
    public const KIND_PAYMENT = 'payment';
    public const KIND_SALE = 'sale';
    public const KIND_COLLECTION = 'collection';
    public const KIND_BALANCE_QUERY = 'balance_query';

    private const KIND_HINTS = [
        self::KIND_EXPENSE => '- "expense": bir MALİYET — mal/hizmet ALINDI ("Ahmet\'ten 100 bin malzeme aldım", "5 bin yakıt", "işçiye 3 bin yevmiye"). Ödendi de olsa veresiye de olsa gider budur.',
        self::KIND_PAYMENT => '- "payment": BİZ bir cariye PARA VERDİK, ama yeni bir mal/hizmet tarif edilmiyor ("Ahmet\'e 100 bin ödedim", "Kuşak Beton\'a 50 bin havale ettim", "ustaya 20 bin verdim"). Önceki borcu kapatır.',
        self::KIND_SALE => '- "sale": BİZ bir cariye İŞ YAPTIK / SATTIK, karşılığında o bize borçlanır ("Ahmet X\'e 80 bine proje yaptım", "şantiye şefliği 30 bin").',
        self::KIND_COLLECTION => '- "collection": CARİ BİZE PARA VERDİ ("Ahmet X 50 bin ödedi", "Ahmet\'ten 20 bin tahsil ettim"). DİKKAT: "ödedim" (biz verdik → payment) ile "ödedi" (o verdi → collection) farklıdır.',
        self::KIND_BALANCE_QUERY => '- "balance_query": kayıt değil, SORU ("Ahmet\'e ne kadar borcum var?", "Ahmet\'in bakiyesi ne?"). Hiçbir şey kaydedilmez; amount=0.',
    ];

    /**
     * Kurulumda açık modüllere göre izinli türler (APP_PROFILE ile):
     *   mimar: sale, collection, balance_query · müteahhit: hepsi · toptancı: expense, collection, balance_query.
     * Tedarikçi tarafı kapalı → "payment" yok; gider kapalı → "expense" yok; Direkt Satış açık → "sale" yok
     * (satış kalemli/stoklu Direkt Satış'tan girilir — iki ayrı satış yolu olmasın).
     *
     * @return list<string>
     */
    public static function allowedKinds(): array
    {
        return array_values(array_filter([
            config('modules.expenses') ? self::KIND_EXPENSE : null,
            config('modules.cari_supplier') ? self::KIND_PAYMENT : null,
            config('modules.direct_sales') ? null : self::KIND_SALE,
            self::KIND_COLLECTION,
            self::KIND_BALANCE_QUERY,
        ]));
    }

    /**
     * @param  array{media_type:string,data:string}|null  $image  base64 image
     * @param  array|null  $previous  önceki taslak — verilirse yeni mesaj DÜZELTME sayılır
     */
    public function extract(?string $text, ?array $image = null, ?array $previous = null): array
    {
        $apiKey = config('services.anthropic.api_key');
        if (empty($apiKey)) {
            throw new RuntimeException('ANTHROPIC_API_KEY tanımlı değil (.env).');
        }

        $content = [];
        if ($image !== null) {
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $image['media_type'],
                    'data' => $image['data'],
                ],
            ];
        }
        $userText = trim($text ?: '');
        if ($previous !== null) {
            $content[] = [
                'type' => 'text',
                'text' => "Kullanıcının önceki taslağa düzeltmesi/ek bilgisi:\n" . ($userText !== '' ? $userText : '(ekteki belgeye bak)'),
            ];
        } else {
            $content[] = [
                'type' => 'text',
                'text' => $userText !== ''
                    ? "Müteahhidin mesajı:\n" . $userText
                    : 'Ekteki belgeden gideri çıkar ve kaydet.',
            ];
        }

        // Fotoğraf varsa güçlü vision modeli (el yazısı okuma), metinde ucuz model.
        $model = $image !== null
            ? config('services.anthropic.vision_model')
            : config('services.anthropic.model');

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(90)->post(self::ENDPOINT, [
            'model' => $model,
            'max_tokens' => 1024,
            'system' => $this->systemPrompt($previous),
            'tools' => [$this->tool()],
            'tool_choice' => ['type' => 'tool', 'name' => 'save_entry'],
            'messages' => [
                ['role' => 'user', 'content' => $content],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Anthropic API hatası: ' . $response->status() . ' ' . $response->body());
        }

        foreach ($response->json('content', []) as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === 'save_entry') {
                return $this->normalize($block['input'] ?? []);
            }
        }

        throw new RuntimeException('AI yapılandırılmış çıktı döndürmedi.');
    }

    private function systemPrompt(?array $previous = null): string
    {
        $today = now()->format('Y-m-d');
        $context = ExpenseContext::build();
        $photoRule = in_array(self::KIND_EXPENSE, self::allowedKinds(), true)
            ? '- Fotoğraf (fiş/fatura/dekont/çek) → her zaman "expense".'
            : '- Fotoğraf (dekont/çek) → belgeden yönü çıkar: bize gelen para → "collection".';
        $kinds = implode("\n", array_map(
            fn (string $k) => self::KIND_HINTS[$k],
            self::allowedKinds(),
        ));

        $refine = '';
        if ($previous !== null) {
            $prevJson = json_encode($previous, JSON_UNESCAPED_UNICODE);
            $refine = <<<REFINE

            ÖNEMLİ — Aşağıda ONAY BEKLEYEN önceki bir taslak var. Kullanıcının yeni mesajı ya
            (a) bu taslağa bir DÜZELTME/EK BİLGİdir ("proje Cumhuriyet", "tutar 6000", "nakit"),
            ya da (b) TAMAMEN YENİ, ayrı bir kayıttır. Karar ver ve `is_new_entry`'yi doldur:
            - Düzeltme/ek bilgi ise (tür düzeltmesi dahil: "bu ödeme değil masraf"): `is_new_entry`=false; önceki değerleri KORU, yalnızca yeni
              mesajın belirttiği alanları güncelle (ör. önceki tutar 12000 ve mesaj sadece projeyi
              söylüyorsa tutarı 12000 bırak).
            - Yeni mesaj kendi başına ayrı bir kayıt tanımlıyorsa (kendi tutarı var, önceki taslağı
              düzeltmiyor) ya da bir bakiye sorusuysa: `is_new_entry`=true; önceki taslağı YOK SAY, SIFIRDAN çıkar —
              önceki proje/cari/kategori/tutarı ASLA taşıma.

            ÖNCEKİ TASLAK: {$prevJson}

            REFINE;
        }

        return <<<PROMPT
        Sen bir inşaat şirketinin kayıt asistanısın. Kullanıcı (müteahhit ya da mimar) sana
        yazarak veya belge (fiş, fatura, dekont) fotoğrafı atarak bir işlem bildirir. Görevin
        `save_entry` aracını çağırarak işlemi çıkarmak.

        Bugünün tarihi: {$today}. Tarih belirtilmemişse `date` alanına bugünü koy.
        {$refine}
        İŞLEM TÜRÜ (`kind`) — önce bunu belirle. YÖN çok önemli, fiilin öznesine dikkat et:
        {$kinds}
        {$photoRule}
        - `payment` / `sale` / `collection` / `balance_query` için CARİ zorunludur (kime/kimden).
          Kullanıcı cari söylemediyse `question`'a "Kime ödedin?" / "Kimden?" gibi kısa bir soru yaz.
          Sadece genel bir unvan/meslek söylendiyse ("ustaya", "işçiye", "kamyoncuya") — İSİM yoksa —
          mevcut bir cariyle EŞLEŞTİRME (adında "Usta" geçen cari olsa bile): `party_id` ve `party_name`
          null, `question`="Hangi usta? Adını yazar mısın?". Para yanlış kişinin hesabına yazılmasın.
        - `sale`'de birden fazla iş sayılırsa ("80 bine proje, 30 bine şantiye şefliği") her birini
          `items`'a ayrı yaz (description + amount); `amount` = toplamları.
        - `payment`/`collection`'da ödeme şekli söylendiyse `payment_type`: cash (nakit),
          bank_transfer (havale), eft, other (çek/senet/diğer). Söylenmediyse null.
        - `payment`/`sale`/`collection`'da kategori YOK (null bırak); proje sadece söylendiyse.
        
        PROJE ve CARİ için: önce aşağıdaki mevcut listelerle eşleştir (`project_id` / `party_id`).
        El yazısı bir isim, mevcut cari listesindeki bir isme makul ölçüde yakınsa (özellikle
        ilk ad tutuyorsa), harf harf yeniden okumaya çalışma — o mevcut cariyle EŞLEŞTİR
        (`party_id`). Tanıma, kör okumadan güvenilirdir.
        Kullanıcı bir proje/cari adı söyledi ama listede yoksa, id'yi null bırak ve adı
        `project_name` / `party_name` alanına yaz — onay verilince yeni kayıt açılacak.
        Kullanıcı hiç proje/cari söylemediyse hepsini null bırak (zorunlu değil).
        ÖNEMLİ: Sistemde tek proje/cari olsa bile, mesaj veya belge o projeyi/cariyi AÇIKÇA
        belirtmiyorsa proje/cari'yi null bırak — "tek seçenek bu" diye otomatik atama YAPMA
        (yanlış maliyet atfı olur). Özellikle bir çek belgesinde proje bilgisi YOKTUR;
        kullanıcı söylemediyse `project_id`'yi null bırak.
        `question` alanını proje/cari eklemek için KULLANMA — sadece tutarı gerçekten
        okuyamıyorsan gibi kritik bir belirsizlikte kullan.

        KATEGORİ için: kategori GENİŞ bir gider TÜRÜdür (Malzeme, İşçilik, Nakliye…), tek tek
        malzeme veya marka adı DEĞİLDİR. Önce mevcut kategori listesiyle eşleştir (`category_id`)
        ve mümkün olan en GENİŞ olanı tercih et. Somut yapı malzemeleri — boya, çimento, demir,
        kum, çakıl, tuğla, alçı, seramik, fayans, kereste, kablo, boru, vida, hırdavat vb. — hepsi
        "Malzeme" kategorisine girer; bunlar için AYRI kategori açma (mevcut "Malzeme" varsa onu seç).
        (Kategori yalnız `expense` içindir.)
        Sadece mevcut hiçbir geniş türe girmeyen, gerçekten YENİ bir gider türü varsa `category_name`'e
        kısa bir ad öner; aksi halde uygun mevcut kategoriyle eşleştir ve `category_name`'i boş bırak.
        Kararsızsan yeni açmaktansa en yakın mevcut kategoriyi seç — mükerrer/aşırı ince kategori açma.

        BELGE ÇEK İSE: Çekte iki taraf vardır — (a) matbu basılı KEŞİDECİ / hesap sahibi
        firma (çeki YAZAN, genelde bizim kendi şirketimizdir) ve (b) el yazısıyla yazılan
        LEHTAR / alıcı ("… emrine ödeyiniz" satırına EL YAZISIYLA yazılmış AD-SOYAD/isim,
        paranın GİTTİĞİ taraf). Cari (party) = bu el yazısı ad-soyad'dır; matbu basılı
        keşideci firmayı (Boztepeler gibi) cari YAPMA. Tutarı çekteki rakam/yazı tutarından,
        tarihi çekin (vade) tarihinden al.
        Alıcı adı TÜRKÇE bir ad-soyad'dır. Türk alfabesinde X, Q, W harfleri YOKTUR ve
        Türkçe isimler bu harfleri içermez. Okuduğun ad-soyad'da X/Q/W çıkıyorsa KESİNLİKLE
        yanlış okumuşsundur — en yakın Türkçe harfe düzelt (harfleri yeniden değerlendir),
        çıktıda asla X/Q/W kullanma. Türkçe harfler: a b c ç d e f g ğ h ı i j k l m n o ö
        p r s ş t u ü v y z.
        El yazısı ad-soyad'ı NET okuyamıyorsan UYDURMA: `party_name`'i null bırak,
        `confidence`'i "low" yap ve `question`'a "Çekteki alıcının adını net okuyamadım,
        kim olduğunu yazar mısın?" yaz. Yanlış/saçma bir isim yazmaktansa sormak daha iyi.

        Tutarı (`amount`) Türk Lirası olarak, sayı biçiminde döndür (binlik ayraç/simge yok).
        El yazısı/bulanık nedeniyle tutardan emin değilsen `confidence` = "low" yap.

        ÖDEME DURUMU ve VADE (yalnız `expense` için):
        - Varsayılan: ödeme YAPILMIŞ (`paid`=true). Mesaj ödemeye dair bir şey söylemiyorsa ödendi say.
        - Gelecek zaman/niyet ("ödeyeceğim", "ödenecek", "vereceğim", "kalan/borç") veya açıkça
          "ödenmedi" deniyorsa: `paid`=false.
        - `due_date` (YYYY-MM-DD): ödemenin planlandığı/söz verildiği vade. Örn "…ödemesi 12.05.2026'da"
          veya "12.05.2026'da ödeyeceğim" → `due_date`=2026-05-12, `paid`=false. Vade yoksa null bırak.
        - ÖNEMLİ: `date` (harcamanın/giderin OLUŞTUĞU tarih) ile `due_date` (ödeme VADESİ) farklıdır.
          Ödeme ileride yapılacaksa gider tarihi bugündür (`date`=bugün) ve verilen tarih `due_date`'tir.
          Geçmişte "aldım/harcadım/ödedim" denen bir tarih ise o `date`'tir, `due_date`=null, `paid`=true.

        {$context}
        PROMPT;
    }

    private function tool(): array
    {
        return [
            'name' => 'save_entry',
            'description' => 'Çıkarılan işlem bilgisini yapılandırılmış olarak döndürür.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'kind' => ['type' => 'string', 'enum' => self::allowedKinds(), 'description' => 'Entry kind; see system prompt'],
                    'items' => [
                        'type' => 'array',
                        'description' => 'Only for kind=sale with several jobs: one item per job',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'description' => ['type' => 'string'],
                                'amount' => ['type' => 'number'],
                            ],
                            'required' => ['description', 'amount'],
                        ],
                    ],
                    'payment_type' => ['type' => ['string', 'null'], 'enum' => ['cash', 'bank_transfer', 'eft', 'other', null], 'description' => 'payment/collection method if stated, else null'],
                    'amount' => ['type' => 'number', 'description' => 'Amount (TRY, numeric); total for sale items; 0 for balance_query'],
                    'date' => ['type' => ['string', 'null'], 'description' => 'Date the expense occurred (YYYY-MM-DD); today if absent. NOT the payment due date.'],
                    'due_date' => ['type' => ['string', 'null'], 'description' => 'Payment due date (YYYY-MM-DD) when payment is planned for the future / promised; null if paid or no due date given'],
                    'description' => ['type' => 'string', 'description' => 'What the expense is for (short)'],
                    'project_id' => ['type' => ['integer', 'null'], 'description' => 'Matched existing project id or null'],
                    'project_name' => ['type' => ['string', 'null'], 'description' => 'Proposed NEW project name if the user named one that is not in the list; null if matched or none named'],
                    'party_id' => ['type' => ['integer', 'null'], 'description' => 'Matched existing party id or null'],
                    'party_name' => ['type' => ['string', 'null'], 'description' => 'Proposed NEW party name if the user named one that is not in the list; null if matched or none named'],
                    'category_id' => ['type' => ['integer', 'null'], 'description' => 'Matched existing category id or null'],
                    'category_name' => ['type' => ['string', 'null'], 'description' => 'Proposed NEW short category name if none fits; null if an existing category matched'],
                    'paid' => ['type' => 'boolean', 'description' => 'Paid already? true if unclear'],
                    'confidence' => ['type' => 'string', 'enum' => ['high', 'low']],
                    'question' => ['type' => ['string', 'null'], 'description' => 'Question to ask the user for a missing project/party match, else null'],
                    'is_new_entry' => ['type' => 'boolean', 'description' => 'Only meaningful when a previous draft is provided: true if the new message is a brand-new separate entry or a balance question (extract from scratch, ignore previous), false if it refines the previous draft. Default false.'],
                ],
                'required' => ['kind', 'amount', 'description', 'paid', 'confidence'],
            ],
        ];
    }

    private function normalize(array $input): array
    {
        $kind = in_array($input['kind'] ?? null, self::allowedKinds(), true) ? $input['kind'] : self::KIND_EXPENSE;

        $items = [];
        foreach ((array) ($input['items'] ?? []) as $item) {
            $amount = (float) ($item['amount'] ?? 0);
            if ($amount > 0) {
                $items[] = ['description' => trim((string) ($item['description'] ?? '')), 'amount' => $amount];
            }
        }

        $paymentType = $input['payment_type'] ?? null;

        return [
            'kind' => $kind,
            'items' => $kind === self::KIND_SALE ? $items : [],
            'payment_type' => in_array($paymentType, ['cash', 'bank_transfer', 'eft', 'other'], true) ? $paymentType : null,
            'amount' => isset($input['amount']) ? (float) $input['amount'] : 0.0,
            'date' => $input['date'] ?? now()->format('Y-m-d'),
            'due_date' => ! empty($input['due_date']) ? (string) $input['due_date'] : null,
            'description' => (string) ($input['description'] ?? ''),
            'project_id' => isset($input['project_id']) ? (int) $input['project_id'] : null,
            'project_name' => ! empty($input['project_name']) ? trim((string) $input['project_name']) : null,
            'party_id' => isset($input['party_id']) ? (int) $input['party_id'] : null,
            'party_name' => ! empty($input['party_name']) ? trim((string) $input['party_name']) : null,
            'category_id' => isset($input['category_id']) ? (int) $input['category_id'] : null,
            'category_name' => ! empty($input['category_name']) ? trim((string) $input['category_name']) : null,
            'paid' => (bool) ($input['paid'] ?? true),
            'confidence' => in_array($input['confidence'] ?? 'high', ['high', 'low'], true) ? $input['confidence'] : 'high',
            'question' => ! empty($input['question']) ? (string) $input['question'] : null,
            'is_new_entry' => (bool) ($input['is_new_entry'] ?? false),
        ];
    }
}
