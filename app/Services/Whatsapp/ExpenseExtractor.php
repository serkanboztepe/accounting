<?php

namespace App\Services\Whatsapp;

use App\Models\UsageLog;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
 *   purchase      — gider takibi KAPALI firmada "Ahmet'ten 10 bin mal aldım" → cari alış (paid → alış + ödeme)
 *   agenda        — "yarın ne var?" / "bu hafta neler var?" / "20 Ekim boş mu?" → reminders + due dates + checks, nothing saved
 *   reminder      — "20 Ekim düğün çekimim var" / "her ayın 10'unda kart ödemesi" → reminders after confirmation
 *                   (event_date, event_time, lead_minutes, is_alarm, repeat; reminder times computed in code)
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

    /** "Bugün", "yarın", "Cuma 14:00", "2 saat sonra" kullanıcının saatine göre yorumlansın. */
    private const TIMEZONE = 'Europe/Istanbul';

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
    public const KIND_REMINDER = 'reminder';
    public const KIND_AGENDA = 'agenda';
    public const KIND_PURCHASE = 'purchase';
    public const KIND_EMPLOYEE_SETTING = 'employee_setting'; // "Abdullah personelim, maaşı 50 bin, ayın 15'i" (EmployeeDraft)
    public const KIND_SALARY = 'salary';
    public const KIND_REMEMBER = 'remember';       // "bundan sonra kalfa deyince Mehmet'i anla" (MemoryDraft, onaylı)
    public const KIND_FORGET = 'forget';           // "kalfayı unut"
    public const KIND_MEMORY_LIST = 'memory_list'; // "neleri hatırlıyorsun" — kayıt yok                     // "Abdullah'ın maaşını verdim" → Maaş satırı + ödeme (avans düşülür)

    // Stoklu satış paketi (Satışlar/Alışlar tezgahı açık firmada; diğer firmalarda bu türler YOK):
    public const KIND_TRADE_SALE = 'trade_sale';         // satış belgesi (peşin carisiz / cariye), ürünlü ya da ürünsüz, toplu ciro
    public const KIND_TRADE_PURCHASE = 'trade_purchase'; // alış belgesi (katalogdaki mal → stoğa girer)
    public const KIND_STOCK_COUNT = 'stock_count';       // sayım: "un 9 kaldı"
    public const KIND_STOCK_QUERY = 'stock_query';       // "un ne kadar kaldı?" — kayıt yok
    public const KIND_STOCK_SETTING = 'stock_setting';   // "günde 3 kullanıyorum, 6 kala haber ver"
    public const KIND_MONTH_RESULT = 'month_result';     // "bu ay ne kazandım?" — kayıt yok
    public const KIND_PRODUCT_RENAME = 'product_rename'; // "stoktaki yemi besi yemi yap" (Oktay 17.10)

    public const KIND_PRODUCTION = 'production';         // "bu yıl 400 ton silaj ürettim" → maliyetsiz stok girişi (Oktay 16.10)

    public const KIND_PRODUCT_ADD = 'product_add';       // "un, maya, tuz kullanıyorum" → ürün kartı açılır (onayla)

    /** Stoklu satış türleri — yalnız bu firmalarda; kayıt yolu TradeDraft. */
    public const TRADE_KINDS = [self::KIND_TRADE_SALE, self::KIND_TRADE_PURCHASE, self::KIND_STOCK_COUNT,
        self::KIND_STOCK_QUERY, self::KIND_STOCK_SETTING, self::KIND_MONTH_RESULT, self::KIND_PRODUCT_ADD,
        self::KIND_PRODUCT_RENAME, self::KIND_PRODUCTION];

    private const KIND_HINTS = [
        self::KIND_REMEMBER => '- "remember": KALICI BİLGİ ÖĞRETİLİYOR, para hareketi yok ("bundan sonra kalfa deyince Mehmet Kaya\'yı anla", "unutma Recep\'le peynirin kilosunu 280\'den anlaştık", "un çuvalını hep 850\'den alıyoruz", "bana kısa yaz", "0553 Ergenekon kasası"). `memory_kind`: alias (kişi/takma ad/numara kimin) · agreement (anlaşma, karar, fiyat sözü) · price (fiyat/alışkanlık) · preference (kullanıcının asistan tercihi — nasıl konuşulacağı YA DA gelecekteki kayıtların nasıl yazılacağı: "Kaya Petrol fişlerinden plakayı da kaydet", "yakıt fişlerinde açıklamaya hep plakayı yaz", "Ali Usta ödemelerini açıklamaya işçilik diye yaz") · note. Kayıt yazımıyla ilgili kalıcı istek ("hep", "bundan sonra", "fişlerimde/fişlerden" — tekrar eden işler için) "help"/"yapamıyorum" DEĞİL → remember; tek bir kayda yönelik ("BU fişe plakayı ekle") remember DEĞİL, o kaydın düzeltmesidir. `memory_subject`: kısa konu ("kalfa", "Recep peynir fiyatı"). `memory_fact`: tek cümle bilgi ("Kalfa = Mehmet Kaya", "Recep Demirci ile peynir kilosu 280 TL"). İlgili cari/ürün varsa party_id / product_id. amount=0.',
        self::KIND_FORGET => '- "forget": öğretilen bir bilgiyi unut ("kalfayı unut", "Recep fiyatını sil"). `memory_subject` = aranacak kelime ("kalfa"). amount=0.',
        self::KIND_MEMORY_LIST => '- "memory_list": "neleri hatırlıyorsun", "bana ne öğrettim" — kayıt yok. amount=0.',
        self::KIND_EMPLOYEE_SETTING => '- "employee_setting": PERSONEL BİLGİSİ — çalışanın maaşı / maaş günü söyleniyor, para hareketi YOK ("Abdullah Günhan personelim, maaşı 50 bin, ayın 15\'inde", "Ali\'nin maaşı 55 bin oldu", "Mehmet işçimiz ayın 1\'i maaş alıyor"). `party_id` (listede yoksa `party_name`), `salary_amount` (aylık), `salary_day` (ayın kaçı); söylenmeyen null; amount=0. Asistan az önce "maaşı ne kadar, ayın kaçında?" diye sorduysa cevap ("45 bin, ayın 5\'i", "45 bin") o kişi için employee_setting\'tir. "Maaşından ne kadar kaldı / ne kadar vereceğim" SORUSU kayıt değildir: CARİLER\'deki o kişinin notundaki "maaştan kalan" / "açık avans" rakamıyla cevap ver (hesap yapma).',
        self::KIND_SALARY => '- "salary": PERSONELE MAAŞ — bir çalışanın maaşı ödendi / hak edildi ("Abdullah\'ın maaşını verdim", "Ali\'ye Ekim maaşı 50 bin ödedim", "işçi maaşları: Mehmet 40 bin"). `party_id`/`party_name` = çalışan, `amount` = MAAŞIN TAMAMI (söylenmediyse 0 — karttaki maaş kullanılır), `paid` true (verildiyse) / false ("maaşı hak etti, henüz vermedim"), `month` = hangi ayın maaşı (YYYY-MM, söylendiyse). YEVMİYE / günlük / haftalık işçi ücreti de bu türdür ("İşçi Hasan\'a 2000 yevmiye verdim" → salary, amount 2000, paid true, description "yevmiye"). Avans DÜŞÜLMÜŞ tutarı yazma; sistem açık avansı kendisi düşer. AVANS maaş değildir → "payment" + payment_purpose "advance".',
        self::KIND_EXPENSE => '- "expense": CARİSİZ genel harcama — belli bir kişi/firma hesabına bağlı OLMAYAN masraf ("5 bin yakıt", "kira 20 bin ödendi", "elektrik faturası", "yemek 800", "markette 2 bin"). Ödendi de olsa ödenmedi de olsa gider budur. Bir KİŞİDEN/FİRMADAN (cari) mal ya da hizmet alındıysa ("Ahmet\'ten 100 bin malzeme aldım", "Abdullah Usta\'ya sıva yaptırdım") bu gider DEĞİL, "purchase"tır.',
        self::KIND_PAYMENT => '- "payment": BİZ bir cariye PARA VERDİK, ama yeni bir mal/hizmet tarif edilmiyor ("Ahmet\'e 100 bin ödedim", "Kuşak Beton\'a 50 bin havale ettim", "ustaya 20 bin verdim"). Önceki borcu kapatır. YÖN: "X avans aldı", "X maaş aldı", "X\'e avans verdik" → para BİZDEN ÇIKTI → payment (X\'in "aldı" demesi tahsilat DEĞİLDİR; tahsilat yalnız X bize para VERDİYSE). Kişi BİZİM ÇALIŞANIMIZSA (anlamdan çıkar — personel, eleman, işçi, çoban, şoför, kalfa, çırak, "maaşından verdim" … ya da CARİLER\'de "personel") `is_staff`=true; çalışana verilen avans maaş avansıdır (payment_purpose "advance"). Çalışana amacı söylenmeden ödeme → `question`: "Maaş mı, avans mı?". Paranın NE İÇİN verildiği söylendiyse `payment_purpose`: ileride yapılacak iş ya da sonra gelecek mal için peşin ("avans", "kapora", "iş yaptıracağım", "bağlantı" / "bağlantısı ödendi" — malı önceden parasını verip sabitlemek, "ön ödeme", "peşin verdim mal sonra gelecek") → "advance" (ör. "Sinan\'a yapılan 2026 bağlantısı 2.500.000 ödendi" → payment, advance); geri alınacak ödünç ("borç verdim", "ödünç", "geri alacağım") → "loan". Verilen para yapılmış bir işin / gelmiş malın karşılığıysa ("sıva yaptırdım", "malzeme aldım ödedim") bu bir "purchase"tır (paid=true). Söylenmediyse null. GELECEK ZAMAN ödeme DEĞİLDİR — para henüz verilmedi ("Yusuf\'a 67 bin vereceğim", "vereceğim var", "ödeyeceğim"): biz ona borçluyuz → "debt_note" `debt_side`="payable" (ne alındığı söylendiyse alış/gider, ödenmedi). "Borç aldım" da ödeme DEĞİLDİR (bkz. debt_note).',
        self::KIND_SALE => '- "sale": BİZ bir cariye İŞ YAPTIK / SATTIK, karşılığında o bize borçlanır ("Ahmet X\'e 80 bine proje yaptım", "şantiye şefliği 30 bin"). Bir cariye MAL VERDİYSEK de satıştır: "X\'e verilen / X\'e verdim / X\'e gitti" + mal adı ya da miktarı ("Şeker\'in kardeşine verilen peynir 25 kilo 15 bin", "Ali\'ye 10 kilo bal verdim 6 bin") → "sale" — bu bir gider ya da ödeme DEĞİLDİR (biz mal ALMADIK, para VERMEDİK). "X\'e verdim" yalnız PARA ise ("ustaya 20 bin verdim") → "payment". "X\'e veresiye" / "X\'e veresiye yazdım" ("Ayşe hanıma 1.250 lira veresiye") → "sale", `paid`=false — veresiye = malı verdik, parası sonra alınacak; gider ya da borç kaydı DEĞİLDİR. Parası hemen alındıysa ("parasını aldım", "peşin sattım", "nakit aldım") ve mal VERİLDİYSE `paid`=true (peşin satış: cari borçlanmaz); söylenmediyse null. Para alındı ama mal/iş SONRA verilecekse ("parasını aldım, peyniri sonra göndereceğim", "kapora aldım", "peşin verdi, malı sonra götüreceğim") bu satış DEĞİL → "collection" + `payment_purpose`="advance".',
        self::KIND_COLLECTION => '- "collection": CARİ BİZE PARA VERDİ ("Ahmet X 50 bin ödedi", "Ahmet\'ten 20 bin tahsil ettim"). DİKKAT: "ödedim" (biz verdik → payment) ile "ödedi" (o verdi → collection) farklıdır. Para, mal/iş henüz VERİLMEDEN peşin alındıysa (müşteri avansı: "Zülfikar 3 bin verdi, peyniri sonra vereceğim", "kapora aldım", "parasını aldım malı sonra göndereceğim") → `payment_purpose`="advance", `description` ne verilecekse ("20 kg peynir"). Müşteri bize alacaklı olur; satış AÇMA.',
        self::KIND_BALANCE_QUERY => '- "balance_query": kayıt değil, SORU ("Ahmet\'e ne kadar borcum var?", "Ahmet\'in bakiyesi ne?"). Belirli bir cari ADI şart; isim yoksa ("Borcum ne kadar", "Tüm borç", "Alacağım ne kadar") → "totals_query". Hiçbir şey kaydedilmez; amount=0.',
        self::KIND_DEBT_NOTE => '- "debt_note": işlem fiili OLMADAN söylenen bir ALACAK/BORÇ durumu — eski/devreden hesap, veresiye ("Ali\'den 40 bin alacağım var", "Ahmet bana 20 bin borçlu", "Mehmet\'e 15 bin borcum var", "Kuşak Beton\'a 50 bin borçluyum"). `debt_side`: o bize borçlu → "receivable", biz ona borçluyuz → "payable"; yön fiilden AÇIKÇA anlaşılmıyorsa ("Serkan adına 10000 borç", "Ali 5 bin borç", "Veli ile 3 bin hesap") → "unclear" — TAHMİN ETME, kullanıcıya sorulur. Cari zorunlu (isim yoksa `question`="Kimden alacağın var? Adını yazar mısın?" ya da "Kime borcun var? Adını yazar mısın?"). Soru cümlesi DEĞİLDİR ("Ali\'ye ne kadar borcum var?" → balance_query). PARAYI BİZ ALDIYSAK ve geri vereceksek ("Ali\'den 10 bin borç aldım", "Ali\'den ödünç aldım", "Ali\'den 5 bin daha borç aldım") → "debt_note", `debt_side`="payable" (biz ona borçlandık), `description`="Borç alındı" — bu "payment"/"loan" (borç VERDİM) DEĞİLDİR, yön terstir. Gelecek zamanda verilecek para ("Ahmet\'e 20 bin vereceğim", "vereceğim var") da "payable"dır. BORCU ÖDEDİYSEK bu borç kaydı DEĞİL, ÖDEMEDİR: "Hazal\'a borcum ödendi", "Ali\'ye borcumu ödedim", "borcumu kapattım", "borcumu iade ettim" → "payment" + `debt_repayment`=true (sistem kayıtlı borcu kapatır; kayıtlı borç yoksa eski borç + ödeme yazar, "ne için verdin?" sorulmaz).',
        self::KIND_PARTY_LIST => '- "party_list": carilerin LİSTESİ isteniyor, belirli bir isim yok ("Hangi carim var?", "Carilerimi göster", "Cari hesabı kontrol et", "Kimlerle hesabım var?"). Hiçbir şey kaydedilmez; amount=0.',
        self::KIND_PURCHASE => '- "purchase": bir CARİDEN mal/hizmet ALDIK ("Ahmet\'ten 10 bin mal aldım", "Kuşak\'tan 20 bin malzeme aldım, 15 Kasım\'da ödeyeceğim", "Mehmet\'ten 5 bin yem aldım nakit ödedim"). Cari zorunlu. `paid`: "ödedim / nakit / peşin / havale" → true; "sonra ödeyeceğim / veresiye / borç" ya da hiç söylenmediyse false. Ödeme tarihi söylendiyse `due_date`. Bu bir ÖDEME DEĞİLDİR ("Ahmet\'e 10 bin ödedim" → payment).',
        self::KIND_AGENDA => '- "agenda": TAKVİM / PROGRAM sorusu — bir günde ya da aralıkta ne var ("Yarın ne var?", "Bu hafta neler var?", "20 Ekim boş mu?", "Pazartesi programım ne?", "Bu ay ödemelerim ne zaman?"). Hiçbir şey kaydedilmez; amount=0. `date_from`/`date_to` (YYYY-MM-DD): tek gün sorulduysa ikisi de o gün; "bu hafta" / "önümüzdeki günler" → bugün / bugün+6 (hafta sonu sorulsa da 7 gün); "gelecek hafta" → gelecek Pazartesi / Pazar; "bu ay" → bugün / ay sonu; söylenmediyse ikisi de null (önümüzdeki 7 gün). Harcama sorusu ("bu hafta ne harcadım") DEĞİLDİR → "expense_summary"; yeni bir olay/hatırlatma bildirimi ("20 Ekim\'de düğünüm var") DEĞİLDİR → "reminder".',
        self::KIND_REMINDER => '- "reminder": HATIRLATMA isteği ya da hatırlatılacak bir OLAY/RANDEVU/ÖDEME GÜNÜ ("20 Ekim\'de düğün çekimim var", "yarın saat 11\'de kuaförüm var, 2 saat önce hatırlat", "kredi kartına her ayın 10\'unda ödemem var", "sıvacı 10 gün sonra gelecek, hatırlat", "Cuma Ali\'den parayı almayı hatırlat", "2 saat sonra hatırlat: ustayı ara", "kasko her yıl 3 Mart\'ta"). Kayıt değil; amount=0. `description` = olay/iş KISA ("Düğün çekimi", "Kuaför randevusu", "Kredi kartı ödemesi", "Ali\'den parayı al"). `event_date` (YYYY-MM-DD) olay günü (gün adı → bugünden sonraki ilk o gün; "10 gün sonra" → bugün+10; "her ayın 10\'u" → bugünden sonraki ilk 10\'u, bugün 10\'uysa bugün). `event_time` ("HH:MM") SADECE saat söylendiyse; "2 saat sonra" → şu an+2 saat. `lead_minutes` SADECE "ne kadar önce" söylendiyse ("2 saat önce" → 120, "2 gün önce" → 2880, "1 hafta önce" → 10080), yoksa null — varsayılanı sistem uygular, SEN HESAPLAMA. `is_alarm`=true: kullanıcı tam o anda hatırlatılmak istiyor, ortada ayrı bir olay yok ("saat 15\'te hatırlat: Ahmet\'i ara", "2 saat sonra hatırlat"); olay/randevu/ödeme günü ise false. `repeat`: "her hafta / her pazartesi" → "weekly", "her ay / her ayın 10\'u" → "monthly", "her yıl" → "yearly", yoksa null; "her gün" desteklenmez → `question`="Şimdilik haftalık, aylık ya da yıllık tekrar kurabiliyorum.". Cari geçiyorsa `party_id` (listede yoksa party_name YAZMA). Gün belli değilse ("sonra hatırlat") `event_date`=null, `question`="Ne zaman hatırlatayım?". DİKKAT: "Ali\'ye 45 bin sattım, 14 Kasım\'da ödeyecek" bir SATIŞTIR (sale + due_date), reminder değil.',
        self::KIND_HELP => '- "help": yukarıdaki türlerin HİÇBİRİNE uymayan mesaj — selam, teşekkür, "ne yapabilirsin", "cari hesap kayıt" gibi yarım/anlaşılmayan istekler, desteklenmeyen işler. Hiçbir şey kaydedilmez; amount=0. `reply` alanına KISA (en fazla 3 cümle), samimi Türkçe bir cevap yaz: ne anladığını söyle ve yapabildiğin bir işe ÖRNEK CÜMLEYLE yönlendir (örnekler yalnız bu listedeki türlerden). Yapamadığın şeyi yapabilirmiş gibi, kayıt yapmışsın gibi SÖYLEME (cariye / üçüncü kişiye mesaj gönderemezsin, "iletebilirim" deme). Bir cariye mesaj ya da ekstre gönderme isteği ("Ferdi\'ye mesaj at") "help" DEĞİL → "statement" + `forward_to_party`=true. Tutarı ya da ismi eksik bir İŞLEM ise "help" SEÇME — o türü seç ve `question` ile eksiği sor.',
        self::KIND_TOTALS_QUERY => '- "totals_query": TÜM CARİLER için toplam SORUSU, belirli bir cari YOK ("Toplam alacağım ne kadar?", "Kimden alacağım var?", "Toplam borcum ne?", "Kime borçluyum?", "Genel durum ne?"). GİDER/HARCAMA/MASRAF sorusu bu DEĞİLDİR (→ "expense_summary"). Hiçbir şey kaydedilmez; amount=0. `totals_side`: alacak sorusu → "receivable", borç sorusu → "payable", genel/ikisi → "both". Proje söylendiyse `project_id`.',
        self::KIND_EXPENSE_SUMMARY => '- "expense_summary": bir DÖNEMDE ne kadar HARCANDIĞI sorusu ("Bu ay ne kadar giderim var?", "Gider ?", "Giderlerim", "Masraflar ne durumda?", "Eylül\'de ne harcadım?", "Bu yıl toplam masrafım ne?", "Cumhuriyet\'te bu ay ne harcadım?", "Geçen ay yakıta ne verdim?"). Borç/alacak sorusu DEĞİL. Hiçbir şey kaydedilmez; amount=0. Dönem: `date_from`/`date_to` ("bu ay" → ayın 1\'i / bugün; "geçen ay" → geçen ayın 1\'i / son günü; "Eylül" → bu yılın 09-01 / 09-30; "bu yıl" → 01-01 / bugün); dönem söylenmediyse ikisi de null (bu ay sayılır). Proje söylendiyse `project_id`.',
        self::KIND_TRADE_SALE => '- "trade_sale": BİZ MAL/ÜRÜN SATTIK ya da günün CİROSU. ("30 ekmek sattım", "Lokantaya 50 ekmek veresiye verdim", "Ali\'ye 4 lastik + montaj 12 bin", "bugün 10.000 nakit 15.000 kart", "bugünkü ciro 25 bin"). Ürünler aşağıdaki ÜRÜNLER listesinden: her biri `items`\'a {product_id, description, quantity, unit_price?, amount?}; listede yoksa product_id null ve description\'a adı. Fiyat söylenmediyse unit_price/amount boş bırak (karttaki fiyat kullanılır). Ürün sayılmadan sadece tutar ("bugün 25 bin ciro") → items boş, `amount` toplam. Nakit/kart ayrı söylendiyse `cash_amount` / `card_amount`. CARİ: kişi/firma adı geçiyorsa (veresiye) `party_id`/`party_name`, `paid`=false; "peşin / nakit aldım / parasını aldım" denirse `paid`=true; kişi adı yoksa `paid`=true (peşin, cari sorma). Mal verip parası sonra alınacaksa bu "debt_note" DEĞİL, "trade_sale"dir.',
        self::KIND_TRADE_PURCHASE => '- "trade_purchase": STOKTA TUTULAN bir MAL/HAMMADDE ALDIK ya da GELDİ — stoğa girer ("Ak Un\'dan 20 çuval un aldım", "10 çuval un aldım 8.500 nakit ödedim", "unlar geldi 35 çuval, çuvalı 900 olmuş", "Lastikçi Ali\'den 8 lastik aldım"). `items` aynı biçimde (product_id + quantity + varsa unit_price/amount); fiyat "tanesi/çuvalı X" ise unit_price, toplam söylendiyse amount; söylenmediyse ikisi de null (son alış fiyatı kullanılır). ÜRÜNLER listesinde yoksa da adet/çuval/kg/paket ile sayılan mal ise trade_purchase: product_id null, `unit` = birim ("çuval", "kg") — sistem ürün kartı açmayı sorar. Kimden alındıysa `party_id`/`party_name`; söylenmediyse ikisi de null (son alınan yer kullanılır). `paid`: "ödedim / nakit / peşin / havale" → true (alış + ödeme birlikte); "veresiye / sonra ödeyeceğim" → false; söylenmediyse null. Tüketilen MASRAF (mazot, kira, fatura, yemek, temizlik) bu DEĞİL → "expense".',
        self::KIND_STOCK_COUNT => '- "stock_count": SAYIM — elde kalan miktar söyleniyor ("un 9 çuval kaldı", "elimde 12 lastik var", "maya 3 paket"). `product_id` (ÜRÜNLER listesinden — yalnız AYNI ürünse; "buzağı yemi" listedeki "Yem" DEĞİLDİR; yoksa null + `product_name` = yazılan ad ve `unit`) ve `counted_quantity`. Kayıt sistemdeki stoğu bu sayıya eşitler. Soru biçimindeyse ("un ne kadar kaldı?") bu DEĞİL → "stock_query". Aynı mesajda günlük kullanım da varsa ("günde 16 adet gidiyor") onu `next_items` alanına yaz (sonra stock_setting olarak önerilir) — "not aldım" DEME.',
        self::KIND_STOCK_QUERY => '- "stock_query": STOK SORUSU ("un ne kadar kaldı?", "stokta ne var?", "hangi mallar bitiyor?"). Belirli ürün varsa `product_id`, yoksa null (tüm liste). Hiçbir şey kaydedilmez; amount=0.',
        self::KIND_STOCK_SETTING => '- "stock_setting": GÜNLÜK KULLANIM / UYARI / ALIŞ FİYATI AYARI ("unu günde 3 çuval kullanıyorum, 6 kala haber ver", "maya 2 paket kalınca haber ver", "unu çuvalı 850\'den alıyorum"). `product_id` (listede yoksa null + `product_name` ve `unit`), `daily_usage` (her gün aynı miktar kullanılıyorsa), `reorder_level` (kaç kalınca haber verilsin), `purchase_price` (birim alış fiyatı). Söylenmeyeni null bırak; amount=0. O an bir mal GELDİYSE/ALINDIYSA bu değil → "trade_purchase".',
        self::KIND_PRODUCT_RENAME => '- "product_rename": ÜRÜN KARTININ ADI DEĞİŞİYOR ("stoktaki yemi besi yemi yap", "Yem adını Besi yemi olarak değiştir"). `product_id` = ÜRÜNLER listesindeki MEVCUT kart, `product_name` = YENİ ad. Stok ve geçmiş aynen kalır; amount=0. Taslaktaki bir kalemi düzeltmek bu DEĞİL.',
        self::KIND_PRODUCTION => '- "production": ÜRETİM — kendi ürettiğimiz/yetiştirdiğimiz mal stoğa giriyor; alış ya da gider DEĞİL, para yok ("bu yıl 400 ton silaj, 15 ton saman ürettim", "50 kg peynir yaptık", "yonca 3. biçim 25.000 kg"). `items`: her ürün {description=ad, quantity, unit, product_id = ÜRÜNLER listesinde AYNI ürün varsa}. amount=0.',
        self::KIND_PRODUCT_ADD => '- "product_add": STOKTA TUTULACAK ÜRÜN(LER) TANITILIYOR, alış/satış yok ("un, maya, tuz, şeker kullanıyorum", "ürün ekle: un çuval", "simit de satıyorum"). `items`: her ürün {description=ad, unit=birim ("çuval","kg","paket","adet"; söylenmediyse null), unit_price=alış fiyatı söylendiyse, quantity=ELDEKİ MİKTAR söylendiyse ("400 ton saman ekle" → 400; açılış stoğu olur)}. ÜRÜNLER listesinde olanları yazma. amount=0.',
        self::KIND_MONTH_RESULT => '- "month_result": CİRO / KAZANÇ / KALAN sorusu ("bu ay ne kazandım?", "Eylül ne kaldı?", "ciro ne kadar?", "bugünkü ciro", "dünkü cirolar", "14.10 toplam ciro", "iki günlük ciro ve gider", "bu hafta ne kaldı", "toplam kalan para"). GÜN ya da ARALIK söylendiyse `date_from` / `date_to` (YYYY-MM-DD; "bugün" → bugün/bugün, "dün" → dün/dün, "14.10" → o gün, "iki günlük" → dün/bugün, "bu hafta" → pazartesi/bugün); yalnız AY söylendiyse `month` (YYYY-MM); hiçbiri yoksa ikisi de null (= bu ay). Hiçbir şey kaydedilmez; amount=0. Kasa/şube adı geçse de ("Araştırma kasa ne kadar", "dünkü Ergenekon ciro") bu bir CİRO sorusudur, cari bakiyesi DEĞİL.',
        self::KIND_STATEMENT => '- "statement": EKSTRE / hesap dökümü İSTEĞİ ("Ali\'nin ekstresini at", "Kuşak Beton ekstresi", "Ali\'nin Eylül ekstresi", "Ali\'nin Cumhuriyet ekstresi"). Yazım hataları ve eş anlamlılar da ekstredir — SORMA, gönder: "ekstra", "extra", "extre", "ektre", "ekstire", "ekistre", "eksre", "döküm", "hesap dökümü", "hareketleri", "kişi adı + pdf" ("Hazal doğan ekstra", "Ferhat extre gönder", "Serkan pdf", "Ali döküm" → statement). Hiçbir şey kaydedilmez; amount=0. Dönem söylendiyse `date_from`/`date_to` (ör. "Eylül" → bu yılın 09-01 / 09-30; "bu ay" → ayın 1\'i / bugün; "2026" → 01-01 / 12-31), proje söylendiyse `project_id`. Kullanıcı ekstrenin ya da bir mesajın CARİYE (üçüncü kişiye) gönderilmesini istiyorsa ("Ferdi\'ye at", "Ferdi Cengiz\'e mesaj at", "ekstresini ona gönder", "müşteriye yolla") → yine "statement" ve `forward_to_party`=true (biz cariye mesaj atamayız, kullanıcıya iletmesi söylenir).',
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
            self::KIND_REMINDER,
            self::KIND_AGENDA,
            self::KIND_REMEMBER,
            self::KIND_FORGET,
            self::KIND_MEMORY_LIST,
            // Personel: maaş/avans ödemesi var → ödeme açık firmalarda (mimar paketi değişmez — Ferhat kararı).
            config('modules.cari_supplier') ? self::KIND_EMPLOYEE_SETTING : null,
            config('modules.cari_supplier') ? self::KIND_SALARY : null,
            // Tedarikçi tarafı açık: "Ahmet'ten mal aldım" alış olur (cari hesabı), gider değil — gider yalnız carisiz
            // harcama. Gider kapalı firmada yoksa en yakın kalıp "ödeme" seçiliyor, yön ters çıkıyordu (Burak, 2026-10-10).
            config('modules.cari_supplier') ? self::KIND_PURCHASE : null,
            // Stoklu satış paketi: yalnız Satışlar/Alışlar tezgahı açık firmada (mevcut firmalarda hiçbiri yok).
            config('modules.sales_desk') ? self::KIND_TRADE_SALE : null,
            config('modules.purchases') && config('modules.stock') ? self::KIND_TRADE_PURCHASE : null,
            self::tradeStock() ? self::KIND_STOCK_COUNT : null,
            self::tradeStock() ? self::KIND_STOCK_QUERY : null,
            self::tradeStock() ? self::KIND_STOCK_SETTING : null,
            self::tradeStock() ? self::KIND_PRODUCT_ADD : null,
            self::tradeStock() ? self::KIND_PRODUCT_RENAME : null,
            self::tradeStock() ? self::KIND_PRODUCTION : null,
            config('modules.report_monthly') ? self::KIND_MONTH_RESULT : null,
        ]));
    }

    /**
     * AI'ya sunulan türler. Stoklu alış (Mal alışı) açık firmada basit cari alışı GÖSTERİLMEZ: "Ak Un'dan 20 çuval un
     * aldım" stoğa girmeyen basit alışa düşmesin. Carili kayıt yine cariye gider — AI gider seçer, fitToModules
     * (TradeDraft::route sonrası) ürünsüz carili gideri basit alışa çevirir.
     *
     * @return list<string>
     */
    public static function aiKinds(): array
    {
        $kinds = self::allowedKinds();
        if (in_array(self::KIND_TRADE_PURCHASE, $kinds, true)) {
            $kinds = array_values(array_diff($kinds, [self::KIND_PURCHASE]));
        }

        return $kinds;
    }

    /** Stok türleri (sayım / soru / ayar) yalnız stoklu satış paketinde — Yeni Yapı gibi eski stoklu firmada değişiklik yok. */
    private static function tradeStock(): bool
    {
        return config('modules.stock') && (config('modules.sales_desk') || config('modules.purchases'));
    }

    /**
     * Gider takibi kapalı firmada gider taslağı ASLA kalmasın. Eskiden AI izinsiz tür döndürünce (ya da "Ne için
     * verdin?" → "1"/"malzeme aldım") taslak gidere düşüyordu — Ferhat (10 Ekim) ve Burak (11 Ekim) giderleri.
     * Carisi belliyse ve tedarikçi tarafı açıksa alış olur; değilse kayıt yok, açıklama döner.
     */
    public static function fitToModules(array $d): array
    {
        // Stoklu satış paketi: hedef (gider / alış belgesi / satış belgesi) KODDA, katalog eşleşmesine göre.
        $d = TradeDraft::route($d);

        if (($d['kind'] ?? self::KIND_EXPENSE) !== self::KIND_EXPENSE) {
            return $d;
        }

        // Carisi olan kayıt gidere değil carinin hesabına yazılır (alış); gider yalnız carisiz harcama.
        // "Sinan'a bağlantı ödendi" carili gider olunca hem gider şişti hem ekstre "hesap kapalı" dedi (Taner, 13 Ekim).
        // İstisna: eski ödenmemiş carili giderin ödemesi (settles_expense_id) gider yolunda kapanır.
        if (in_array(self::KIND_PURCHASE, self::allowedKinds(), true) && (! empty($d['party_id']) || ! empty($d['party_name']))
            && empty($d['settles_expense_id'])) {
            $d['kind'] = self::KIND_PURCHASE;
            $d['category_id'] = $d['category_name'] = $d['settles_expense_id'] = null;
            if (! config('modules.projects')) {
                $d['project_id'] = $d['project_name'] = null;
            }
            $d['paid'] = (bool) ($d['paid'] ?? false); // carili ve ödendiği söylenmemiş → ona borçlandın

            return $d;
        }

        if (config('modules.expenses')) {
            return $d;
        }

        // Kişi var ama adı yok ("yemciden 12 bin yem aldım" → "Hangi yemci?"): alış olarak sor, harcama sanma (Erkan, 12 Ekim).
        if (in_array(self::KIND_PURCHASE, self::allowedKinds(), true) && ! empty($d['question'])) {
            $d['kind'] = self::KIND_PURCHASE;
            $d['category_id'] = $d['category_name'] = $d['settles_expense_id'] = null;
            $d['project_id'] = $d['project_name'] = null;
            $d['paid'] = (bool) ($d['paid'] ?? false);

            return $d;
        }

        $d['kind'] = self::KIND_HELP;
        $d['reply'] = 'Bu hesapta harcama takibi kapalı, sadece kişi/firma (cari) hesapları tutuluyor.'
            . (in_array(self::KIND_PURCHASE, self::allowedKinds(), true) ? ' Bir kişiden aldıysan adıyla yaz, örneğin: "Ahmet\'ten 10 bin mal aldım".' : '')
            . (in_array(self::KIND_SALE, self::allowedKinds(), true) ? ' Satış olarak yazmak istersen: "Ahmet\'e 50 bin proje yaptım".' : '');

        return $d;
    }

    /**
     * @param  array{media_type:string,data:string}|null  $image  base64 image
     * @param  array|null  $previous  önceki taslak — verilirse yeni mesaj DÜZELTME sayılır
     * @param  array{party_id:int,party_name:string,balance:float,balance_note:string}|null  $lastParty
     *         son 30 dk'da konuşulan cari (ConversationContext) — "ondan / ona / daha / hepsini" ona bağlanır
     * @param  string|null  $phone  yazan numara — numaraya özel hafıza (tercih: "fişte plakayı yaz") fişte de uygulansın
     */
    public function extract(?string $text, ?array $image = null, ?array $previous = null, ?array $lastParty = null, ?string $phone = null): array
    {
        $apiKey = config('services.anthropic.api_key');
        if (empty($apiKey)) {
            throw new RuntimeException('ANTHROPIC_API_KEY tanımlı değil (.env).');
        }

        $content = [];
        if ($image !== null) {
            $content[] = [
                // PDF (dekont) belge olarak, diğerleri resim olarak.
                'type' => $image['media_type'] === 'application/pdf' ? 'document' : 'image',
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
                    ? "Kullanıcının mesajı:\n" . $userText
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
            'system' => $this->systemPrompt($previous, $lastParty, $phone),
            'tools' => [$this->tool()],
            'tool_choice' => ['type' => 'tool', 'name' => 'save_entry'],
            'messages' => [
                ['role' => 'user', 'content' => $content],
            ],
        ]);

        if ($response->failed()) {
            \App\Support\CreditAlert::fromApiError($response->body()); // kredi bittiyse yöneticiye hemen haber
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

    /** Son 3 saatlik konuşma (≤8 mesaj, saatli) — belge okurken bağlam. */
    private static function recentConversation(string $phone): string
    {
        $rows = WhatsappMessage::where('phone', \App\Support\Phone::normalize($phone))->whereNotNull('body')->where('body', '!=', '')
            ->where('created_at', '>=', now()->subMinutes(ChatAssistant::HISTORY_MINUTES))->orderByDesc('id')->limit(8)->get()->reverse();
        if ($rows->isEmpty()) {
            return '';
        }

        return "\nSON KONUŞMA (belge/fotoğraf bunun devamı olabilir — ör. \"Osman'a çekleri verdim\" yazıldıysa çek fotoğrafı o ödemedir; "
            . "konuşulan kişi ve işi kullan, yine de belgeyi esas al):\n"
            . $rows->map(fn ($m) => '  [' . $m->created_at->copy()->timezone(self::TIMEZONE)->format('H:i') . '] '
                . ($m->direction === 'in' ? 'Kullanıcı' : 'Asistan') . ': ' . mb_substr(str_replace("\n", ' / ', (string) $m->body), 0, 200))->implode("\n") . "\n";
    }

    private function systemPrompt(?array $previous = null, ?array $lastParty = null, ?string $phone = null): string
    {
        $now = now(self::TIMEZONE)->locale('tr');
        $today = $now->format('Y-m-d') . ' (' . $now->translatedFormat('l') . ', saat ' . $now->format('H:i') . ', İstanbul)';
        $context = ExpenseContext::build($phone);
        // Fiilsiz/yönü belirsiz mesaj ("Abdullah Uçar 2 milyon kaba inşaat"): müteahhitte bu bir
        // maliyettir; satış yalnız açık fiille. Gider kapalıysa (mimar) varsayılan satış.
        $ambiguousRule = in_array(self::KIND_EXPENSE, self::allowedKinds(), true)
            ? '- Fiil YOKSA ya da yön belirsizse ("Abdullah Uçar 2 milyon kaba inşaat", "Ahmet 50 bin sıva") → '
                . (in_array(self::KIND_PURCHASE, self::aiKinds(), true) ? '"purchase" (cariye yaptırdığımız iş = alış)' : '"expense" (yaptırdığımız iş = maliyet)')
                . '. "sale" SADECE açık fiille: "yaptım", "sattım", "fatura kestim", "iş yaptım" — cari daha önce müşteri olarak görünse bile fiil yoksa satış SEÇME.'
            : '- Fiil YOKSA ("Ali Bey 80 bin proje") → "sale" (yaptığımız iş).';
        // Banka dekontu (FAST/EFT/havale, PDF ya da fotoğraf): kişiye/kişiden para → ödeme/tahsilat; banka ücreti ayrı (Taner, 13 Ekim).
        $receiptRule = '- BANKA DEKONTU (FAST/EFT/havale; PDF ya da fotoğraf): GÖNDEREN biz (kullanıcı/firma) ve ALICI bir kişi/firma ise → "payment", '
            . 'ALICI biz ve GÖNDEREN başkası ise → "collection". Cari = karşı taraf (ALICI ya da GÖNDEREN adı; listede benzeri varsa onunla eşleştir). '
            . '`amount` = İŞLEM TUTARI (FAST/EFT ücreti, BSMV, masraf HARİÇ — "TOPLAM" satırı DEĞİL). Ücret + BSMV toplamı varsa `bank_fee`. '
            . '`date` = işlem tarihi, `payment_type` = "eft", `description` = dekonttaki AÇIKLAMA (yoksa "Havale/EFT"). '
            . 'ALICI ya da GÖNDEREN adı yukarıdaki BİZ listesindeyse o taraf BİZİZ — cari = DİĞER taraf (bize gelen para → "collection", cari = gönderen). '
            . 'Açıklama ya da kullanıcı "borç iade / borcum ödendi" diyorsa `debt_repayment`=true. Kullanıcının mesajı varsa önce o (bekleyen taslağa ek bilgi olabilir).';
        // Fiş/fatura: satıcı adı her zaman `seller_name`; akaryakıtta plaka açıklamaya (Ferhat, 15 Ekim: "plakayı fişten oku").
        $receiptRule .= "\n" . '- FİŞ / FATURA (alışveriş belgesi): fişteki SATICI firmanın adını HER ZAMAN `seller_name`\'e yaz. AKARYAKIT fişinde '
            . '`description` = litre + yakıt türü + PLAKA (fişte "PLAKA" yazar; ör. "55,45 lt Kurşunsuz 95 — 24 FC 004"). '
            . 'ÖDEME SATIRI (TOPLAM altı): "KREDİ(CH)", "CH", "CARİ", "CARİ HESAP", "VERESİYE", "AÇIK HESAP" → veresiye: `paid`=false '
            . '(DİKKAT: "KREDİ(CH)" kredi kartı DEĞİL, istasyonun cari hesabıdır); "NAKİT", "KREDİ KARTI", "K.KARTI", "KART", "POS" → `paid`=true; '
            . 'ödeme satırı yoksa ya da okunmuyorsa `paid`=null (kullanıcı ayrıca söylemediyse).';
        if (! in_array(self::KIND_EXPENSE, self::allowedKinds(), true) && in_array(self::KIND_PURCHASE, self::allowedKinds(), true)) {
            // Gider kapalı: fiş = satıcıdan ALIŞ (cari = satıcı). Ödendi mi sistem karar verir (önceki alışlar / soru).
            $receiptRule .= "\n" . '- GİDER KAPALI: FİŞ / FATURA → "purchase", cari = SATICI (listede benzeri varsa onunla eşleştir, yoksa `party_name` = satıcı adı). '
                . '`paid`: önce kullanıcının sözü ("ödedim / nakit" → true, "veresiye / ödemedim" → false), yoksa fişin ödeme satırı (yukarıdaki kural), o da yoksa null. "help" CEVABI VERME.';
        }
        $photoRule = in_array(self::KIND_EXPENSE, self::allowedKinds(), true)
            ? $receiptRule . "\n" . '- Fotoğraf (fiş/fatura/çek) → her zaman "expense". Fişteki/faturadaki SATICI (market, akaryakıt, mağaza, restoran) cari DEĞİLDİR: '
                . 'kullanıcı mesajda o kişiyi/firmayı adıyla yazmadıysa ve satıcı aşağıdaki mevcut cari listesinde yoksa `party_id`/`party_name` null.'
            : $receiptRule . "\n" . '- Fotoğraf (çek) → belgeden yönü çıkar: bize gelen para → "collection".';
        // Harcama takibi kapalı: kişisiz harcama / "ne harcadım" sorusu zorla başka kalıba girmesin.
        $noExpenseRule = in_array(self::KIND_EXPENSE, self::allowedKinds(), true) ? '' : '- HARCAMA (GİDER) TAKİBİ KAPALI: kişisiz bir harcama ("5 bin mazot aldım", "kira ödedim" — kime belli değil) ya da harcama sorusu ("bu ay ne harcadım") → "help", `reply`: "Bu hesapta harcama takibi kapalı, sadece kişi/firma (cari) hesapları tutuluyor.'
            . (in_array(self::KIND_PURCHASE, self::allowedKinds(), true) ? ' Bir kişiden aldıysan adıyla yaz, örneğin: \"Ahmet\'ten 10 bin mal aldım\".' : '')
            . ' 💡 Harcamalarını da tutmak istersen Hesap Asistanım yetkilisinden bu özelliğin açılmasını isteyebilirsin."';
        // Tedarikçi tarafı kapalı (mimar): "Hasan'a 10 bin ödedim" en yakın kalıp "sale" sanılıp yön ters çıkıyordu.
        $noSupplierRule = in_array(self::KIND_PAYMENT, self::allowedKinds(), true) ? '' : '- TEDARİKÇİ TARAFI KAPALI: bu hesapta yalnız MÜŞTERİ tarafı (yaptığımız işler, bize gelen paralar, alacaklar) tutulur. BİZ birine para verdiysek ("Hasan\'a 10 bin ödedim", "ustaya verdim") ya da birinden mal/hizmet aldıysak → "help", `reply`: "Bu hesapta yalnız müşteri hesapları tutuluyor; birine yaptığın ödemeler ve aldığın mal/hizmet kaydedilmiyor. 💡 Ödemelerini ve alımlarını da tutmak istersen Hesap Asistanım yetkilisinden bu özelliğin açılmasını isteyebilirsin." ASLA "sale" ya da "collection" SEÇME — yön ters olur.';
        // İnşaat dışı firmada (köylü/esnaf: peynir, bal satan) "inşaat şirketi" çerçevesi satışı gider sandırıyordu (Erkan, 12 Ekim).
        $intro = in_array(config('app.profile'), ['muteahhit', 'mimar'], true)
            ? 'Sen bir inşaat şirketinin kayıt asistanısın (kullanıcı müteahhit ya da mimar).'
            : 'Sen bir esnafın / küçük işletmenin (çiftçi, üretici, satıcı) kayıt asistanısın; kullanıcı mal ya da hizmet SATAR, alır, para alır ve verir.';
        $kinds = implode("\n", array_map(
            fn (string $k) => self::KIND_HINTS[$k],
            self::aiKinds(),
        ));

        $refine = '';
        if ($previous !== null) {
            $prevJson = json_encode($previous, JSON_UNESCAPED_UNICODE);
            // "Ne için verdin? → malzeme aldım": cariye verilen para gider değil, peşin alış (Burak 11 Ekim, Taner 13 Ekim).
            $doneWork = ! in_array(self::KIND_PURCHASE, self::aiKinds(), true)
                ? 'kind "expense",
                `paid`=true, `description` işin kısa adı, uygun kategori'
                : 'kind "purchase",
                `paid`=true, `description` mal/işin kısa adı (cariye verilen para — "expense" SEÇME)';
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
              · yapılmış iş / alınmış mal ("iş yaptırdım", "sıva işçiliği", "malzeme aldım") → {$doneWork};
              · ileride yapılacak iş, peşinat ("iş yaptıracağım", "avans", "kapora") → kind "payment", `payment_purpose`="advance";
              · ödünç ("borç verdim", "geri alacağım", "ödünç") → kind "payment", `payment_purpose`="loan".

            ÖNCEKİ TASLAK bir "sale" ise ve kullanıcı paranın alındığını söylüyorsa ("parasını aldım", "parası alındı",
            "peşin", "nakit aldım", "ödedi") bu bir DÜZELTMEDİR: aynı satış, `paid`=true, `is_new_entry`=false; tutarı,
            cariyi, açıklamayı KORU. AMA mal/iş henüz VERİLMEDİYSE ("peyniri sonra göndereceğim", "teslim edilecek",
            "sonra götüreceğim") bu satış değil müşteri avansıdır: kind "collection", `payment_purpose`="advance",
            `is_new_entry`=false; tutarı, cariyi KORU, açıklama ne verilecekse.

            BİRİM FİYAT: Önceki taslakta bir MİKTAR varsa (açıklamada "34 kg bal", "10 adet kapı", "200 litre mazot")
            ve kullanıcı birim fiyat yazarsa ("Kg 600", "kilosu 600", "tanesi 50", "litresi 30", "adedi 1500") bu
            bir DÜZELTMEDİR: `amount` = miktar × birim fiyat (34 × 600 = 20400), `description`'a hesabı ekle
            ("34 kg bal (kg 600)"), `question`=null. Tutarı tekrar SORMA.

            ÖNCEKİ TASLAK: {$prevJson}

            REFINE;
        }

        $lastPartyBlock = $lastParty !== null ? $this->lastPartyContext($lastParty) : '';
        // Faz 3.3: fotoğraf/belge konuşmanın devamı olabilir ("Osman'a çekleri verdim" → çek fotoğrafı) — okuyucu konuşmayı görsün.
        $lastPartyBlock .= $phone !== null ? self::recentConversation($phone) : '';
        $understand = Understanding::RULES;

        return <<<PROMPT
        {$intro} Kullanıcı sana
        yazarak veya belge (fiş, fatura, dekont) fotoğrafı atarak bir işlem bildirir. Görevin
        `save_entry` aracını çağırarak işlemi çıkarmak. Kullanıcı günlük konuşma diliyle, yazım hatalı
        yazabilir ("Parası ni", "15binlira", "25,kilo") — anlamı oku, yazıma takılma.

        Bugünün tarihi: {$today}. Tarih belirtilmemişse `date` alanına bugünü koy.
        {$refine}{$lastPartyBlock}
        {$understand}

        İŞLEM TÜRÜ (`kind`) — önce bunu belirle. YÖN çok önemli, fiilin öznesine dikkat et:
        {$kinds}
        {$photoRule}
        {$ambiguousRule}
        {$noExpenseRule}
        {$noSupplierRule}
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
        Miktar ve birim fiyat söylendiyse ("34 kg bal aldım kilosu 600", "10 adet kapı tanesi 1500") `amount` = miktar × birim
        fiyat; açıklamaya hesabı yaz ("34 kg bal (kg 600)"). Tutarı sorma. BİRİM FİYAT yalnız "kilosu / tanesi / adedi /
        litresi / kg 600" gibi açıkça BİRİM başına söylenen fiyattır. "tutarı", "toplam", "hepsi", "etti", ya da miktardan
        sonra yalnız bir sayı ("peynir 25 kilo 15 bin", "25 kilo tutarı 15 bin") → o sayı TOPLAM tutardır, ÇARPMA
        (`amount`=15000, açıklama "25 kg peynir").
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

    /**
     * Sınırlı bağlam: son konuşulan cari ve/veya az önce gönderilen hatırlatma — isimsiz atıfları
     * ("ondan", "ona", "daha", "aldım", "yarın tekrar hatırlat") çözer.
     */
    private function lastPartyContext(array $c): string
    {
        $block = '';

        if (! empty($c['reminder'])) {
            $text = json_encode($c['reminder'], JSON_UNESCAPED_UNICODE);
            $block .= <<<CTX

            AZ ÖNCE GÖNDERİLEN HATIRLATMA: {$text}
            - Kısa cevap bu hatırlatmaya aittir. Erteleme ("yarın tekrar hatırlat", "1 saat sonra", "akşam hatırlat")
              → "reminder", `description` aynı metin, `is_alarm`=true, yeni zaman.

            CTX;
        }

        if (! empty($c['party_id'])) {
            $all = (string) round(abs((float) $c['balance']), 2);
            $reply = ! empty($c['reminder'])
                ? "\n  · Hatırlatmaya cevap olarak tek başına \"aldım\", \"geldi\", \"ödedi\" → \"collection\"; \"ödedim\", \"verdim\" → \"payment\"; tutar yoksa `amount`={$all}."
                : '';
            $block .= <<<CTX

            SON KONUŞULAN CARİ (son 30 dk): {$c['party_name']} (party_id {$c['party_id']}). Güncel durum: {$c['balance_note']}
            - Mesajda HİÇBİR kişi/cari adı yoksa ve mesaj bir kişiye işaret ediyorsa ("ondan", "ona", "onun", "kendisi",
              "… daha verdi / ödedi / verdim", "hepsini ödedim", "kalanını aldım", "borcunu kapattı") → `party_id`={$c['party_id']}.
              · Bu durumda "ondan X aldım" (mal/hizmet adı GEÇMİYORSA) PARA ALMAKTIR → "collection"; "ona X verdim/ödedim" → "payment".
              · "hepsini / tamamını / kalanını" ve tutar yoksa `amount`={$all} (güncel bakiye).{$reply}
            - Mesajda BAŞKA bir kişi/cari adı geçiyorsa bu bağlamı TAMAMEN YOK SAY.
            - Mesaj bir kişiye işaret etmiyorsa ("5 bin yakıt aldım", "kirayı ödedim", "bu ay ne harcadım") bağlamı KULLANMA.
            - Bu bağlamdan proje / kategori / tutar TAŞINMAZ; yalnız cari.

            CTX;
        }

        return $block;
    }

    private function tool(): array
    {
        return [
            'name' => 'save_entry',
            'description' => 'Çıkarılan işlem bilgisini yapılandırılmış olarak döndürür.',
            'input_schema' => [
                'type' => 'object',
                'properties' => self::tradeProperties() + Understanding::schema() + [
                    'kind' => ['type' => 'string', 'enum' => self::aiKinds(), 'description' => 'Entry kind; see system prompt'],
                    'items' => self::itemsSchema(),
                    'totals_side' => ['type' => ['string', 'null'], 'enum' => ['receivable', 'payable', 'both', null], 'description' => 'Only for kind=totals_query: which side was asked'],
                    'date_from' => ['type' => ['string', 'null'], 'description' => 'Only for kind=statement / expense_summary / month_result / agenda: period start (YYYY-MM-DD) if a day or period was named, else null'],
                    'date_to' => ['type' => ['string', 'null'], 'description' => 'Only for kind=statement / expense_summary / month_result / agenda: period end (YYYY-MM-DD) if a day or period was named, else null'],
                    'payment_type' => ['type' => ['string', 'null'], 'enum' => ['cash', 'bank_transfer', 'eft', 'other', null], 'description' => 'payment/collection method if stated, else null'],
                    'amount' => ['type' => 'number', 'description' => 'Amount (TRY, numeric); total for sale items; 0 for balance_query'],
                    'date' => ['type' => ['string', 'null'], 'description' => 'Date the expense occurred (YYYY-MM-DD); today if absent. NOT the payment due date.'],
                    'due_date' => ['type' => ['string', 'null'], 'description' => 'Payment due date (YYYY-MM-DD) when payment is planned for the future / promised; null if paid or no due date given'],
                    'description' => ['type' => 'string', 'description' => 'What the expense is for (short)'],
                    'project_id' => ['type' => ['integer', 'null'], 'description' => 'Matched existing project id or null'],
                    'project_name' => ['type' => ['string', 'null'], 'description' => 'Proposed NEW project name if the user named one that is not in the list; null if matched or none named'],
                    'party_id' => ['type' => ['integer', 'null'], 'description' => 'Matched existing party id or null'],
                    'party_name' => ['type' => ['string', 'null'], 'description' => 'Proposed NEW party name if the user named one that is not in the list; null if matched or none named'],
                    'direction_unclear' => ['type' => ['boolean', 'null'], 'description' => 'Only for kind=sale/purchase: true if it is unclear whether the goods went TO the party (sale) or came FROM the party (purchase); the user is asked'],
                    'debt_repayment' => ['type' => ['boolean', 'null'], 'description' => 'Only for kind=payment: true if this payment repays OUR existing debt ("borcum ödendi", "borç iade")'],
                    'bank_fee' => ['type' => ['number', 'null'], 'description' => 'Only from a bank receipt (dekont): transfer fee + BSMV total, NOT included in amount; else null'],
                    'seller_name' => ['type' => ['string', 'null'], 'description' => 'Only from a receipt/invoice image: the SELLER company name printed on it (market, fuel station…); else null'],
                    'party_said' => ['type' => ['string', 'null'], 'description' => "How the user referred to the person/company in THIS message, nominative form, all descriptive words kept (\"Hüseyin BİM'in bacısı Ayten\", \"Ali usta\", \"Demirci Recep\"); null if not named (\"ona\", \"aynı kişi\")"],
                    'category_id' => ['type' => ['integer', 'null'], 'description' => 'Matched existing category id or null'],
                    'category_name' => ['type' => ['string', 'null'], 'description' => 'Proposed NEW short category name if none fits; null if an existing category matched'],
                    'paid' => ['type' => ['boolean', 'null'], 'description' => 'expense/purchase: true only if explicitly paid / payment proof document, false if explicitly unpaid or future. sale: true if the money was already received (peşin, "parasını aldım"). null if not stated'],
                    'confidence' => ['type' => 'string', 'enum' => ['high', 'low']],
                    'question' => ['type' => ['string', 'null'], 'description' => 'Question to ask the user for a missing project/party match, else null'],
                    'settles_expense_id' => ['type' => ['integer', 'null'], 'description' => 'Only for kind=expense: id from ÖDENMEMİŞ GİDERLER when the message pays that existing unpaid expense (not a new cost); else null'],
                    'forward_to_party' => ['type' => ['boolean', 'null'], 'description' => 'Only for kind=statement: true if the user wants it SENT TO the party (third person)'],
                    'event_date' => ['type' => ['string', 'null'], 'description' => 'Only for kind=reminder: event day YYYY-MM-DD'],
                    'event_time' => ['type' => ['string', 'null'], 'description' => 'Only for kind=reminder: HH:MM only if a time was said'],
                    'lead_minutes' => ['type' => ['integer', 'null'], 'description' => 'Only for kind=reminder: how long BEFORE, only if said (minutes); else null'],
                    'is_alarm' => ['type' => ['boolean', 'null'], 'description' => 'Only for kind=reminder: true = remind exactly at that time (no separate event)'],
                    'repeat' => ['type' => ['string', 'null'], 'enum' => ['weekly', 'monthly', 'yearly', null], 'description' => 'Only for kind=reminder'],
                    'memory_kind' => ['type' => ['string', 'null'], 'enum' => ['alias', 'agreement', 'price', 'preference', 'note', null], 'description' => 'Only for kind=remember'],
                    'memory_subject' => ['type' => ['string', 'null'], 'description' => 'Only for kind=remember / forget: short subject ("kalfa")'],
                    'memory_fact' => ['type' => ['string', 'null'], 'description' => 'Only for kind=remember: one-sentence fact ("Kalfa = Mehmet Kaya")'],
                    'is_staff' => ['type' => ['boolean', 'null'], 'description' => 'kind=payment / salary: true if the person is OUR worker / employee — decide from MEANING, any wording (personel, eleman, işçi, çoban, şoför, garson, kalfa, çırak, "bizim çocuk", "maaşından", "haftalığı") or "personel" note in CARİLER; false if a supplier/craftsman/customer; null if unknown'],
                    'salary_amount' => ['type' => ['number', 'null'], 'description' => 'Only for kind=employee_setting: monthly salary'],
                    'salary_day' => ['type' => ['integer', 'null'], 'description' => 'Only for kind=employee_setting: day of month the salary is paid (1-31)'],
                    'month' => ['type' => ['string', 'null'], 'description' => 'Only for month_result / salary: YYYY-MM, null = this month'],
                    'payment_purpose' => ['type' => ['string', 'null'], 'enum' => ['advance', 'loan', null], 'description' => 'kind=payment: advance (prepayment for future work) / loan (money lent, to be returned). kind=collection: advance only (customer paid before goods/work were delivered). null if not stated'],
                    'debt_side' => ['type' => ['string', 'null'], 'enum' => ['receivable', 'payable', 'unclear', null], 'description' => 'Only for kind=debt_note: receivable (they owe us) / payable (we owe them) / unclear (direction not stated — user is asked)'],
                    'reply' => ['type' => ['string', 'null'], 'description' => 'Only for kind=help: short Turkish guidance reply (max 3 sentences)'],
                    'is_new_entry' => ['type' => 'boolean', 'description' => 'Only meaningful when a previous draft is provided: true if the new message is a brand-new separate entry or a balance question (extract from scratch, ignore previous), false if it refines the previous draft. Default false.'],
                ],
                // work_status_known zorunlu: kişiyle para/borç kaydında AI "biliyor muyum" demek ZORUNDA (önce anla).
                'required' => ['kind', 'amount', 'description', 'paid', 'confidence', 'work_status_known'],
            ],
        ];
    }

    /** Stoklu satış paketi açık mı (ek şema alanları ve ürün listesi yalnız o zaman). */
    public static function tradeEnabled(): bool
    {
        return (bool) (config('modules.sales_desk') || config('modules.purchases'));
    }

    /** Kalem şeması — eski firmalarda AYNEN eskisi (iş kalemi: açıklama + tutar). */
    private static function itemsSchema(): array
    {
        if (! self::tradeEnabled()) {
            return [
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
            ];
        }

        return [
            'type' => 'array',
            'description' => 'kind=trade_sale / trade_purchase / product_add: one item per product (product_id from ÜRÜNLER, quantity, unit_price / amount if said)',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'description' => ['type' => 'string'],
                    'amount' => ['type' => ['number', 'null']],
                    'product_id' => ['type' => ['integer', 'null'], 'description' => 'matched product id from ÜRÜNLER, else null'],
                    'quantity' => ['type' => ['number', 'null'], 'description' => 'only if the user said it; null if not stated — NEVER assume 1 (system asks)'],
                    'unit_price' => ['type' => ['number', 'null'], 'description' => 'price PER ONE unit if said ("litresi 30", "kilosu 40", "tanesi 40 liradan", "10 verdim 40 liradan" → 40). Without a unit word the unit is piece (adet). Earlier turns count too: keep the price said before in this draft'],
                    'unit' => ['type' => ['string', 'null'], 'description' => 'unit name (çuval, kg, paket, adet) when product_id is null'],
                ],
                'required' => ['description'],
            ],
        ];
    }

    /** Stoklu satış alanları (yalnız paket açıkken şemaya eklenir). */
    private static function tradeProperties(): array
    {
        if (! self::tradeEnabled()) {
            return [];
        }

        return [
            'product_id' => ['type' => ['integer', 'null'], 'description' => 'Only for stock_count / stock_query / stock_setting: product id from ÜRÜNLER'],
            'counted_quantity' => ['type' => ['number', 'null'], 'description' => 'Only for stock_count: quantity on hand'],
            'daily_usage' => ['type' => ['number', 'null'], 'description' => 'Only for stock_setting: daily usage'],
            'reorder_level' => ['type' => ['number', 'null'], 'description' => 'Only for stock_setting: warn when stock falls to this'],
            'purchase_price' => ['type' => ['number', 'null'], 'description' => 'Only for stock_setting: unit purchase price'],
            'product_name' => ['type' => ['string', 'null'], 'description' => 'stock_count / stock_setting: when the product is NOT in ÜRÜNLER; product_rename: the NEW name'],
            'unit' => ['type' => ['string', 'null'], 'description' => 'Only with product_name: unit (çuval, kg, paket, adet)'],
            'cash_amount' => ['type' => ['number', 'null'], 'description' => 'Only for trade_sale: cash part if said'],
            'card_amount' => ['type' => ['number', 'null'], 'description' => 'Only for trade_sale: card part if said'],
            'month' => ['type' => ['string', 'null'], 'description' => 'Only for month_result: YYYY-MM, null = this month'],
        ];
    }

    /**
     * Emniyet (AI'dan bağımsız): "parasını aldım, peyniri sonra VERECEĞİM" peşin satış değil müşteri avansıdır.
     * AI yine satış derse mesajdaki gelecek zamanlı teslim sözü yakalanır ve tahsilata (avans) çevrilir.
     * Mesajda teslim edildiği de söylendiyse ("gönderdim", "verdim") dokunulmaz. Hıdır / Zülfikar / Ayten, Erkan 12-13 Ekim.
     */
    public static function guardUndeliveredSale(array $d, string $text): array
    {
        if (($d['kind'] ?? null) !== self::KIND_SALE || empty($d['paid']) || trim($text) === '') {
            return $d;
        }
        $t = mb_strtolower(strtr($text, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
        $later = '/(verece[gğk]|verice[mk]|verece[mk]|g[oö]nderece[gğk]|g[oö]nderice[mk]|g[oö]nderece[mk]|g[oö]t[uü]rece[gğk]|g[oö]t[uü]r[uü]?ce[mk]'
            . '|teslim edece[gğk]|teslim edilecek|verilecek|g[oö]nderilecek|g[oö]t[uü]r[uü]lecek)/u';
        $done = '/(verdim|g[oö]nderdim|g[oö]t[uü]rd[uü]m|teslim ettim|teslim edildi|verildi|g[oö]nderildi)/u';
        if (! preg_match($later, $t) || preg_match($done, $t)) {
            return $d;
        }

        return array_merge($d, ['kind' => self::KIND_COLLECTION, 'payment_purpose' => 'advance', 'paid' => null, 'items' => []]);
    }

    /**
     * Emniyet (AI'dan bağımsız): "Hazal'a borcum ödendi / borcumu ödedim / kapattım / iade ettim" BORÇ KAYDI değil ödemedir
     * (Taner, 13 Ekim: "borcum ödendi" → "sen ona 100.000 borçlusun" yazıldı). Yalnız "borç" + geçmiş zaman ödeme fiili
     * birlikteyse; "borcum var", "ödeyeceğim", "borcunu ödedi" (o bize ödedi) dokunulmaz. Yönü belirsiz borç notu
     * da yalnız bu ikiliyle çevrilir.
     */
    public static function guardDebtRepayment(array $d, string $text): array
    {
        $kind = $d['kind'] ?? null;
        if (! in_array($kind, [self::KIND_DEBT_NOTE, self::KIND_PAYMENT], true) || trim($text) === ''
            || ! in_array(self::KIND_PAYMENT, self::allowedKinds(), true)
            || ($kind === self::KIND_DEBT_NOTE && ($d['debt_side'] ?? null) === 'receivable')) {
            return $d;
        }
        $t = mb_strtolower(strtr($text, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
        $ourDebt = '/bor[cç]um(u|uz|uzu)?\b|bor[cç]u(mu)? (kapatt[ıi]m|[oö]dedim|iade ettim)/u';
        $paid = '/([oö]dendi|[oö]dedim|kapatt[ıi]m|kapand[ıi]|iade ettim|iade edildi)/u';
        if (! preg_match($ourDebt, $t) || ! preg_match($paid, $t) || preg_match('/[oö]deyece[gğk]|[oö]derim/u', $t)) {
            return $d;
        }

        return array_merge($d, ['kind' => self::KIND_PAYMENT, 'debt_repayment' => true, 'debt_side' => null,
            'payment_purpose' => null, 'advance' => false]);
    }

    /**
     * K4 (Akkuş 14.10: "Ergenekon 5000 tl kart 19483 tl 1630 tl gider var" tek gider 25.113 sanıldı): kasa (nakit + kart/pos)
     * ve "X gider" aynı mesajda → peşin satış (ciro) + ayrı gider (`extra_expense`), tek özet, tek onay.
     */
    /**
     * Miktar mesajda hiç geçmiyorsa ("Un satışım oldu") AI'ın yazdığı miktar tahmindir → boşalt, sistem sorsun
     * (Serkan 16.10: 1 adet sanıldı). Rakam ya da sayı sözü varsa dokunulmaz.
     */
    public static function guardAssumedQuantity(array $d, string $text): array
    {
        if (! in_array($d['kind'] ?? null, [self::KIND_TRADE_SALE, self::KIND_TRADE_PURCHASE], true) || empty($d['items']) || trim($text) === '') {
            return $d;
        }
        $t = mb_strtolower(strtr($text, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
        if (preg_match('/\d|\b(bir|tek|iki|üç|dört|beş|altı|yedi|sekiz|dokuz|on|yirmi|otuz|kırk|elli|yüz|yarım|buçuk|düzine)\b/u', $t)) {
            return $d;
        }
        foreach ($d['items'] as $i => $item) {
            if (! empty($item['product_id'])) {
                $d['items'][$i]['quantity'] = null;
            }
        }

        return $d;
    }

    /** Kasa (gün sonu ciro) mesajı mı: hem nakit hem kart/pos rakamı var ("Nakit 5900 / Pos 21540", "5000 nakit 19483 kart"). */
    public static function looksLikeTill(string $text): bool
    {
        $t = mb_strtolower(strtr($text, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
        $n = '\d[\d.,]*\s*(?:tl|₺|lira)?';

        return (bool) preg_match('/nakit\s*:?\s*' . $n . '|' . $n . '\s*nakit/u', $t)
            && (bool) preg_match('/(?:kart|pos)\s*:?\s*' . $n . '|' . $n . '\s*(?:kart|pos)/u', $t);
    }

    public static function guardSaleWithExpense(array $d, string $text): array
    {
        if (! in_array(self::KIND_TRADE_SALE, self::allowedKinds(), true) || ! config('modules.expenses') || trim($text) === '') {
            return $d;
        }
        $t = mb_strtolower(strtr($text, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
        $num = '(\d[\d.,]*)';
        if (! preg_match('/' . $num . '\s*(?:tl|₺|lira)?\s*(?:gider|masraf)/u', $t, $g)) {
            return $d;
        }
        // Kasa rakamları: "Nakit 9150 / Pos 13390" (etiket önce) ya da "5700 tl nakit 19261 tl kart" (rakam önce).
        // Hangisi olduğu ilk rakamın ilk etiketten önce gelip gelmediğinden anlaşılır; toplam her durumda doğru.
        if (! preg_match('/nakit|kart|pos/u', $t, $lm, PREG_OFFSET_CAPTURE)) {
            return $d; // kasa kalıbı yok — tek başına gider
        }
        preg_match('/\d/u', $t, $nm, PREG_OFFSET_CAPTURE);
        $numberFirst = isset($nm[0]) && $nm[0][1] < $lm[0][1];
        $pick = function (string $label) use ($t, $num, $numberFirst): float {
            $re = $numberFirst
                ? '/' . $num . '\s*(?:tl|₺|lira)?\s*(?:' . $label . ')/u'
                : '/(?:' . $label . ')\s*:?\s*' . $num . '/u';

            return preg_match($re, $t, $m) ? self::trNumber($m[1]) : 0.0;
        };
        $cash = $pick('nakit');
        $card = $pick('kart|pos');
        $expense = self::trNumber($g[1]);
        // Gider dışındaki rakamların toplamı = ciro; etiketi eksik kısım nakit/kart farkı olarak tamamlanır.
        preg_match_all('/' . $num . '/u', (string) preg_replace('/' . $num . '\s*(?:tl|₺|lira)?\s*(?:gider|masraf)/u', ' ', $t), $all);
        $total = array_sum(array_map(fn ($n) => self::trNumber($n), $all[1]));
        if ($total <= 0) {
            return $d;
        }
        if ($cash + $card < $total - 0.01) {
            if ($card > 0) {
                $cash = $total - $card;
            } else {
                $card = $total - $cash;
            }
        }
        // Açıklama: ilk rakamdan / nakit-kart etiketinden önceki kısım ("Ergenekon kasa", "Akkuş ergenekon").
        $base = trim((string) preg_replace('/(\d|nakit|kart|pos).*$/ius', '', $text), " \t\n/-–:");
        if (($d['kind'] ?? null) !== self::KIND_TRADE_SALE) {
            $d = array_merge($d, ['kind' => self::KIND_TRADE_SALE, 'items' => [], 'paid' => true, 'party_id' => null, 'party_name' => null,
                'cash_amount' => $cash, 'card_amount' => $card, 'amount' => $cash + $card, 'category_id' => null, 'category_name' => null,
                'question' => null, 'description' => trim($base . ' günlük ciro')]);
        }
        $d['extra_expense'] = ['amount' => $expense, 'description' => trim($base . ' gideri')];
        if (! empty($d['next_items']) && preg_match('/gider|masraf/u', mb_strtolower((string) $d['next_items']))) {
            $d['next_items'] = null; // aynı gider sıraya ikinci kez girmesin
        }

        return $d;
    }

    /** "5.000" / "19483" / "1.630,50" → float (Türkçe binlik nokta, ondalık virgül). */
    private static function trNumber(string $s): float
    {
        $s = trim($s, " .,\t\n");
        if (str_contains($s, ',')) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
            $s = str_replace('.', '', $s);
        }

        return (float) $s;
    }

    /**
     * Emniyet (AI'dan bağımsız): kendi adımız cari olamaz (Ferhat, 15 Ekim: dekontta alıcı "FERHAT YILDIZ" kendisiydi →
     * "Sen → Ferhat 75.000 ödedin" ve "Ferhat'a borçlusun" önerildi). Cari BİZ adını tam içeriyorsa cari boşaltılır, yön
     * düzeltilir (ödeme/borç notu → tahsilat, tahsilat → ödeme) ve karşı taraf sorulur. "bana geldi / hesabıma geldi"
     * cevabı da ödeme/borç notunu tahsilata çevirir.
     *
     * @param  list<string>  $selfNames
     */
    public static function guardSelfParty(array $d, array $selfNames, string $text): array
    {
        $kind = $d['kind'] ?? null;
        if (! in_array($kind, [self::KIND_PAYMENT, self::KIND_COLLECTION, self::KIND_DEBT_NOTE], true)) {
            return $d;
        }
        $t = mb_strtolower(strtr($text, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
        $toUs = (bool) preg_match('/(bana|hesab[ıi]ma|bize|hesab[ıi]m[ıi]za) (geldi|g[öo]nderdi|yat[ıi]rd[ıi]|att[ıi]|havale etti|eft yapt[ıi])/u', $t);

        $candidates = array_filter([
            ! empty($d['party_id']) ? \App\Models\Party::whereKey($d['party_id'])->value('name') : null,
            $d['party_name'] ?? null,
            $d['party_said'] ?? null,
        ]);
        $isSelf = false;
        foreach ($selfNames as $self) {
            foreach ($candidates as $c) {
                if (PartyNameCheck::containedIn(new \App\Models\Party(['name' => $self]), (string) $c)) {
                    $isSelf = true;
                }
            }
        }

        if ($isSelf) {
            $incoming = $kind !== self::KIND_COLLECTION || $toUs;
            return array_merge($d, [
                'kind' => $incoming ? self::KIND_COLLECTION : self::KIND_PAYMENT,
                'party_id' => null, 'party_name' => null, 'party_said' => null,
                'debt_side' => null, 'payment_purpose' => null, 'advance' => false, 'debt_repayment' => false,
                'bank_fee' => $incoming ? null : ($d['bank_fee'] ?? null),
                'question' => $incoming ? 'Bu para kimden geldi? Gönderenin adını yazar mısın?' : 'Kime gönderdin? Adını yazar mısın?',
            ]);
        }
        if ($toUs && in_array($kind, [self::KIND_PAYMENT, self::KIND_DEBT_NOTE], true)) {
            return array_merge($d, ['kind' => self::KIND_COLLECTION, 'debt_side' => null, 'payment_purpose' => null,
                'advance' => false, 'debt_repayment' => false, 'bank_fee' => null]);
        }

        return $d;
    }

    /**
     * Emniyet (AI'dan bağımsız): takas / iki yönlü mesaj ("yemleme aleti ALDIM 12 bin, 5 kilo peynir VERDİM")
     * AI ikinci kalemi `next_items`'a yazmayı unutursa kod yakalar: alışta "… verdim/sattım", satışta "… aldım"
     * parçası sıraya eklenir — onaydan sonra sorulur. Para sözleri ("parasını verdim/aldım", "ödedim") takas
     * değildir. Demirci Recep, Erkan 12 Ekim.
     */
    public static function guardSwap(array $d, string $text): array
    {
        $kind = $d['kind'] ?? null;
        if (! in_array($kind, [self::KIND_PURCHASE, self::KIND_SALE], true) || ! empty($d['next_items']) || trim($text) === '') {
            return $d;
        }
        $t = mb_strtolower(strtr($text, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
        $verb = $kind === self::KIND_PURCHASE ? '(?:verdim|sattım|sattim)' : '(?:aldım|aldim)';
        if (! preg_match_all('/((?:[^\s,.;]+\s+){0,5}' . $verb . ')(?=[\s,.;!]|$)/u', $t, $m)) {
            return $d;
        }
        foreach ($m[1] as $segment) {
            // "parasını verdim / para aldım / nakit verdim" = ödeme/tahsilat, takas değil.
            if (preg_match('/(para|parası|parasını|parayı|nakit|peşin|ödeme|tutar)\s+' . $verb . '$/u', $segment)) {
                continue;
            }
            $d['next_items'] = trim($segment) . ' (aynı mesajdan: "' . trim($text) . '" — ilk kalem kaydedildi, onu tekrar önerme)';

            return $d;
        }

        return $d;
    }

    /** Sıradaki kalemin kullanıcıya görünen kısmı (kodun eklediği bağlam notu olmadan). */
    public static function nextItemsLabel(string $next): string
    {
        return trim((string) preg_replace('/\s*\(aynı mesajdan: .*$/su', '', $next));
    }

    private static function validDate(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private function normalize(array $input): array
    {
        return self::fitToModules(self::dropUnknownIds($this->normalizeRaw($input)));
    }

    /** Sohbet asistanı (ChatAssistant) aynı kayıt şemasını ve temizliği kullanır. */
    public function normalizeEntry(array $input): array
    {
        return $this->normalize($input);
    }

    /** @return array<string,mixed> kayıt alanlarının JSON şeması (save_entry / propose_entry) */
    public function entrySchema(): array
    {
        return $this->tool()['input_schema'];
    }

    /** İzinli işlem türlerinin tanımları (prompt'a). */
    public static function kindGuide(): string
    {
        $guide = implode("\n", array_map(fn (string $k) => self::KIND_HINTS[$k], self::aiKinds()));
        if (config('modules.cari_supplier')) {
            // Vedat (Erkan, 14 Ekim): "Vedat Çağlar 275+170 sebze ekmek getirdim" sorulmadan alış sayıldı, satıştı.
            $guide .= "\n" . '- YÖN BELİRSİZSE (sale mi purchase mı): malın ona mı gittiği, ondan mı geldiği cümleden anlaşılmıyorsa '
                . '(kişi adında "-e/-a" ya da "-den/-dan" eki yok ve fiil iki yöne de okunuyor: "getirdim", "götürdüm", "geldi"; '
                . 'ör. "Vedat Çağlar 275+170 sebze ekmek getirdim") en olası türü seç ama `direction_unclear`=true — sistem '
                . '"ona mı götürdün, ondan mı aldın?" diye sorar. Ek ya da fiil yönü söylüyorsa ("Vedat\'a getirdim", '
                . '"Vedat\'tan aldım", "X\'e verdim", "X\'ten aldım") ya da malı KİŞİ getirdiyse ("Hasan 2000 lira yem getirdi" → purchase) false.';
        }

        return $guide;
    }

    /** @return list<string> sınıftaki tüm KIND_ sabitleri */
    public static function knownKinds(): array
    {
        static $kinds = null;

        return $kinds ??= array_values(array_filter((new \ReflectionClass(self::class))->getConstants(),
            fn ($v, $k) => str_starts_with($k, 'KIND_') && is_string($v), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * AI'ın verdiği proje / cari / kategori numarası gerçekten var mı (review H): yoksa boşaltılır — kayıtta
     * yabancı anahtar hatası ve cevapsız kalan mesaj olmasın; boşalan alan normal akışta sorulur.
     */
    public static function dropUnknownIds(array $d): array
    {
        foreach ([['project_id', \App\Models\Project::class], ['party_id', \App\Models\Party::class],
            ['category_id', \App\Models\ExpenseCategory::class]] as [$field, $model]) {
            if (! empty($d[$field]) && ! $model::whereKey($d[$field])->exists()) {
                Log::warning('AI olmayan kayıt numarası verdi', [$field => $d[$field]]);
                $d[$field] = null;
            }
        }

        return $d;
    }

    private function normalizeRaw(array $input): array
    {
        // Hiç tanınmayan tür (AI uydurdu / boş) gidere DÜŞMEZ → yönlendirme (review F). Bilinen ama bu firmada kapalı
        // tür eskisi gibi gidere iner, fitToModules uygun yola çevirir.
        $raw = $input['kind'] ?? null;
        if (! in_array($raw, self::knownKinds(), true)) {
            Log::warning('AI tanınmayan tür döndürdü', ['kind' => $raw]);
            $input = ['kind' => self::KIND_HELP, 'reply' => null, 'amount' => 0, 'description' => '', 'paid' => null, 'confidence' => 'low'];
            $raw = self::KIND_HELP;
        }
        $kind = in_array($raw, self::allowedKinds(), true) ? $raw : self::KIND_EXPENSE;

        $items = [];
        $tradeItems = in_array($kind, [self::KIND_TRADE_SALE, self::KIND_TRADE_PURCHASE, self::KIND_PRODUCT_ADD, self::KIND_PRODUCTION], true);
        foreach ((array) ($input['items'] ?? []) as $item) {
            $amount = (float) ($item['amount'] ?? 0);
            if ($tradeItems) {
                // Ürünlü kalem: tutar boş olabilir (karttaki fiyat) — TradeDraft::route hesaplar.
                $items[] = [
                    'description' => trim((string) ($item['description'] ?? '')),
                    'amount' => $amount > 0 ? $amount : null,
                    'product_id' => ! empty($item['product_id']) ? (int) $item['product_id'] : null,
                    'quantity' => isset($item['quantity']) ? (float) $item['quantity'] : null,
                    'unit_price' => isset($item['unit_price']) ? (float) $item['unit_price'] : null,
                    'unit' => is_string($item['unit'] ?? null) && trim($item['unit']) !== '' ? mb_strtolower(trim($item['unit'])) : null,
                ];
            } elseif ($amount > 0) {
                $items[] = ['description' => trim((string) ($item['description'] ?? '')), 'amount' => $amount];
            }
        }
        $stockKind = in_array($kind, [self::KIND_STOCK_COUNT, self::KIND_STOCK_QUERY, self::KIND_STOCK_SETTING, self::KIND_PRODUCT_RENAME], true);

        $paymentType = $input['payment_type'] ?? null;

        return Understanding::normalize($input) + [
            'kind' => $kind,
            'items' => $kind === self::KIND_SALE || $tradeItems ? $items : [],
            'product_id' => $stockKind && ! empty($input['product_id']) ? (int) $input['product_id'] : null,
            'counted_quantity' => $kind === self::KIND_STOCK_COUNT && isset($input['counted_quantity']) ? (float) $input['counted_quantity'] : null,
            'daily_usage' => $kind === self::KIND_STOCK_SETTING && isset($input['daily_usage']) ? (float) $input['daily_usage'] : null,
            'reorder_level' => $kind === self::KIND_STOCK_SETTING && isset($input['reorder_level']) ? (float) $input['reorder_level'] : null,
            'purchase_price' => $kind === self::KIND_STOCK_SETTING && isset($input['purchase_price']) ? (float) $input['purchase_price'] : null,
            // Ad değiştirmede product_id (eski kart) + product_name (YENİ ad) birlikte gelir.
            'product_name' => $stockKind && ($kind === self::KIND_PRODUCT_RENAME || empty($input['product_id'])) && is_string($input['product_name'] ?? null) && trim($input['product_name']) !== '' ? trim($input['product_name']) : null,
            'unit' => $stockKind && is_string($input['unit'] ?? null) && trim($input['unit']) !== '' ? mb_strtolower(trim($input['unit'])) : null,
            'cash_amount' => $kind === self::KIND_TRADE_SALE && isset($input['cash_amount']) ? (float) $input['cash_amount'] : null,
            'card_amount' => $kind === self::KIND_TRADE_SALE && isset($input['card_amount']) ? (float) $input['card_amount'] : null,
            'is_staff' => in_array($kind, [self::KIND_PAYMENT, self::KIND_SALARY], true) && ! empty($input['is_staff']),
            'memory_kind' => $kind === self::KIND_REMEMBER && is_string($input['memory_kind'] ?? null) ? $input['memory_kind'] : null,
            'memory_subject' => in_array($kind, [self::KIND_REMEMBER, self::KIND_FORGET], true) && is_string($input['memory_subject'] ?? null)
                ? mb_substr(trim($input['memory_subject']), 0, 120) : null,
            'memory_fact' => $kind === self::KIND_REMEMBER && is_string($input['memory_fact'] ?? null) ? mb_substr(trim($input['memory_fact']), 0, 500) : null,
            'salary_amount' => $kind === self::KIND_EMPLOYEE_SETTING && (float) ($input['salary_amount'] ?? 0) > 0 ? round((float) $input['salary_amount'], 2) : null,
            'salary_day' => $kind === self::KIND_EMPLOYEE_SETTING && (int) ($input['salary_day'] ?? 0) >= 1 && (int) $input['salary_day'] <= 31 ? (int) $input['salary_day'] : null,
            'month' => in_array($kind, [self::KIND_MONTH_RESULT, self::KIND_SALARY], true) && is_string($input['month'] ?? null) && preg_match('/^\d{4}-\d{2}$/', $input['month']) ? $input['month'] : null,
            'totals_side' => $kind === self::KIND_TOTALS_QUERY
                ? (in_array($input['totals_side'] ?? null, ['receivable', 'payable'], true) ? $input['totals_side'] : 'both')
                : null,
            'date_from' => in_array($kind, [self::KIND_STATEMENT, self::KIND_EXPENSE_SUMMARY, self::KIND_AGENDA, self::KIND_MONTH_RESULT], true) ? self::validDate($input['date_from'] ?? null) : null,
            'date_to' => in_array($kind, [self::KIND_STATEMENT, self::KIND_EXPENSE_SUMMARY, self::KIND_AGENDA, self::KIND_MONTH_RESULT], true) ? self::validDate($input['date_to'] ?? null) : null,
            'payment_type' => in_array($paymentType, ['cash', 'bank_transfer', 'eft', 'other'], true) ? $paymentType : null,
            'amount' => isset($input['amount']) ? (float) $input['amount'] : 0.0,
            'date' => $input['date'] ?? now()->format('Y-m-d'),
            // Ödeme tarihi: gider, satış ve alacak/borç kaydında (diğer türlerde anlamsız).
            'due_date' => in_array($kind, [self::KIND_EXPENSE, self::KIND_SALE, self::KIND_DEBT_NOTE, self::KIND_PURCHASE], true)
                ? self::validDate($input['due_date'] ?? null) : null,
            'description' => (string) ($input['description'] ?? ''),
            // Projesiz kurulum: AI proje uydurup yeni proje açtırmasın.
            'project_id' => config('modules.projects') && isset($input['project_id']) ? (int) $input['project_id'] : null,
            'project_name' => config('modules.projects') && ! empty($input['project_name']) ? trim((string) $input['project_name']) : null,
            'party_id' => isset($input['party_id']) ? (int) $input['party_id'] : null,
            'party_name' => ! empty($input['party_name']) ? trim((string) $input['party_name']) : null,
            'direction_unclear' => in_array($kind, [self::KIND_SALE, self::KIND_PURCHASE], true) && config('modules.cari_supplier')
                && ! empty($input['direction_unclear']),
            'debt_repayment' => $kind === self::KIND_PAYMENT && ! empty($input['debt_repayment']),
            // Banka masrafı yalnız parayı BİZ gönderdiysek bizim gider (bize gelen dekontta masrafı gönderen öder — Fuat→Ferhat).
            'bank_fee' => $kind === self::KIND_PAYMENT && (float) ($input['bank_fee'] ?? 0) > 0
                ? round((float) $input['bank_fee'], 2) : null,
            'seller_name' => is_string($input['seller_name'] ?? null) && trim($input['seller_name']) !== '' ? trim($input['seller_name']) : null,
            'party_said' => is_string($input['party_said'] ?? null) && trim($input['party_said']) !== '' ? trim($input['party_said']) : null,
            'category_id' => isset($input['category_id']) ? (int) $input['category_id'] : null,
            'category_name' => ! empty($input['category_name']) ? trim((string) $input['category_name']) : null,
            // null = belirtilmedi → varsayılan kontrolcüde (carili: ödenmedi, carisiz: ödendi).
            'paid' => isset($input['paid']) ? (bool) $input['paid'] : null,
            'confidence' => in_array($input['confidence'] ?? 'high', ['high', 'low'], true) ? ($input['confidence'] ?? 'high') : 'high',
            'question' => ! empty($input['question']) ? (string) $input['question'] : null,
            'is_new_entry' => (bool) ($input['is_new_entry'] ?? false),
            'forward_to_party' => $kind === self::KIND_STATEMENT && ! empty($input['forward_to_party']),
            'event_date' => $kind === self::KIND_REMINDER ? self::validDate($input['event_date'] ?? null) : null,
            'event_time' => $kind === self::KIND_REMINDER && is_string($input['event_time'] ?? null)
                && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $input['event_time']) ? $input['event_time'] : null,
            'lead_minutes' => $kind === self::KIND_REMINDER && ! empty($input['lead_minutes']) ? max(1, (int) $input['lead_minutes']) : null,
            'is_alarm' => $kind === self::KIND_REMINDER && ! empty($input['is_alarm']),
            'repeat' => $kind === self::KIND_REMINDER && in_array($input['repeat'] ?? null, ['weekly', 'monthly', 'yearly'], true) ? $input['repeat'] : null,
            'settles_expense_id' => $kind === self::KIND_EXPENSE && ! empty($input['settles_expense_id']) ? (int) $input['settles_expense_id'] : null,
            'payment_purpose' => match (true) {
                $kind === self::KIND_PAYMENT && in_array($input['payment_purpose'] ?? null, ['advance', 'loan'], true) => $input['payment_purpose'],
                // Müşteri avansı: para alındı, mal sonra verilecek (Hıdır/Zülfikar, Erkan 13 Ekim).
                $kind === self::KIND_COLLECTION && ($input['payment_purpose'] ?? null) === 'advance' => 'advance',
                default => null,
            },
            // Yön belirsizse tahmin yok — kullanıcıya "kim kime borçlu?" sorulur.
            'debt_side' => $kind === self::KIND_DEBT_NOTE
                ? (in_array($input['debt_side'] ?? null, ['receivable', 'payable'], true) ? $input['debt_side'] : 'unclear')
                : null,
            'reply' => $kind === self::KIND_HELP && ! empty($input['reply']) ? trim((string) $input['reply']) : null,
        ];
    }
}
