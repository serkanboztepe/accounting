<?php

namespace App\Services\Agent;

use App\Models\AssistantMemory;
use App\Models\Party;
use App\Services\Agent\Ops\AgentProposal;
use App\Services\Agent\Ops\OperationAdapter;
use App\Services\Agent\Tools\ReadTools;
use Illuminate\Support\Facades\Cache;
use App\Tenancy\Tenancy;

/**
 * Bir turun araç kutusu (plan v5 §2): okuma araçları + öneri/onay/soru/hafıza. Müşterinin HAM mesajı burada tutulur — onay ve
 * vazgeçme kararında ajanın söylediğine değil, bu metne bakılır. Gölge modunda yazan araçlar hiçbir şey yapmaz, yapılmış gibi döner.
 *
 * Turun dışarıya etkisi `outcome`da toplanır: gönderilecek kart, soru, onay metni. Müşteriye giden rakamlı metni kod üretir.
 */
final class AgentToolbox
{
    /** @var array{card: ?string, proposal_id: ?int, confirmed: ?string, question: ?string, cancelled: bool, notes: list<string>, handover: ?string, media: ?array{0:string,1:string}} */
    public array $outcome = ['card' => null, 'proposal_id' => null, 'confirmed' => null, 'question' => null, 'cancelled' => false, 'notes' => [], 'handover' => null, 'media' => null];

    public function __construct(private string $phone, private string $rawMessage, private bool $shadow = false) {}

