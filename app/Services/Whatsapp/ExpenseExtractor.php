<?php

namespace App\Services\Whatsapp;

use App\Models\UsageLog;
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
 *   statement     — "send X's statement" → PDF sent back (optional date range / project), nothing is saved
 *   totals_query  — "how much is owed to me in total?" → all parties, receivables / payables, nothing is saved
 *   expense_summary — "how much did I spend this month?" → expenses (+ contract deliveries) for a period, nothing is saved
 *   debt_note     — "Ali owes me 40k" / "I owe Mehmet 15k" (a standing balance, no transaction verb) → ledger satis / alis
 *   party_list    — "which parties do I have?" → list with balances, nothing is saved
 *   help          — fits none of the above → AI-written short guidance in `reply`, nothing is saved
 *
 * Returns:
 *   kind (string), items (list<{description,amount}>), payment_type (string|null),
 *   amount (float), date (Y-m-d), due_date (Y-m-d|null), description (string),
 *   project_id (int|null), party_id (int|null),
 *   category_id (int|null), category_name (string|null),
 *   paid (bool|null — null: not stated, default applied by caller), confidence ('high'|'low'),
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
    public const KIND_STATEMENT = 'statement';
    public const KIND_TOTALS_QUERY = 'totals_query';
    public const KIND_EXPENSE_SUMMARY = 'expense_summary';
    public const KIND_DEBT_NOTE = 'debt_note';
    public const KIND_PARTY_LIST = 'party_list';
    public const KIND_HELP = 'help';

    private const KIND_HINTS = [
        self::KIND_EXPENSE => '- "expense": bir MALİYET — mal/hizmet ALINDI ("Ahmet\'ten 100 bin malzeme aldım", "5 bin yakıt", "işçiye 3 bin yevmiye"). Ödendi de olsa veresiye de olsa gider budur.',
        self::KIND_PAYMENT => '- "payment": BİZ bir cariye PARA VERDİK, ama yeni bir mal/hizmet tarif edilmiyor ("Ahmet\'e 100 bin ödedim", "Kuşak Beton\'a 50 bin havale ettim", "ustaya 20 bin verdim"). Önceki borcu kapatır. Paranın NE İÇİN verildiği söylendiyse `payment_purpose`: ileride yapılacak iş için peşin ("avans", "kapora", "iş yaptıracağım") → "advance"; geri alınacak ödünç ("borç verdim", "ödünç", "geri alacağım") → "loan". Verilen para yapılmış bir işin / alınmış malın karşılığıysa ("sıva yaptırdım", "malzeme aldım") bu bir "expense"dir (paid=true). Söylenmediyse null.',
        self::KIND_SALE => '- "sale": BİZ bir cariye İŞ YAPTIK / SATTIK, karşılığında o bize borçlanır ("Ahmet X\'e 80 bine proje yaptım", "şantiye şefliği 30 bin").',
        self::KIND_COLLECTION => '- "collection": CARİ BİZE PARA VERDİ ("Ahmet X 50 bin ödedi", "Ahmet\'ten 20 bin tahsil ettim"). DİKKAT: "ödedim" (biz verdik → payment) ile "ödedi" (o verdi → collection) farklıdır.',
        self::KIND_BALANCE_QUERY => '- "balance_query": kayıt değil, SORU ("Ahmet\'e ne kadar borcum var?", "Ahmet\'in bakiyesi ne?"). Belirli bir cari ADI şart; isim yoksa ("Borcum ne kadar", "Tüm borç", "Alacağım ne kadar") → "totals_query". Hiçbir şey kaydedilmez; amount=0.',
        self::KIND_DEBT_NOTE => '- "debt_note": işlem fiili OLMADAN söylenen bir ALACAK/BORÇ durumu — eski/devreden hesap, veresiye ("Ali\'den 40 bin alacağım var", "Ahmet bana 20 bin borçlu", "Mehmet\'e 15 bin borcum var", "Kuşak Beton\'a 50 bin borçluyum"). `debt_side`: o bize borçlu → "receivable", biz ona borçluyuz → "payable"; yön fiilden AÇIKÇA anlaşılmıyorsa ("Serkan adına 10000 borç", "Ali 5 bin borç", "Veli ile 3 bin hesap") → "unclear" — TAHMİN ETME, kullanıcıya sorulur. Cari zorunlu (isim yoksa `question`="Kimden alacağın var? Adını yazar mısın?" ya da "Kime borcun var? Adını yazar mısın?"). Soru cümlesi DEĞİLDİR ("Ali\'ye ne kadar borcum var?" → balance_query).',
        self::KIND_PARTY_LIST => '- "party_list": carilerin LİSTESİ isteniyor, belirli bir isim yok ("Hangi carim var?", "Carilerimi göster", "Cari hesabı kontrol et", "Kimlerle hesabım var?"). Hiçbir şey kaydedilmez; amount=0.',
        self::KIND_HELP => '- "help": yukarıdaki türlerin HİÇBİRİNE uymayan mesaj — selam, teşekkür, "ne yapabilirsin", "cari hesap kayıt" gibi yarım/anlaşılmayan istekler, desteklenmeyen işler. Hiçbir şey kaydedilmez; amount=0. `reply` alanına KISA (en fazla 3 cümle), samimi Türkçe bir cevap yaz: ne anladığını söyle ve yapabildiğin bir işe ÖRNEK CÜMLEYLE yönlendir (örnekler yalnız bu listedeki türlerden). Yapamadığın şeyi yapabilirmiş gibi, kayıt yapmışsın gibi SÖYLEME. Tutarı ya da ismi eksik bir İŞLEM ise "help" SEÇME — o türü seç ve `question` ile eksiği sor.',
        self::KIND_TOTALS_QUERY => '- "totals_query": TÜM CARİLER için toplam SORUSU, belirli bir cari YOK ("Toplam alacağım ne kadar?", "Kimden alacağım var?", "Toplam borcum ne?", "Kime borçluyum?", "Genel durum ne?"). GİDER/HARCAMA/MASRAF sorusu bu DEĞİLDİR (→ "expense_summary"). Hiçbir şey kaydedilmez; amount=0. `totals_side`: alacak sorusu → "receivable", borç sorusu → "payable", genel/ikisi → "both". Proje söylendiyse `project_id`.',
        self::KIND_EXPENSE_SUMMARY => '- "expense_summary": bir DÖNEMDE ne kadar HARCANDIĞI sorusu ("Bu ay ne kadar giderim var?", "Gider ?", "Giderlerim", "Masraflar ne durumda?", "Eylül\'de ne harcadım?", "Bu yıl toplam masrafım ne?", "Cumhuriyet\'te bu ay ne harcadım?", "Geçen ay yakıta ne verdim?"). Borç/alacak sorusu DEĞİL. Hiçbir şey kaydedilmez; amount=0. Dönem: `date_from`/`date_to` ("bu ay" → ayın 1\'i / bugün; "geçen ay" → geçen ayın 1\'i / son günü; "Eylül" → bu yılın 09-01 / 09-30; "bu yıl" → 01-01 / bugün); dönem söylenmediyse ikisi de null (bu ay sayılır). Proje söylendiyse `project_id`.',
        self::KIND_STATEMENT => '- "statement": EKSTRE / hesap dökümü İSTEĞİ ("Ali\'nin ekstresini at", "Kuşak Beton ekstresi", "Ali\'nin Eylül ekstresi", "Ali\'nin Cumhuriyet ekstresi"). Hiçbir şey kaydedilmez; amount=0. Dönem söylendiyse `date_from`/`date_to` (ör. "Eylül" → bu yılın 09-01 / 09-30; "bu ay" → ayın 1\'i / bugün; "2026" → 01-01 / 12-31), proje söylendiyse `project_id`.',
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
            self::KIND_STATEMENT,
            self::KIND_TOTALS_QUERY,
            config('modules.expenses') ? self::KIND_EXPENSE_SUMMARY : null,
            self::KIND_DEBT_NOTE,
            self::KIND_PARTY_LIST,
            self::KIND_HELP,
        ]));
    }

    /**
     * @param  array{media_type:string,data:string}|null  $image  base64 image
     * @param  array|null  $previous  önceki taslak — verilirse yeni mesaj DÜZELTME sayılır
     * @param  array{party_id:int,party_name:string,balance:float,balance_note:string}|null  $lastParty
     *         son 30 dk'da konuşulan cari (ConversationContext) — "ondan / ona / daha / hepsini" ona bağlanır
     */
    public function extract(?string $text, ?array $image = null, ?array $previous = null, ?array $lastParty = null): array
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
        ])
            // Bağlantı kurulamazsa (ağ dalgalanması) hızlı vazgeç, bir kez daha dene — Twilio 15 sn bekler.
            ->connectTimeout(5)
            ->retry(2, 300, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException, throw: false)
            ->timeout(90)->post(self::ENDPOINT, [
            'model' => $model,
            'max_tokens' => 1024,
            'system' => $this->systemPrompt($previous, $lastParty),
            'tools' => [$this->tool()],
            'tool_choice' => ['type' => 'tool', 'name' => 'save_entry'],
            'messages' => [
                ['role' => 'user', 'content' => $content],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Anthropic API hatası: ' . $response->status() . ' ' . $response->body());
        }

        // Maliyet takibi: her çağrının token'ı ve $ karşılığı (firma başına rapor).
        UsageLog::recordAi((string) $model, (array) $response->json('usage', []));

        foreach ($response->json('content', []) as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === 'save_entry') {
                $data = $this->normalize($block['input'] ?? []);
                // Yazılı tutar belirsiz olamaz ("20.000 tl" için "emin değilim" deniyordu); şüphe yalnız fotoğrafta.
                if ($image === null) {
                    $data['confidence'] = 'high';
                }

                return $data;
            }
        }

        throw new RuntimeException('AI yapılandırılmış çıktı döndürmedi.');
    }

    private function systemPrompt(?array $previous = null, ?array $lastParty = null): string
    {
        $today = now()->format('Y-m-d');
        $context = ExpenseContext::build();
        // Fiilsiz/yönü belirsiz mesaj ("Abdullah Uçar 2 milyon kaba inşaat"): müteahhitte bu bir
        // maliyettir; satış yalnız açık fiille. Gider kapalıysa (mimar) varsayılan satış.
        $ambiguousRule = in_array(self::KIND_EXPENSE, self::allowedKinds(), true)
            ? '- Fiil YOKSA ya da yön belirsizse ("Abdullah Uçar 2 milyon kaba inşaat", "Ahmet 50 bin sıva") → "expense" (yaptırdığımız iş = maliyet). "sale" SADECE açık fiille: "yaptım", "sattım", "fatura kestim", "iş yaptım" — cari daha önce müşteri olarak görünse bile fiil yoksa satış SEÇME.'
            : '- Fiil YOKSA ("Ali Bey 80 bin proje") → "sale" (yaptığımız iş).';
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
              düzeltmiyor) ya da bir soruysa (bakiye, toplam, gider özeti, ekstre, cari listesi): `is_new_entry`=true; önceki taslağı YOK SAY, SIFIRDAN çıkar —
              önceki proje/cari/kategori/tutarı ASLA taşıma.

            Önceki taslak bir "payment" ise ve kullanıcıya "Ne için verdin?" sorulduysa, cevap bir DÜZELTMEDİR
            (`is_new_entry`=false; tutarı, cariyi, tarihi KORU) — niyeti oku:
              · yapılmış iş / alınmış mal ("iş yaptırdım", "sıva işçiliği", "malzeme aldım") → kind "expense",
                `paid`=true, `description` işin kısa adı, uygun kategori;
              · ileride yapılacak iş, peşinat ("iş yaptıracağım", "avans", "kapora") → kind "payment", `payment_purpose`="advance";
              · ödünç ("borç verdim", "geri alacağım", "ödünç") → kind "payment", `payment_purpose`="loan".

            ÖNCEKİ TASLAK: {$prevJson}

            REFINE;
        }

        $lastPartyBlock = $lastParty !== null ? $this->lastPartyContext($lastParty) : '';

        return <<<PROMPT
        Sen bir inşaat şirketinin kayıt asistanısın. Kullanıcı (müteahhit ya da mimar) sana
        yazarak veya belge (fiş, fatura, dekont) fotoğrafı atarak bir işlem bildirir. Görevin
        `save_entry` aracını çağırarak işlemi çıkarmak.

        Bugünün tarihi: {$today}. Tarih belirtilmemişse `date` alanına bugünü koy.
        {$refine}{$lastPartyBlock}
        İŞLEM TÜRÜ (`kind`) — önce bunu belirle. YÖN çok önemli, fiilin öznesine dikkat et:
        {$kinds}
        {$photoRule}
        {$ambiguousRule}
        - `payment` / `sale` / `collection` / `debt_note` / `balance_query` / `statement` için CARİ zorunludur (kime/kimden).
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

        KATEGORİ için (yalnız `expense`): kategori GENİŞ bir gider TÜRÜdür (Malzeme, İşçilik, Kira, Ulaşım,
        Yemek, Fatura…), tek tek malzeme ya da marka adı DEĞİLDİR. İki kural:
        1) ANLAMA göre eşleştir, yakınlığa göre DEĞİL: mevcut bir kategori ancak AYNI anlama geliyorsa
           `category_id` ile seç. Örnekler: otobüs, taksi, metro, uçak, yol, otopark → "Ulaşım" (NAKLİYE DEĞİL —
           Nakliye yük/malzeme taşımaktır: kamyon, hafriyat, malzeme getirme); mazot/benzin → "Yakıt";
           elektrik, su, doğalgaz, internet, telefon faturası → mevcutsa ilgili kategori ya da "Fatura";
           yemek, çay, su (içme) → "Yemek"; boya, çimento, demir, kum, tuğla, alçı, seramik, kereste, kablo,
           boru, vida, hırdavat → "Malzeme".
           Aynı anlamda mevcut kategori YOKSA `category_name`'e kısa, genel bir ad öner (ör. "Ulaşım", "Yemek",
           "Kırtasiye", "Reklam") — uymayan bir kategoriye zorla sokma.
        2) AYNI ŞEYİN İKİNCİ KATEGORİSİNİ AÇMA: listede "Ulaşım" varken "Yol", "Otobüs", "Ulaşım gideri" açma;
           "Malzeme" varken "Boya", "Çimento" açma. Önerdiğin ad listede başka yazımla varsa onu seç.

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
        El yazısı/bulanık nedeniyle tutardan emin değilsen `confidence` = "low" yap. Yazılı mesajda
        "20.000" Türkçe biçimdir (= yirmi bin), "20 bin" de öyle — yazıda `confidence` HER ZAMAN "high".
        Bir İŞLEMİN tutarı hiç söylenmediyse ("Kira") türü yine seç, `amount`=0 ve `question`="… tutarı ne kadar?" yaz.
        AMA soru biçimindeki genel kelime ("Gider ?", "Giderler?", "Borç?", "Alacak?") işlem DEĞİL, sorudur:
        gider/harcama/masraf → "expense_summary", borç/alacak → "totals_query".

        ÖDEME TARİHİ (`sale` ve `debt_note` için): para ileride ödenecekse / alınacaksa ve tarih SÖYLENDİYSE
        `due_date` (YYYY-MM-DD): "Ali'ye bal sattım, gelecek ayın 14'ünde ödeyecek" → gelecek ayın 14'ü;
        "Mehmet'e 15 bin borcum var, ayın 20'sinde ödeyeceğim" → bu ayın 20'si (geçtiyse gelecek ayın);
        "10 gün sonra ödeyecek" → bugün + 10. Tarih söylenmediyse null bırak — SORMA (`question` kullanma).

        ÖDEME DURUMU ve VADE (yalnız `expense` için):
        - `paid`'i SADECE açıkça belliyse doldur, yoksa null bırak (varsayılanı sistem uygular):
          · true: "ödedim", "ödendi", "peşin", "nakit verdim", "kartla ödedim", "havale ettim"; ya da belge
            bir ÖDEME KANITI ise (banka dekontu, POS/kredi kartı slipi, çek).
          · false: gelecek zaman/niyet ("ödeyeceğim", "ödenecek", "vereceğim", "kalan/borç"), "ödemedim",
            "ödenmedi", "veresiye", "hesaba yaz".
          · null: ödemeye dair hiçbir şey yok ("Ahmet'ten 100 bin malzeme aldım", "5 bin yakıt"). "aldım"
            ödeme DEĞİLDİR — mal almaktır.
        - ÖDENMEMİŞ BİR GİDERİN ÖDEMESİ: kullanıcı aşağıdaki "ÖDENMEMİŞ GİDERLER" listesindeki bir borcu
          ÖDEDİĞİNİ söylüyorsa ("kirayı ödedim", "elektriği yatırdım", "kiranın 10 binini ödedim", "Ahmet'in
          parasını verdim") bu YENİ bir gider DEĞİLDİR: kind "expense", `paid`=true, `settles_expense_id`=o borcun id'si,
          `amount`=ödenen tutar (söylenmediyse borcun tutarının tamamı), `description` borcun açıklaması.
          Eşleşme yoksa ya da açıkça yeni bir dönem/iş söyleniyorsa ("Kasım kirasını ödedim" ama listedeki Ekim)
          `settles_expense_id`=null. SADECE AYNI ŞEY ise eşleştir (kira↔kira, elektrik↔elektrik faturası, Ahmet'in
          malzemesi↔Ahmet'ten malzeme); farklı bir şeyse ("kirayı ödedim" ama listede yalnız elektrik var) ASLA eşleştirme →
          null (yeni gider; tutar yoksa sor). Aynı türden birden çok borç varsa en eskisini seç.
        - `due_date` (YYYY-MM-DD): ödemenin planlandığı/söz verildiği vade. Örn "…ödemesi 12.05.2026'da"
          veya "12.05.2026'da ödeyeceğim" → `due_date`=2026-05-12, `paid`=false. Vade yoksa null bırak.
        - ÖNEMLİ: `date` (harcamanın/giderin OLUŞTUĞU tarih) ile `due_date` (ödeme VADESİ) farklıdır.
          Ödeme ileride yapılacaksa gider tarihi bugündür (`date`=bugün) ve verilen tarih `due_date`'tir.
          Geçmişte "aldım/harcadım/ödedim" denen bir tarih ise o `date`'tir, `due_date`=null.

        {$context}
        PROMPT;
    }

    /** Sınırlı bağlam: yalnız son konuşulan cari — isimsiz atıfları ("ondan", "ona", "daha") çözer. */
    private function lastPartyContext(array $c): string
    {
        $all = (string) round(abs((float) $c['balance']), 2);

        return <<<CTX

        SON KONUŞULAN CARİ (son 30 dk): {$c['party_name']} (party_id {$c['party_id']}). Güncel durum: {$c['balance_note']}
        - Mesajda HİÇBİR kişi/cari adı yoksa ve mesaj bir kişiye işaret ediyorsa ("ondan", "ona", "onun", "kendisi",
          "… daha verdi / ödedi / verdim", "hepsini ödedim", "kalanını aldım", "borcunu kapattı") → `party_id`={$c['party_id']}.
          · Bu durumda "ondan X aldım" (mal/hizmet adı GEÇMİYORSA) PARA ALMAKTIR → "collection"; "ona X verdim/ödedim" → "payment".
          · "hepsini / tamamını / kalanını" ve tutar yoksa `amount`={$all} (güncel bakiye).
        - Mesajda BAŞKA bir kişi/cari adı geçiyorsa bu bağlamı TAMAMEN YOK SAY.
        - Mesaj bir kişiye işaret etmiyorsa ("5 bin yakıt aldım", "kirayı ödedim", "bu ay ne harcadım") bağlamı KULLANMA.
        - Bu bağlamdan proje / kategori / tutar TAŞINMAZ; yalnız cari.

        CTX;
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
                    'totals_side' => ['type' => ['string', 'null'], 'enum' => ['receivable', 'payable', 'both', null], 'description' => 'Only for kind=totals_query: which side was asked'],
                    'date_from' => ['type' => ['string', 'null'], 'description' => 'Only for kind=statement / expense_summary: period start (YYYY-MM-DD) if a period was named, else null'],
                    'date_to' => ['type' => ['string', 'null'], 'description' => 'Only for kind=statement / expense_summary: period end (YYYY-MM-DD) if a period was named, else null'],
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
                    'paid' => ['type' => ['boolean', 'null'], 'description' => 'true only if explicitly paid / payment proof document, false if explicitly unpaid or future, null if not stated'],
                    'confidence' => ['type' => 'string', 'enum' => ['high', 'low']],
                    'question' => ['type' => ['string', 'null'], 'description' => 'Question to ask the user for a missing project/party match, else null'],
                    'settles_expense_id' => ['type' => ['integer', 'null'], 'description' => 'Only for kind=expense: id from ÖDENMEMİŞ GİDERLER when the message pays that existing unpaid expense (not a new cost); else null'],
                    'payment_purpose' => ['type' => ['string', 'null'], 'enum' => ['advance', 'loan', null], 'description' => 'Only for kind=payment: advance (prepayment for future work) / loan (money lent, to be returned); null if not stated'],
                    'debt_side' => ['type' => ['string', 'null'], 'enum' => ['receivable', 'payable', 'unclear', null], 'description' => 'Only for kind=debt_note: receivable (they owe us) / payable (we owe them) / unclear (direction not stated — user is asked)'],
                    'reply' => ['type' => ['string', 'null'], 'description' => 'Only for kind=help: short Turkish guidance reply (max 3 sentences)'],
                    'is_new_entry' => ['type' => 'boolean', 'description' => 'Only meaningful when a previous draft is provided: true if the new message is a brand-new separate entry or a balance question (extract from scratch, ignore previous), false if it refines the previous draft. Default false.'],
                ],
                'required' => ['kind', 'amount', 'description', 'paid', 'confidence'],
            ],
        ];
    }

    private static function validDate(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
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
            'totals_side' => $kind === self::KIND_TOTALS_QUERY
                ? (in_array($input['totals_side'] ?? null, ['receivable', 'payable'], true) ? $input['totals_side'] : 'both')
                : null,
            'date_from' => in_array($kind, [self::KIND_STATEMENT, self::KIND_EXPENSE_SUMMARY], true) ? self::validDate($input['date_from'] ?? null) : null,
            'date_to' => in_array($kind, [self::KIND_STATEMENT, self::KIND_EXPENSE_SUMMARY], true) ? self::validDate($input['date_to'] ?? null) : null,
            'payment_type' => in_array($paymentType, ['cash', 'bank_transfer', 'eft', 'other'], true) ? $paymentType : null,
            'amount' => isset($input['amount']) ? (float) $input['amount'] : 0.0,
            'date' => $input['date'] ?? now()->format('Y-m-d'),
            // Ödeme tarihi: gider, satış ve alacak/borç kaydında (diğer türlerde anlamsız).
            'due_date' => in_array($kind, [self::KIND_EXPENSE, self::KIND_SALE, self::KIND_DEBT_NOTE], true)
                ? self::validDate($input['due_date'] ?? null) : null,
            'description' => (string) ($input['description'] ?? ''),
            // Projesiz kurulum: AI proje uydurup yeni proje açtırmasın.
            'project_id' => config('modules.projects') && isset($input['project_id']) ? (int) $input['project_id'] : null,
            'project_name' => config('modules.projects') && ! empty($input['project_name']) ? trim((string) $input['project_name']) : null,
            'party_id' => isset($input['party_id']) ? (int) $input['party_id'] : null,
            'party_name' => ! empty($input['party_name']) ? trim((string) $input['party_name']) : null,
            'category_id' => isset($input['category_id']) ? (int) $input['category_id'] : null,
            'category_name' => ! empty($input['category_name']) ? trim((string) $input['category_name']) : null,
            // null = belirtilmedi → varsayılan kontrolcüde (carili: ödenmedi, carisiz: ödendi).
            'paid' => isset($input['paid']) ? (bool) $input['paid'] : null,
            'confidence' => in_array($input['confidence'] ?? 'high', ['high', 'low'], true) ? ($input['confidence'] ?? 'high') : 'high',
            'question' => ! empty($input['question']) ? (string) $input['question'] : null,
            'is_new_entry' => (bool) ($input['is_new_entry'] ?? false),
            'settles_expense_id' => $kind === self::KIND_EXPENSE && ! empty($input['settles_expense_id']) ? (int) $input['settles_expense_id'] : null,
            'payment_purpose' => $kind === self::KIND_PAYMENT && in_array($input['payment_purpose'] ?? null, ['advance', 'loan'], true)
                ? $input['payment_purpose'] : null,
            // Yön belirsizse tahmin yok — kullanıcıya "kim kime borçlu?" sorulur.
            'debt_side' => $kind === self::KIND_DEBT_NOTE
                ? (in_array($input['debt_side'] ?? null, ['receivable', 'payable'], true) ? $input['debt_side'] : 'unclear')
                : null,
            'reply' => $kind === self::KIND_HELP && ! empty($input['reply']) ? trim((string) $input['reply']) : null,
        ];
    }
}