    /** Tüm firmalarda aynı sırada aynı tanımlar (önbellek); yalnız sql_select firmaya göre var/yok. */
    public static function definitions(): array
    {
        $entry = [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => [...OperationAdapter::KINDS, ...OperationAdapter::EXTENDED_KINDS],
                    'description' => 'expense = kişisiz harcama (mazot, kira, fatura) · payment = kişiye verilen para · collection = kişiden alınan para · '
                        . 'sale = kişiye satılan iş/mal · purchase = kişiden alınan iş/mal · debt_note = eski/devreden borç ya da alacak (kayıtlarda yoktu) · '
                        . 'trade_sale = ÜRÜNLER\'deki malın satışı ya da günün kasası/cirosu (items; kasa: cash_amount + card_amount, kişi yok) · '
                        . 'trade_purchase = ÜRÜNLER\'deki malın alışı, stoğa girer (items) · stock_count = sayım ("un 9 kaldı") · stock_setting = günlük kullanım / uyarı eşiği / alış fiyatı · '
                        . 'product_add = yeni ürün kartı · production = kendi ürettiğin mal stoğa girer · salary = personele maaş ödemesi ya da maaştan kesinti · '
                        . 'employee_setting = personel kaydı / maaşı / maaş günü · reminder = hatırlatma kur ya da değiştir · recurring_expense = düzenli gider (her gün/hafta/ay) · '
                        . 'recurring_stop = düzenli gideri durdur · product_rename = ürün kartının adı değişiyor · forget = öğretilen bilgiyi unut (memory_subject) · agreement = kişiyle ileride gelecek mal/iş için anlaşma (agreement_items, agreement_payments, yön) · '
                        . 'agreement_delivery = açık anlaşmadan mal geldi / iş yapıldı (delivery_lines; AÇIK ANLAŞMALAR\'daki kalem id\'leri). '
                        . 'Ürünlü, personelli ve anlaşmalı türler yalnız o özelliği açık işletmede çalışır; kapalıysa sistem söyler. '
                        . 'ÇEK: payment / collection (ya da ödenmiş purchase / sale) kaydına `checks` (tek tek: no, vade, tutar) ya da `check_plan` (kural: "3 çek aylık") ekle.'],
                'amount' => ['type' => 'number', 'description' => 'TL, tam tutar (120 bin → 120000)'],
                'party_id' => ['type' => ['integer', 'null'], 'description' => 'Kayıtlı kişi (KİŞİLER listesi / find_party)'],
                'party_name' => ['type' => ['string', 'null'], 'description' => 'Kişi kayıtlı değilse adı, müşterinin yazdığı gibi'],
                'new_party' => ['type' => ['boolean', 'null'], 'description' => 'true: benzer adlı kayıtlı kişi olsa da bu YENİ biri (müşteri teyit ettiyse)'],
                'date' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD; söylenmediyse null (bugün)'],
                'description' => ['type' => ['string', 'null'], 'description' => 'Kısa açıklama (ne için / ne alındı); yoksa null'],
                'paid' => ['type' => ['boolean', 'null'], 'description' => 'sale/purchase/expense: parası ödendi mi. sale/purchase için zorunlu — bilmiyorsan sor'],
                'payment_type' => ['type' => ['string', 'null'], 'enum' => [...OperationAdapter::PAYMENT_TYPES, null], 'description' => 'Söylendiyse: cash, eft, bank_transfer, check…'],
                'purpose' => ['type' => ['string', 'null'], 'enum' => [...OperationAdapter::PURPOSES, null],
                    'description' => 'payment/collection: debt = var olan borca karşılık · advance = önden, iş/mal sonra · loan = borç verdim · on_behalf = o kişi ADINA başkasına ödendi (harç, fatura, kurs ücreti), hesabına yazılır'],
                'debt_side' => ['type' => ['string', 'null'], 'enum' => ['payable', 'receivable', null], 'description' => 'debt_note: payable = biz ona borçluyuz · receivable = o bize borçlu'],
                'project_id' => ['type' => ['integer', 'null'], 'description' => 'Yer / şantiye / şube (listeden)'],
                'project_name' => ['type' => ['string', 'null'], 'description' => 'Listede olmayan YENİ yer adı (müşteri söylediyse)'],
                'no_project' => ['type' => ['boolean', 'null'], 'description' => 'true: müşteri "genel" dedi, yere yazılmayacak'],
                'category_id' => ['type' => ['integer', 'null'], 'description' => 'expense: gider kategorisi (listeden)'],
                'category_name' => ['type' => ['string', 'null'], 'description' => 'expense: listede yoksa yeni kategori adı'],
                'due_date' => ['type' => ['string', 'null'], 'description' => 'Veresiye satış/alış/borçta ödeme günü söylendiyse YYYY-MM-DD'],
                'agreement_id' => ['type' => ['integer', 'null'], 'description' => 'payment/collection belli bir anlaşma içinse (party_overview → anlaşmalar)'],
                'allow_duplicate' => ['type' => ['boolean', 'null'], 'description' => 'true: aynı kişi/tutar yakın günde var ama müşteri AYRI ödeme olduğunu söyledi'],
                'total_ok' => ['type' => ['boolean', 'null'], 'description' => 'agreement: sistem "kalemlerin toplamı belgedeki toplamla tutmuyor" dedi ve müşteri kalemlerin doğru olduğunu söyledi'],
                'payments_skipped' => ['type' => ['boolean', 'null'], 'description' => 'agreement: sistem ödeme tutarlarını sordu ve müşteri ödeme olmadığını / geçilmesini söyledi'],
                'replace' => ['type' => ['string', 'null'], 'enum' => ['replace', 'separate', null], 'description' => 'agreement: aynı kişiyle açık anlaşma varken — replace = eskisinin yerine (eskiler kapanır) · separate = ayrı anlaşma (müşteri söylediyse)'],
                'no_agreement' => ['type' => ['boolean', 'null'], 'description' => 'payment/collection: kişinin açık anlaşması var ama müşteri bu paranın anlaşma dışı olduğunu söyledi'],
                'purchase_confirmed' => ['type' => ['boolean', 'null'], 'description' => 'purchase: sistem "bu kişi müşterin" dedi, müşteri gerçekten ondan aldığını söyledi'],
                'payee_answered' => ['type' => ['boolean', 'null'], 'description' => 'expense + unnamed_payee: sistem "bu para X\'e mi gitti" dedi, müşteri ayrı gider dedi'],
                'stock_done' => ['type' => ['boolean', 'null'], 'description' => 'goods_name: müşteri bu malın stoğunu tutmadığını söyledi'],
                'past_ok' => ['type' => ['boolean', 'null'], 'description' => 'checks: vadesi geçmiş çek için müşteri "eski çek, öyle kalsın" dedi'],
                'past_checks' => ['type' => ['string', 'null'], 'enum' => ['paid', 'pending', null], 'description' => 'agreement: vadesi geçmiş çekler için müşteri cevabı — paid = ödendi · pending = bekliyor'],
            ],
            'required' => ['kind'],
        ];
        // Ürün, kasa, stok, maaş, personel, hatırlatma, düzenli gider alanları: eski asistanın şemasındaki tanımlarıyla (aynı anlam, aynı kayıt).
        $legacy = app(\App\Services\Whatsapp\ExpenseExtractor::class)->entrySchema()['properties'] ?? [];
        foreach (OperationAdapter::EXTENDED_FIELDS as $field) {
            if (isset($legacy[$field]) && ! isset($entry['properties'][$field])) {
                $entry['properties'][$field] = $legacy[$field];
            }
        }

        return [
            ...ReadTools::definitions(),
            [
                'name' => 'propose',
                'description' => 'Kayıt / silme / düzeltme ÖNER (hiçbir şey yazmaz). İşlemler sırayla sistemde denenir: eksik ya da çelişki varsa "bulgular" döner '
                    . '(düzeltip yeniden öner ya da müşteriye sor); yoksa kartı sistem hazırlar ve bu turun sonunda müşteriye gönderir — sen kartı yazma. '
                    . 'Sonuçtaki "etki" gerçek kayıttan gelir (bakiye önce → sonra): müşterinin anlattığıyla tutmuyorsa (ör. "622 kaldı" dedi ama kart başka gösteriyor) '
                    . 'öneriyi düzelt. Birbirine bağlı işlemleri TEK öneride ver (önce eski borç, sonra ödeme). Yeni öneri öncekinin yerine geçer.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'ops' => ['type' => 'array', 'description' => 'Sırayla uygulanacak işlemler', 'items' => [
                            'type' => 'object',
                            'properties' => [
                                'type' => ['type' => 'string', 'enum' => AgentProposal::OP_TYPES,
                                    'description' => 'record = yeni kayıt (entry) · delete = kayıt sil (refs) · edit = kaydı düzelt (ref + entry: doğru hâlinin TAMAMI) · '
                                        . 'target_balance = "X kaldı / X borcum var": kayıtla karşılaştır (party_id, side, amount, reason) · '
                                        . 'check_cleared = bekleyen çek ödendi / tahsil edildi (check_ids; yeni para kaydı yazılmaz) · '
                                        . 'return = iade: sattığımız mal geri geldi / aldığımız mal geri verildi (party, text, side, quantity, amount, money_back)'],
                                'entry' => $entry,
                                'refs' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'delete: silinecek kayıtların ref\'leri (ör. "ledger:12")'],
                                'ref' => ['type' => ['string', 'null'], 'description' => 'edit: düzeltilecek kaydın ref\'i'],
                                'party_id' => ['type' => ['integer', 'null'], 'description' => 'target_balance: kişi'],
                                'side' => ['type' => ['string', 'null'], 'enum' => ['we_owe', 'they_owe', 'closed', 'sale', 'purchase', null],
                                    'description' => 'target_balance: we_owe / they_owe / closed (müşterinin söylediği durum) · return: sale = bizim sattığımız geri geldi, purchase = bizim aldığımız geri gitti'],
                                'amount' => ['type' => ['number', 'null'], 'description' => 'target_balance: söylenen kalan tutar · return: iade tutarı (yalnız söylendiyse)'],
                                'check_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'check_cleared: çek id\'leri (party_overview → açık_çekler ya da report open_checks)'],
                                'party' => ['type' => ['string', 'null'], 'description' => 'return: kime satılmıştı / kimden alınmıştı (kayıtlı ad)'],
                                'text' => ['type' => ['string', 'null'], 'description' => 'return: mal adı ya da açıklamadaki kelime ("yem", "süt")'],
                                'date' => ['type' => ['string', 'null'], 'description' => 'return: asıl satış/alış tarihi söylendiyse YYYY-MM-DD'],
                                'quantity' => ['type' => ['number', 'null'], 'description' => 'return: geri gelen/giden miktar; söylenmediyse null (tamamı)'],
                                'money_back' => ['type' => ['boolean', 'null'], 'description' => 'return: true = parası da geri verildi/alındı'],
                                'reason' => ['type' => ['string', 'null'], 'enum' => ['payment', 'old_debt', null],
                                    'description' => 'target_balance, fark varsa ve müşteri sebebini söylediyse: payment = yazılmamış ödeme/tahsilat · old_debt = eski borç farklıydı'],
                            ],
                            'required' => ['type'],
                        ]],
                    ],
                    'required' => ['ops'],
                ],
            ],
            [
                'name' => 'confirm',
                'description' => 'Açık kartı kaydet. Yalnız müşteri BU mesajında onay verdiyse çağır. Sistem müşterinin kendi mesajına bakar: onay yoksa, kartta olmayan '
                    . 'bir rakam yazdıysa ya da kayıtlar değiştiyse reddeder ve sebebini söyler. Başarılıysa "kaydedildi" mesajını sistem gönderir.',
                'input_schema' => ['type' => 'object', 'properties' => ['proposal_id' => ['type' => 'integer', 'description' => 'ŞU ANKİ DURUM → açık öneri id']], 'required' => ['proposal_id']],
            ],
            [
                'name' => 'cancel',
                'description' => 'Açık kartı kapat (kayıt yapılmaz). Yalnız müşteri bu mesajında vazgeçtiyse ("iptal", "vazgeç", "hayır", "kaydetme").',
                'input_schema' => ['type' => 'object', 'properties' => ['proposal_id' => ['type' => 'integer']], 'required' => ['proposal_id']],
            ],
            [
                'name' => 'ask',
                'description' => 'Müşteriye TEK soru sor ve turu bitir. Bakiyeyi/kaydın türünü değiştiren bir şey belli değilse kullan. Soru kısa, müşterinin kelimeleriyle; '
                    . 'seçenek varsa 2–3 tane (sistem numaralar). Aynı soru ikinci kez sorulamaz — müşterinin verdiği cevabı yorumla.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'question' => ['type' => 'string'],
                        'options' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Kısa seçenekler (isteğe bağlı; çoğu soruda 2–3, yer/şantiye listesi gibi durumlarda hepsi — en çok 12)'],
                    ],
                    'required' => ['question'],
                ],
            ],
            [
                'name' => 'send_statement',
                'description' => 'Bir kişinin hesap dökümünü (ekstre) PDF olarak müşteriye gönder ve turu bitir: "Ahmet ekstre", "Yakup taş extra getir", "hesap dökümü". '
                    . 'Yalnız bakiye sorulduysa (PDF istenmediyse) party_overview ile cevap ver.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'party_id' => ['type' => 'integer'],
                    'date_from' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD (dönem söylendiyse)'],
                    'date_to' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD'],
                ], 'required' => ['party_id']],
            ],
            [
                'name' => 'hand_over',
                'description' => 'SON ÇARE: bu mesajı eski asistana devret ve turu bitir. Artık her işi kendin yapabiliyorsun; bunu yalnız `propose` bir işi iki denemede de '
                    . 'kabul etmediyse ve bulgudan nasıl düzelteceğin çıkmıyorsa kullan. Normal işlerde (para, ürün, kasa, stok, maaş, hatırlatma, anlaşma, çek, iade, ekstre) devretme.',
                'input_schema' => ['type' => 'object', 'properties' => ['reason' => ['type' => 'string', 'description' => 'Kısaca hangi iş (ör. "maaş ödemesi", "hatırlatma")']], 'required' => ['reason']],
            ],
            [
                'name' => 'remember',
                'description' => 'İşletme hakkında kalıcı, KESİN bir bilgiyi hafızaya yaz (müşteriye sormadan; sabah dökümünde görünür): takma ad ("Asansörcü = Yıldız '
                    . 'Asansör"), kim kimdir, hangi usta hangi şantiyede. Para/bakiye kararı, tek seferlik bilgi ve tahmin YAZILMAZ. Alışkanlık notu soruyu '
                    . 'atlatmaz: bakiyeyi değiştiren şey her seferinde sorulur.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'kind' => ['type' => 'string', 'enum' => array_keys(AssistantMemory::KINDS)],
                        'subject' => ['type' => 'string', 'description' => 'Kısa konu (ör. "Asansörcü")'],
                        'fact' => ['type' => 'string', 'description' => 'Tek cümle bilgi'],
                        'party_id' => ['type' => ['integer', 'null']],
                    ],
                    'required' => ['kind', 'subject', 'fact'],
                ],
            ],
        ];
    }

    public function run(string $name, array $input): array
    {
        if (ReadTools::has($name)) {
            return ReadTools::run($name, $name === 'report' ? ['_phone' => $this->phone] + $input : $input);
        }

        return match ($name) {
            'propose' => $this->propose($input),
            'confirm' => $this->confirm($input),
            'cancel' => $this->cancel($input),
            'ask' => $this->ask($input),
            'remember' => $this->remember($input),
            'hand_over' => $this->handOver($input),
            'send_statement' => $this->sendStatement($input),
            default => ['hata' => 'Böyle bir araç yok: ' . $name],
        };
    }

    /** Tur bitti mi (müşteriye gidecek kesin bir şey oluştu): soru soruldu ya da onay verildi. */
    public function finished(): bool
    {
        return $this->outcome['question'] !== null || $this->outcome['confirmed'] !== null || $this->outcome['handover'] !== null || $this->outcome['media'] !== null;
    }

    private function propose(array $in): array
    {
        $r = AgentProposal::propose($this->phone, (array) ($in['ops'] ?? []), $this->rawMessage, store: ! $this->shadow);
        if (! $r['ok']) {
            $this->outcome['card'] = null;
            $this->outcome['proposal_id'] = null;

            return isset($r['noop']) ? ['yazılacak_bir_şey_yok' => $r['noop']] : ['bulgular' => $r['issues'], 'not' => 'Öneri kabul edilmedi, kart yok. Bulguyu çöz: kayıtta/konuşmada cevabı varsa düzeltip yeniden öner, yoksa ask ile sor.'];
        }
        $this->outcome['card'] = $r['card'];
        $this->outcome['proposal_id'] = $r['proposal_id'] ?? null;

        return ['kabul' => true, 'proposal_id' => $r['proposal_id'] ?? null, 'kart (sistem müşteriye gönderecek)' => $r['card'], 'etki' => $r['effects'],
            'uyarılar' => $r['warnings'], 'not' => 'Kart bu turun sonunda gider. Etki müşterinin anlattığıyla tutuyorsa turu bitir (kartı tekrar yazma); tutmuyorsa düzeltip yeniden öner.'];
    }

    private function confirm(array $in): array
    {
        if ($this->shadow) {
            return ['gölge' => 'Onay gölge modunda uygulanmaz (yapılmış sayıldı).'];
        }
        if ($this->outcome['proposal_id'] !== null) {
            return ['hata' => 'Bu turda yeni önerilen kart henüz müşteriye gösterilmedi — onay sonraki mesajda alınır.'];
        }
        $r = AgentProposal::confirm($this->phone, (int) ($in['proposal_id'] ?? 0), $this->rawMessage);
        if (! $r['ok']) {
            return ['kaydedilmedi' => $r['reason']];
        }
        $this->outcome['confirmed'] = $r['text'];

        return ['kaydedildi' => true, 'müşteriye_giden' => $r['text'], 'not' => 'Sistem bu metni gönderdi. Ekleyecek önemli bir şey yoksa bir şey yazma.'];
    }

    private function cancel(array $in): array
    {
        if ($this->shadow) {
            return ['gölge' => 'Vazgeçme gölge modunda uygulanmaz.'];
        }
        $r = AgentProposal::cancel($this->phone, (int) ($in['proposal_id'] ?? 0), $this->rawMessage);
        if (! $r['ok']) {
            return ['kapatılmadı' => $r['reason']];
        }
        $this->outcome['cancelled'] = true;

        return ['kapatıldı' => true, 'not' => 'Müşteriye kısa bir "tamam, yazmadım" de.'];
    }

    private function ask(array $in): array
    {
        $question = trim((string) ($in['question'] ?? ''));
        if ($question === '') {
            return ['hata' => 'Soru boş.'];
        }
        $options = array_slice(array_values(array_filter(array_map(fn ($o) => is_string($o) ? trim($o) : '', (array) ($in['options'] ?? [])), 'strlen')), 0, 12);
        // Tekrar-soru kilidi (Kaya 08.11: aynı soru 5 kez): aynı soru bu numaraya son 6 saatte sorulduysa reddedilir.
        $key = Tenancy::key('agent-asked:' . sha1($this->phone));
        $asked = (array) Cache::get($key, []);
        $sig = sha1(Tools\FindParty::fold($question . ' ' . implode(' ', $options)));
        if (in_array($sig, $asked, true)) {
            return ['hata' => 'Bu soruyu az önce sordun. Müşterinin verdiği cevabı (son konuşmaya bak) yorumla: anlamı çıkıyorsa ona göre öner; çıkmıyorsa '
                . 'soruyu BAŞKA kelimelerle, somut bir örnekle sor ya da en olası yorumu kartta gösterip onayına sun.'];
        }
        if (! $this->shadow) {
            Cache::put($key, array_slice([...$asked, $sig], -20), now()->addHours(6));
        }
        $text = '❓ ' . $question;
        foreach ($options as $i => $option) {
            $text .= "\n*" . ($i + 1) . '* ' . $option;
        }
        $this->outcome['question'] = $text;

        return ['soruldu' => true, 'not' => 'Soru müşteriye gidiyor; tur bitti.'];
    }

    /** Ekstre PDF'i: eski yolla aynı imzalı, 10 dakikalık bağlantı; metni ve bakiyeyi kod yazar. */
    private function sendStatement(array $in): array
    {
        $party = Party::find((int) ($in['party_id'] ?? 0));
        if (! $party) {
            return ['hata' => 'Bu id ile kişi yok — find_party ile bul.'];
        }
        $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $filters = array_filter(['date_from' => $date($in['date_from'] ?? null), 'date_to' => $date($in['date_to'] ?? null)]);
        $balance = (float) \App\Support\PartyStatement::build($party, $filters)['balance'];
        $lines = ['📄 ' . $party->name . ' — Hesap Dökümü'];
        if ($filters !== []) {
            $lines[] = 'Dönem: ' . ($filters['date_from'] ?? '…') . ' – ' . ($filters['date_to'] ?? '…');
        }
        $lines[] = \App\Support\PartyBalances::line((string) $party->name, $balance);
        if ($this->shadow) {
            return ['gölge' => 'Ekstre gölge modunda gönderilmez.'];
        }
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('whatsapp.statement.pdf', now()->addMinutes(10), [
            'party' => $party->id,
            'file' => 'Ekstre-' . (\Illuminate\Support\Str::slug($party->name) ?: $party->id) . '.pdf',
            'detailed' => 1,
        ] + $filters + array_filter(['firm' => Tenancy::current()?->id]));
        $this->outcome['media'] = [implode("\n", $lines), $url];

        return ['gönderildi' => true, 'not' => 'PDF müşteriye gidiyor; tur bitti.'];
    }

    private function handOver(array $in): array
    {
        $this->outcome['handover'] = mb_substr(trim((string) ($in['reason'] ?? '')), 0, 120) ?: 'belirtilmedi';

        return ['devredildi' => true];
    }

    private function remember(array $in): array
    {
        $fact = mb_substr(trim((string) ($in['fact'] ?? '')), 0, 300);
        $subject = mb_substr(trim((string) ($in['subject'] ?? '')), 0, 100);
        if ($fact === '' || $subject === '' || ! isset(AssistantMemory::KINDS[$in['kind'] ?? ''])) {
            return ['hata' => 'kind, subject ve fact gerekli.'];
        }
        $partyId = ! empty($in['party_id']) && Party::whereKey((int) $in['party_id'])->exists() ? (int) $in['party_id'] : null;
        if ($this->shadow) {
            return ['gölge' => 'Hafıza gölge modunda yazılmaz.'];
        }
        if (AssistantMemory::where('status', 'active')->where('fact', $fact)->exists()) {
            return ['zaten_var' => true];
        }
        AssistantMemory::create(['kind' => $in['kind'], 'subject' => $subject, 'fact' => $fact, 'party_id' => $partyId, 'status' => 'active', 'source' => 'ajan']);
        $this->outcome['notes'][] = 'hafıza: ' . $fact;

        return ['yazıldı' => true];
    }
}
