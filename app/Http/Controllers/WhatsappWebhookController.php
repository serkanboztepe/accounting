<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractPayment;
use App\Models\Expense;
use App\Models\HubAnnouncement;
use App\Models\ExpenseCategory;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Models\Purchase;
use App\Models\Reminder;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\AdvanceOffset;
use App\Services\Whatsapp\ChatAssistant;
use App\Services\Whatsapp\DraftFollowUp;
use App\Services\Whatsapp\DraftGaps;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\TradeDraft;
use App\Services\Whatsapp\HubRouter;
use App\Services\Whatsapp\PartyNameCheck;
use App\Support\Agenda;
use App\Support\Announcements;
use App\Support\DailyNudge;
use App\Support\CheckReminders;
use App\Support\ContractStatus;
use App\Support\ConversationContext;
use App\Support\ExpenseSummary;
use App\Support\Money;
use App\Support\PartyBalances;
use App\Support\PartyStatement;
use App\Support\Reminders;
use App\Support\Phone;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Twilio WhatsApp Sandbox webhook'u.
 *
 * Akış: müteahhit fotoğraf/metin atar → AI çıkarır → teyit metni döner →
 * müteahhit "evet" der → kayıt yazılır. Tür (kind) AI'dan gelir:
 *   expense → Expense; payment/sale/collection → cari hareketi (party_ledger_entries);
 *   balance_query → sadece cevap, kayıt yok.
 * Ödeme (payment) maliyet DEĞİLDİR — borcu düşürür. Carinin açık borcu yoksa
 * "iş/mal mı aldın, avans mı?" sorulur (çift sayımı önler). Cevap TwiML olarak döner
 * (aynı sohbette görünür, REST gönderime gerek yok).
 */
class WhatsappWebhookController extends Controller
{
    /** Geri alma taslağı (sohbet asistanı "son kaydı sil"). */
    private const KIND_UNDO = 'undo';

    /** Bu tutardan itibaren özette tutar yazıyla da gösterilir. */
    private const WORDS_FROM = 1_000_000;

    /** Cari hareketi türleri — kişi eşleşmesi "aynı kişi mi?" kontrolünden geçer. */
    private const PARTY_KINDS = [ExpenseExtractor::KIND_PAYMENT, ExpenseExtractor::KIND_SALE, ExpenseExtractor::KIND_COLLECTION,
        ExpenseExtractor::KIND_DEBT_NOTE, ExpenseExtractor::KIND_PURCHASE];

    public function __invoke(Request $request, ExpenseExtractor $extractor)
    {
        $phone = (string) $request->input('From', '');
        $body = trim((string) $request->input('Body', ''));
        $numMedia = (int) $request->input('NumMedia', 0);

        // Hub (APP_ROLE=hub) veya tek panel: önce telefonun firmasını bul. Tek panelde router
        // firmayı açıp buraya geri çağırır (via_hub) — o zaman aşağıdaki firma akışı çalışır.
        if ((config('app.role') === 'hub' || Tenancy::enabled()) && ! $request->attributes->get('via_hub')) {
            return app(HubRouter::class)->forward($request);
        }

        // 0) Sadece kayıtlı telefonlar — yabancı numara AI'ya da veriye de ulaşmasın.
        if (! $this->isAllowedPhone($request, $phone)) {
            return HubRouter::unknownPhoneResponse($phone);
        }

        // Peş peşe gelen mesajlar ("kira 20 bin" + hemen "ödeme yapıldı") aynı anda işlenince ikincisi
        // taslağı göremiyor, ayrı ayrı cevaplanıyordu → aynı telefonun mesajları sıraya girer.
        $lock = Cache::lock(Tenancy::key('wa-msg:' . Phone::normalize($phone)), 30);
        try {
            $lock->block(12);
        } catch (LockTimeoutException) {
            $lock = null; // uzun süren bir önceki mesaj (fotoğraf) — beklemeden devam
        }

        try {
            $firstContact = $this->firstContact = $this->isFirstContact($phone);
            $incoming = $this->logMessage($phone, 'in', $body, $numMedia > 0);

            $response = $this->respond($request, $extractor, $phone, $body, $numMedia);

            // İlk kez yazan: cevabın ardından ikinci mesaj olarak KISA karşılama (ne yazabileceğini bilmeden
            // deneme-yanılmayla vazgeçmesin — Arda vakası). Uzun liste yalnız "nasıl"da: 30 satırlık rehberi alan
            // iki kişi bir daha yazmadı (Akkuş Fırın, 13 Ekim). İlk mesaj anlaşılmadıysa ("Hesap takibi") genel AI
            // cevabı + karşılama üst üste gitmesin — yalnız karşılama.
            if ($firstContact && $this->inboundKind !== 'guide') {
                $replies = $this->inboundKind === 'help' ? [] : $this->replies;
                $this->replies = [...$replies, $this->welcomeText()];
                $response = $this->twimlMany($this->replies);
            }
            // Bekleyen duyuru: ilk mesajda iptal (hoş geldin yeter, ikisi birden asla); değilse iş bitince bir kez.
            if ($firstContact) {
                $this->cancelAnnouncement($phone);
            } elseif ($this->replies !== [] && ($announcement = $this->announcementToSend($phone))) {
                $response = $this->twimlMany([...$this->replies, $announcement->body]);
                $announcement->update(['status' => 'sent', 'sent_at' => now()]);
            }

            if ($this->touchedPartyId) {
                // Hatırlatmaya cevap olarak tahsilat/ödeme onaylandıysa ("aldım" → evet) hatırlatma kapanır.
                if ($this->committedPartyId
                    && ($reminder = Reminder::awaitingReply(Phone::normalize($phone), $this->committedPartyId))) {
                    $reminder->acknowledge();
                }
                ConversationContext::remember($phone, $this->touchedPartyId);
            }

            $incoming?->update(['kind' => $this->inboundKind]);
            foreach ($this->replies as $reply) {
                // [metin, PDF linki] → ek olduğu kayda geçsin (analiz "hareketler gösterilmedi" sanmasın).
                $this->logMessage($phone, 'out', is_array($reply) ? $reply[0] : $reply, is_array($reply));
            }

            return $response;
        } finally {
            $lock?->release();
        }
    }

    /** Bu mesajda konuşulan cari (bakiye, ekstre, taslak, kayıt) → sınırlı bağlam (ConversationContext). */
    private ?int $touchedPartyId = null;

    /** Bu mesajda KAYDEDİLEN (onaylanan) işlemin carisi — bekleyen hatırlatmayı kapatmak için. */
    private ?int $committedPartyId = null;

    /** Bu numaranın ilk mesajı mı (karşılama)? */
    private bool $firstContact = false;

    /** Son gelen mesajın türü (konuşma kaydı): AI türü / quick / guide / checks / error / no_draft. */
    private ?string $inboundKind = null;

    /** Gelen mesajın metni — kod emniyetleri (ör. teslim edilmemiş satış = avans) AI'dan bağımsız buna bakar. */
    private string $inboundBody = '';

    /** @var list<string|array{0:string,1:string}> bu isteğin cevap(lar)ı — kayıt ve ilk mesaj rehberi için */
    private array $replies = [];

    private function respond(Request $request, ExpenseExtractor $extractor, string $phone, string $body, int $numMedia)
    {
        $this->inboundBody = $body;
        // Akşam mesajının düğmeleri ("Kayıt gireceğim / Hatırlatma kur / Bugün yok") ve aç/kapa — AI'sız.
        if ($numMedia === 0) {
            $word = $this->word($body);
            // "yenilikler" şablonunun düğmesi ya da "yenilikler" yazısı → duyurunun metni (AI'sız).
            if (Announcements::isRequest((string) $request->input('ButtonPayload', ''), $word)) {
                $this->inboundKind = 'announcement';

                return $this->twiml(Announcements::answer($phone));
            }
            if ($button = DailyNudge::button((string) $request->input('ButtonPayload', ''), $word)) {
                $this->inboundKind = 'nudge_reply';

                return $this->twiml(DailyNudge::reply($button));
            }
            if (($enable = DailyNudge::toggleWord($word)) !== null) {
                $this->inboundKind = 'nudge_toggle';
                DailyNudge::setEnabled($phone, $enable);

                return $this->twiml($enable
                    ? 'Tamam, akşam mesajlarını açtım. Her akşam 19:00\'da "bugün aklında bir şey var mı?" diye soracağım. 👋'
                    : "Tamam, akşam mesajlarını kapattım. 👍\nTekrar istersen *akşam mesajı aç* yaz.");
            }
        }

        // "nasıl" / "yardım" — sektöre göre rehber, AI'sız.
        if ($numMedia === 0 && in_array($this->word($body), ['nasıl', 'nasil', 'yardım', 'yardim', 'menü', 'menu', 'neler yapabilirsin'], true)) {
            $this->inboundKind = 'guide';

            return $this->twiml($this->firstContact ? $this->welcomeText() : $this->guideText());
        }

        // "hatırlatmalarım" — bekleyen hatırlatmalar; "iptal 2" — listedeki 2. hatırlatmayı sil. AI'sız.
        if ($numMedia === 0 && in_array($this->word($body), ['hatırlatmalarım', 'hatırlatmalar', 'hatirlatmalarim', 'hatirlatmalar', 'hatırlatmalarımı göster'], true)) {
            $this->inboundKind = 'reminder_list';

            return $this->twiml(Reminders::listText($phone));
        }
        if ($numMedia === 0 && preg_match('/^iptal\s+(\d{1,2})$/u', $this->word($body), $m)) {
            $this->inboundKind = 'reminder_cancel';
            $reminder = Reminder::upcomingFor(Phone::normalize($phone))->skip((int) $m[1] - 1)->first();
            if (! $reminder) {
                return $this->twiml("Listede {$m[1]} numaralı hatırlatma yok. *hatırlatmalarım* yazıp numaraya bak.");
            }
            $reminder->update(['status' => 'cancelled', 'next_fire_at' => null]);

            return $this->twiml('❌ Hatırlatma silindi: ' . $reminder->text);
        }

        // "cari" / "cariler" tek kelime — cari listesi, AI'sız (Arda: "Cari" yazınca "anlayamadım" alıyordu).
        if ($numMedia === 0 && in_array($this->word($body), ['cari', 'cariler', 'carilerim', 'cari listesi', 'carim'], true)) {
            $this->inboundKind = ExpenseExtractor::KIND_PARTY_LIST;

            return $this->twiml($this->partyListAnswer());
        }

        // "çekler" — vadesi yaklaşan çek listesi (hatırlatmanın devamı). AI'sız, doğrudan veriden.
        if ($numMedia === 0 && config('modules.checks')
            && in_array($this->word($body), ['çekler', 'cekler', 'çeklerim', 'ceklerim', 'çek', 'cek'], true)) {
            $this->inboundKind = 'checks';

            return $this->twiml(CheckReminders::listText());
        }

        // 1) Onay / iptal — bekleyen taslağa cevap mı? Yalnızca SON 30 DK içindeki taslak
        // "önceki" sayılır; terk edilmiş eski taslaklar yeni mesaja bağlam olup proje/cari
        // sızdırmasın (updated_at: aktif gidip gelme penceresi taze tutar).
        // Birden fazla açık taslak olabilir (yeni kayıt eskisini kapatmaz, yarım kalan olarak takip edilir):
        // en son dokunulan etkindir — hatırlatılan yarım kayıt da bu yüzden cevabı alır.
        // Hatırlatması gönderilmiş yarım kayıt 24 saat etkin kalır: geç gelen cevap ("8500") ona bağlansın.
        $pending = WhatsappPendingExpense::where('phone', $phone)
            ->where('status', 'awaiting_confirmation')
            ->where(fn ($q) => $q->where('updated_at', '>=', now()->subMinutes(30))
                ->orWhere('followed_up_at', '>=', now()->subHours(24)))
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->first();

        // Ses / video / belge: şimdilik okuyamıyoruz — sessizce yardım metni yerine açıkça söyle.
        // (Sesi yazıya çevirme planlı; bkz. hafıza: ses kaydı desteği.)
        $mediaType = (string) $request->input('MediaContentType0', '');
        if ($numMedia > 0 && $mediaType !== '' && ! Str::startsWith($mediaType, 'image/')) {
            return $this->twiml(Str::startsWith($mediaType, 'audio/')
                ? "🎤 Sesli mesajları henüz dinleyemiyorum. Yazıyla gönderir misin? 🙏\nÖrnek: \"Ali'ye 15 bin verdim\""
                : "Şu an yazı ve fotoğraf okuyabiliyorum. Fişin/faturanın fotoğrafını çekip gönderir misin? 📷");
        }

        // Hızlı cevaplar (evet/iptal/numara) dışında AI çağrılacak → "yazıyor…" göster.
        $quickReply = $numMedia === 0 && ($this->isConfirm($body) || $this->isCancel($body) || ctype_digit($body));
        if ($quickReply) {
            $this->inboundKind = 'quick';
        }
        if (! $quickReply && ($body !== '' || $numMedia > 0)) {
            $this->sendTypingIndicator((string) $request->input('MessageSid', ''));
        }

        $image = $numMedia > 0 ? $this->downloadMedia($request) : null;

        // Bekleyen taslak yokken gelen "evet"/"iptal"/numara: yeni kayıt SANMA (0 ₺'lik gider açılıyordu).
        // Taslak yokken "tamam" / "evet": az önce gönderilen hatırlatma(lar) görüldü (kayıt yok). Kalan
        // hatırlatmalar iptal edilmez — olay günü yine hatırlatılır.
        if (! $pending && $image === null && $this->isConfirm($body)
            && ($seen = Reminder::allAwaitingReply(Phone::normalize($phone)))->isNotEmpty()) {
            $this->inboundKind = 'reminder_ack';
            $lines = $seen->map(function (Reminder $r) {
                $r->markSeen();

                return '• ' . $r->text . ($r->next_fire_at ? ' (yine hatırlatacağım: ' . Reminders::when($r->next_fire_at) . ')' : '');
            });

            return $this->twiml("✅ Tamam.\n" . $lines->implode("\n"));
        }

        if (! $pending && $image === null && ($this->isConfirm($body) || $this->isCancel($body) || ctype_digit($body))) {
            $this->inboundKind = 'no_draft';

            return $this->twiml('Onay bekleyen bir kayıt yok (taslaklar 30 dk geçerli). Yeni bir işlem yazabilirsin.');
        }

        // Kartta olmayan malın alışı: "gider" → ürün kartı açılmaz, aynı alış gider olarak yazılır (AI'sız, kesin cevap).
        if ($pending && $image === null && $this->word($body) === 'gider' && TradeDraft::canBecomeExpense($pending->extracted)) {
            $this->inboundKind = 'quick';

            return $this->twiml($this->saveDraft($pending, $phone, TradeDraft::toExpense($pending->extracted)));
        }

        // Satış mı alış mı belirsizdi → "sattım" / "aldım" (AI'sız, kesin cevap).
        if ($pending && $image === null && DraftGaps::needsSaleOrPurchase($pending->extracted)) {
            $kind = match ($this->word($body)) {
                'sattım', 'satış', 'sattim', 'satis', 'götürdüm', 'ona götürdüm', 'verdim', 'ona verdim', 'o bana borçlu' => ExpenseExtractor::KIND_SALE,
                'aldım', 'alış', 'aldim', 'alis', 'ondan aldım', 'o getirdi', 'ben borçluyum', 'ben ona borçluyum' => ExpenseExtractor::KIND_PURCHASE,
                default => null,
            };
            if ($kind !== null) {
                $this->inboundKind = 'quick';
                $data = ['kind' => $kind, 'direction_unclear' => false, 'question' => null] + $pending->extracted;

                return $this->twiml($this->saveDraft($pending, $phone, $data));
            }
        }

        // Eşleşen cari ile yazılan ad farklıydı, "aynı kişi mi?" → "yeni": yazılan adla yeni cari (AI'sız, kesin cevap).
        if ($pending && $image === null && ! empty($pending->extracted['party_check'])
            && in_array($this->word($body), ['yeni', 'yeni cari', 'yeni kişi', 'farklı', 'farklı kişi', 'başka', 'başka kişi', 'aynı değil', 'değil'], true)) {
            $this->inboundKind = 'quick';
            $data = $pending->extracted;
            $data['party_name'] = $data['party_said'];
            $data['party_id'] = Party::where('name', $data['party_said'])->value('id'); // aynı adla açılmış cari varsa o
            $data['party_said'] = null;

            return $this->twiml($this->saveDraft($pending, $phone, $data));
        }

        // Sohbet asistanı (deneme, ayarla açılır): yazılı mesajlar konuşmayı gören asistana gider.
        // Kesin cevaplar (evet / iptal / numara) eskisi gibi kodda kalır — onu AI'a sormaya gerek yok.
        if (ChatAssistant::enabled() && $image === null && $body !== ''
            && ! ($pending && ($this->isConfirm($body) || $this->isCancel($body) || ctype_digit($body)))) {
            return $this->twiml($this->chatTurn($pending, $phone, $body));
        }

        if ($pending) {
            if ($this->isConfirm($body)) {
                return $this->twiml($this->commitThenNext($pending, $phone));
            }
            if ($this->isCancel($body)) {
                $pending->update(['status' => 'cancelled']);

                return $this->twiml('İptal edildi. ✖️');
            }

            // Alacak/borç kaydı, yön belirsiz → "o bana" / "ben ona".
            if ($this->needsDebtSide($pending->extracted)) {
                $side = match ($this->word($body)) {
                    'o bana', 'bana', 'o bana borçlu', 'o borçlu', 'alacak', 'alacağım' => 'receivable',
                    'ben ona', 'ona', 'ben ona borçluyum', 'ben borçluyum', 'borç', 'borcum' => 'payable',
                    default => null,
                };
                if ($side !== null) {
                    $data = $pending->extracted;
                    $data['debt_side'] = $side;
                    $data['question'] = null; // AI'ın "kim kime borçlu?" sorusu cevaplandı

                    return $this->twiml($this->saveDraft($pending, $phone, $data));
                }
                if ($this->isConfirm($body)) {
                    return $this->twiml('Önce yönü yaz: *o bana* (o sana borçlu) ya da *ben ona* (sen ona borçlusun).');
                }
            }

            // Borç ödemesi eşleşmesi yanlış → "yeni": ayrı bir gider olarak yaz.
            if (! empty($pending->extracted['settles_expense_id'])
                && in_array($this->word($body), ['yeni', 'ayrı', 'ayri', 'yeni gider', 'ayrı gider'], true)) {
                $data = $pending->extracted;
                // Açıklama eşleşen borçtan kopyalandıysa ("Ev kira ödemesi") yeni gidere taşınmasın → kategori adı.
                $matched = Expense::find($data['settles_expense_id']);
                if ($matched && trim((string) $data['description']) === trim((string) $matched->description)) {
                    $category = ! empty($data['category_id']) ? ExpenseCategory::find($data['category_id'])?->name : null;
                    $data['description'] = $category ?? ($data['category_name'] ?? 'Gider');
                }
                $data['settles_expense_id'] = null;

                return $this->twiml($this->saveDraft($pending, $phone, $data));
            }

            // Tutarı eksik taslak ("Kira" → "Kira tutarı ne kadar?") + sadece sayı ("20000", "20.000 tl")
            // → tutar budur. Eskiden soruyu sorup taslak açmıyor, cevaba "onay bekleyen kayıt yok" diyordu.
            $typedAmount = $this->typedAmount($body);
            if ($typedAmount !== null && (float) ($pending->extracted['amount'] ?? 0) <= 0
                && $this->kind($pending->extracted) !== ExpenseExtractor::KIND_REMINDER) {
                $data = $pending->extracted;
                $data['amount'] = $typedAmount;
                $data['question'] = null;

                return $this->twiml($this->saveDraft($pending, $phone, $data));
            }

            // Sözleşmeli cariye ödeme, birden fazla aktif sözleşme → hangisi?
            if ($this->needsContractChoice($pending->extracted) && ctype_digit($body)) {
                $contract = $this->activeContracts($this->existingParty($pending->extracted))->get((int) $body - 1);
                if (! $contract) {
                    return $this->twiml('Listede o numara yok. Sözleşme numarasını yaz.');
                }
                $data = $pending->extracted;
                $data['contract_id'] = $contract->id;

                return $this->twiml($this->saveDraft($pending, $phone, $data));
            }

            // Ödeme ama carinin açık borcu yok → "Ne için verdin?" sorusu bekleniyor. Serbest cevap
            // aşağıda AI düzeltmesiyle okunur (iş yaptırdım / avans / borç verdim). 1-2 eski menü
            // cevapları hâlâ geçerli.
            if ($this->needsPaymentChoice($pending->extracted)) {
                if ($body === '1') {
                    // Yapılmış iş / gelmiş mal → peşin alış (fitToModules carili gideri alışa çevirir). Önce "ne için?" sorulur: yoksa açıklama
                    // sadece "Ali'ye ödeme" kalıyor, işin ne olduğu/kategorisi görünmüyordu. Cevap
                    // düzeltme (refine) olarak işlenir → açıklama + kategori dolar, sonra özet.
                    $data = $pending->extracted;
                    $data['kind'] = ExpenseExtractor::KIND_EXPENSE;
                    $data['paid'] = true;
                    $data = ExpenseExtractor::fitToModules($data); // carili → peşin alış
                    $pending->update(['extracted' => $data]);

                    return $this->twiml("Ne için ödedin? Kısaca yaz (ör. \"sıva işçiliği\", \"kaba inşaat\").\nAtlamak için *evet*.");
                }
                if ($body === '2') {
                    $data = $pending->extracted;
                    $data['advance'] = true;
                    $pending->update(['extracted' => $data]);

                    return $this->twiml($this->commit($pending));
                }
                if ($this->isConfirm($body)) {
                    return $this->twiml("Önce ne için verdiğini kısaca yaz (ör. \"sıva yaptırdım\", \"iş yaptıracağım, avans\", \"borç verdim\").");
                }
            }

            // Sadece sayı → proje menüsünden seçim (yalnız gider taslağında menü gösterilir). Kullanıcı tüm özeti ilk mesajda
            // zaten gördü; numara = "her şey doğru + proje bu" → direkt kaydet (tek adım).
            if (config('modules.projects') && ctype_digit($body) && $this->kind($pending->extracted) === ExpenseExtractor::KIND_EXPENSE
                && empty($pending->extracted['settles_expense_id'])) {
                $projects = $this->activeProjects();
                $chosen = $projects->get((int) $body - 1);
                if ($chosen) {
                    $data = $pending->extracted;
                    $data['project_id'] = $chosen->id;
                    $data['project_name'] = null;
                    $pending->update(['extracted' => $data]);

                    return $this->twiml($this->commit($pending));
                }

                return $this->twiml('Listede o numara yok. Tekrar bir numara yaz ya da proje adını yaz.');
            }

            // Metin (fotoğrafsız): önceki taslağa düzeltme mi, yoksa tamamen yeni bir kayıt mı?
            // Kararı AI verir (is_new_entry) — kurallar kırılgan. Yeni giderse eski taslağın
            // proje/cari/kategorisi SIZMASIN diye taze taslak açılır.
            if ($image === null && $body !== '') {
                try {
                    $data = $extractor->extract($body, null, $pending->extracted);
                } catch (\Throwable $e) {
                    Log::error('WhatsApp gider düzeltme hatası', ['msg' => $e->getMessage()]);

                    $this->inboundKind = 'error';

                    return $this->twiml('Şu an okuyamadım, birazdan tekrar dener misin? 🙏');
                }

                if (! empty($data['is_new_entry'])) {
                    // Yeni gider → SIFIRDAN çıkar: previous verilince model önceki proje/cari'yi sızdırabiliyor;
                    // temiz çıkarım için previous=null ile yeniden. Eski taslak açık kalır (yarım kayıt takibi).
                    try {
                        $data = $extractor->extract($body, null, null, ConversationContext::get($phone));
                    } catch (\Throwable $e) {
                        Log::error('WhatsApp yeni gider çıkarma hatası', ['msg' => $e->getMessage()]);

                        $this->inboundKind = 'error';

                    return $this->twiml('Şu an okuyamadım, birazdan tekrar dener misin? 🙏');
                    }

                    return $this->twiml($this->saveDraft(null, $phone, $data));
                }

                // Düzeltme/ek bilgi → mevcut taslağı güncelle. AI'ın bilmediği seçimler (sözleşme,
                // avans) aynı cari için korunur — "nakit" diye düzeltince sözleşme tekrar sorulmasın.
                if (($data['party_id'] ?? null) === ($pending->extracted['party_id'] ?? null)) {
                    $data += array_intersect_key($pending->extracted, ['contract_id' => true, 'advance' => true, 'payment_purpose' => true, 'advance_declined' => true]);
                }

                return $this->twiml($this->saveDraft($pending, $phone, $data));
            }

            // Yeni fotoğraf → yeni gider (düzeltme değil): aşağıda taze başla, eski taslak yarım kayıt olarak kalır.
        }

        // 2) Yeni gider girişi (metin ve/veya fotoğraf)
        if ($image === null && $body === '') {
            $this->inboundKind = 'guide';

            return $this->twiml($this->guideText());
        }

        try {
            $data = $extractor->extract($body ?: null, $image, null, ConversationContext::get($phone));
        } catch (\Throwable $e) {
            Log::error('WhatsApp gider çıkarma hatası', ['msg' => $e->getMessage()]);
            $this->inboundKind = 'error';

            return $this->twiml('Şu an okuyamadım, birazdan tekrar dener misin? 🙏');
        }

        return $this->twiml($this->saveDraft(null, $phone, $data, $numMedia > 0 ? (string) $request->input('MediaUrl0') : null));
    }

    /**
     * Kaydet; aynı mesajda başka kalem kaldıysa ("5400 parça, 10.000 işçilik") sıradakini hemen öner
     * (Oktay, 11 Ekim: ikinci kalem kayboluyordu, ayrıca yazmak zorunda kaldı).
     */
    private function commitThenNext(WhatsappPendingExpense $pending, string $phone): string|array
    {
        $done = $this->commit($pending);
        $next = $pending->extracted['next_items'] ?? null;
        if ($pending->fresh()?->status !== 'confirmed') {
            return $done;
        }
        if (! ChatAssistant::enabled() || ! $next) {
            return $this->withLeftover($done, $phone, $pending->id);
        }

        try {
            $result = app(ChatAssistant::class)->respond($phone, 'Sıradaki kalem: ' . $next, null);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp sıradaki kalem önerilemedi', ['msg' => $e->getMessage()]);

            return $done . "\n\nAynı mesajdaki diğer kalemi (" . ExpenseExtractor::nextItemsLabel($next) . ') ayrıca yazar mısın?';
        }
        if ($result['type'] !== 'entry') {
            return $done . "\n\n" . ($result['text'] ?? '');
        }
        $data = $result['data'];
        $data['is_new_entry'] = true;
        $out = $this->saveDraft(null, $phone, $data);

        return is_string($out) ? $done . "\n\n➡️ *Sıradaki:*\n" . $out : $done;
    }

    /** İş bitti: yarım kalan en eski kayıt varsa cevabın sonunda sorulur (Oktay "kira" yazıp onaylamadı). */
    private function withLeftover(string|array $done, string $phone, int $exceptId): string|array
    {
        if (! is_string($done)) {
            return $done;
        }
        try {
            $left = DraftFollowUp::inlineCandidate($phone, $exceptId);
            if ($left === null) {
                return $done;
            }
            DraftFollowUp::markSent($left);

            return $done . "\n\n" . DraftFollowUp::inlineText($left);
        } catch (\Throwable $e) {
            Log::warning('Yarım kayıt kontrolü yapılamadı', ['error' => $e->getMessage()]);

            return $done;
        }
    }

    /** Duyuru yalnız iş bitmişse: onay bekleyen kayıt (taslak) varken araya girmez. Hata akışı bozmaz. */
    private function announcementToSend(string $phone): ?HubAnnouncement
    {
        try {
            $busy = WhatsappPendingExpense::where('phone', $phone)
                ->where('status', 'awaiting_confirmation')
                ->where('updated_at', '>=', now()->subMinutes(30))
                ->exists();

            return $busy ? null : HubAnnouncement::pendingFor($phone);
        } catch (\Throwable $e) {
            Log::warning('Duyuru kontrolü yapılamadı', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function cancelAnnouncement(string $phone): void
    {
        try {
            HubAnnouncement::pendingFor($phone)?->update(['status' => 'cancelled']);
        } catch (\Throwable) {
            // duyuru tablosu yoksa önemli değil
        }
    }

    /** Sohbet asistanının kararını uygula: cevap / taslak öner / onayla / vazgeç. Kayıt yine commit()'te. */
    private function chatTurn(?WhatsappPendingExpense $pending, string $phone, string $body): string|array
    {
        try {
            $result = app(ChatAssistant::class)->respond($phone, $body, $pending?->extracted);
        } catch (\Throwable $e) {
            Log::error('WhatsApp sohbet asistanı hatası', ['msg' => $e->getMessage()]);
            $this->inboundKind = 'error';

            return 'Şu an okuyamadım, birazdan tekrar dener misin? 🙏';
        }

        switch ($result['type']) {
            case 'confirm':
                $this->inboundKind = 'quick';
                if (! $pending) {
                    return 'Onay bekleyen bir kayıt yok. Yeni bir işlem yazabilirsin.';
                }
                if (($result['paid'] ?? null) !== null
                    && in_array($this->kind($pending->extracted), [ExpenseExtractor::KIND_EXPENSE, ExpenseExtractor::KIND_PURCHASE], true)) {
                    $pending->update(['extracted' => ['paid' => $result['paid']] + $pending->extracted]);
                }

                return $this->commitThenNext($pending, $phone);
            case 'ask_confirm':
                $this->inboundKind = 'chat';

                return $pending
                    ? "Kaydedeyim mi? *evet* / *tamam* / *ok* — değiştirmek istediğin bir şey varsa yaz.\n\n" . $pending->summary
                    : 'Onay bekleyen bir kayıt yok. Yeni bir işlem yazabilirsin.';
            case 'undo':
                $this->inboundKind = 'undo';

                return $this->undoDraft($pending, $phone, $result['replacement'] ?? null);
            case 'cancel':
                $this->inboundKind = 'quick';
                $pending?->update(['status' => 'cancelled']);

                return $pending ? 'İptal edildi. ✖️' : 'Tamam.';
            case 'entry':
                $data = $result['data'];
                if ($pending && ! empty($data['is_new_entry'])) {
                    $pending = null; // eski taslak açık kalır — iş bitince "yarım kalan var" diye sorulur
                }
                if ($pending && ($data['party_id'] ?? null) === ($pending->extracted['party_id'] ?? null)) {
                    $data += array_intersect_key($pending->extracted, ['contract_id' => true, 'advance' => true, 'payment_purpose' => true, 'advance_declined' => true]);
                }
                // Düzeltmede sıradaki kalem unutulmasın (Recep takası: peynir satışı düzeltme turunda kayboldu).
                if ($pending && empty($data['next_items']) && ! empty($pending->extracted['next_items'])) {
                    $data['next_items'] = $pending->extracted['next_items'];
                }
                $out = $this->saveDraft($pending, $phone, $data);
                $said = trim((string) ($result['text'] ?? ''));

                return $said !== '' && is_string($out) ? $said . "\n\n" . $out : $out;
            default:
                $this->inboundKind = 'chat';

                return $result['text'];
        }
    }

    /**
     * Taslağı kaydet/güncelle ve müteahhide gidecek metni döndür.
     * Bakiye sorusu taslak açmaz — direkt cevaplanır.
     */
    private function saveDraft(?WhatsappPendingExpense $pending, string $phone, array $data, ?string $mediaUrl = null): string|array
    {
        $data = ExpenseExtractor::guardUndeliveredSale(ExpenseExtractor::fitToModules($data), $this->inboundBody);
        if ($pending === null && ChatAssistant::enabled()) {
            $data = ExpenseExtractor::guardSwap($data, $this->inboundBody); // yeni kayıtta; düzeltmede AI kuralı yeterli
        }
        // Tutar ikinci mesajda gelince AI kişinin yazılışını tekrarlamıyor — "aynı kişi mi?" kaybolmasın.
        if ($pending && empty($data['party_said']) && ! empty($pending->extracted['party_said'])
            && ($data['party_id'] ?? null) === ($pending->extracted['party_id'] ?? null)) {
            $data['party_said'] = $pending->extracted['party_said'];
        }
        $data = AdvanceOffset::apply($data, $this->inboundBody);
        $data['party_check'] = in_array($this->kind($data), self::PARTY_KINDS, true) && ($party = $this->existingParty($data))
            && PartyNameCheck::differs($party, $data['party_said'] ?? null);
        $this->inboundKind = $this->kind($data);
        if (! empty($data['party_id']) && Party::whereKey($data['party_id'])->exists()) {
            $this->touchedPartyId = (int) $data['party_id']; // bakiye / ekstre / taslak — sınırlı bağlam
        }

        // "Kirayı ödedim" → AI açık bir borçla eşleştirdiyse doğrula: borç hâlâ açık mı, tutar sığıyor mu?
        // Tutar söylenmediyse borcun tamamı; borçtan fazlaysa yeni gider sayılır (ör. yeni ayın kirası).
        if (! empty($data['settles_expense_id'])) {
            $open = $this->openExpense((int) $data['settles_expense_id']);
            if ($open && (float) ($data['amount'] ?? 0) <= 0) {
                $data['amount'] = (float) $open->amount;
                $data['question'] = null;
            }
            // Borç başka birine aitse eşleşme yanlış — ayrı gider.
            $differentParty = $open && $open->party_id && ! empty($data['party_id']) && (int) $data['party_id'] !== (int) $open->party_id;
            if (! $open || $differentParty || (float) $data['amount'] > (float) $open->amount + 0.01) {
                $data['settles_expense_id'] = null;
            }
        }

        // "Ali'ye 15 bin avans / borç verdim" ya da "Ne için verdin?" cevabı → masraf/avans sorusu yok.
        if ($this->kind($data) === ExpenseExtractor::KIND_PAYMENT && in_array($data['payment_purpose'] ?? null, ['advance', 'loan'], true)) {
            $data['advance'] = true;
        }

        if ($this->kind($data) === ExpenseExtractor::KIND_HELP) {
            // Anlaşılamayan mesaj: zorla en yakın türe sokmak yerine yönlendir. Bekleyen taslağa dokunma.
            return $data['reply'] ?: $this->guideText();
        }
        // Stoklu satış paketi (satış/alış belgesi, sayım, stok sorusu, ay sonucu) — yalnız o firmalarda.
        if (TradeDraft::handles($data)) {
            return $this->saveTradeDraft($pending, $phone, $data);
        }
        if ($this->kind($data) === ExpenseExtractor::KIND_AGENDA) {

            return Agenda::text($data['date_from'] ?? null, $data['date_to'] ?? null, $phone);
        }
        if ($this->kind($data) === ExpenseExtractor::KIND_PARTY_LIST) {

            return $this->partyListAnswer();
        }
        if ($this->kind($data) === ExpenseExtractor::KIND_DEBT_NOTE
            && ($data['debt_side'] ?? null) === 'unclear' && ! config('modules.cari_supplier')) {
            $data['debt_side'] = 'receivable'; // tedarikçi tarafı kapalı: tek olası yön
        }
        if ($this->kind($data) === ExpenseExtractor::KIND_DEBT_NOTE
            && ($data['debt_side'] ?? null) === 'payable' && ! config('modules.cari_supplier')) {
            // Düzeltmeyle yön "biz borçluyuz" çıktı: eski (ters yönlü) taslak açık kalıp sonra "kaydedeyim mi?"
            // diye hatırlatılmasın (Ferhat, 14 Ekim: Akın Dil borcu alacak olarak hatırlatıldı).
            $pending?->update(['status' => 'superseded']);

            return 'Bu hesapta borç kaydı (biz borçluyuz) açık değil; alacaklarını yazabilirsin. Örnek: "Ali\'den 40 bin alacağım var"';
        }
        if ($this->kind($data) === ExpenseExtractor::KIND_BALANCE_QUERY) {

            return $this->balanceAnswer($data);
        }
        if ($this->kind($data) === ExpenseExtractor::KIND_TOTALS_QUERY) {

            return $this->totalsAnswer($data);
        }
        if ($this->kind($data) === ExpenseExtractor::KIND_STATEMENT) {
            $answer = $this->statementAnswer($data);

            // "Ferdi'ye at": cariye biz yazamayız (Meta izni / karşı tarafın onayı yok) — sessizce kullanıcıya
            // göndermek yerine açıkça söyle ve nasıl ileteceğini anlat (Ferhat Bey gönderildi sanmıştı).
            if (! empty($data['forward_to_party']) && is_array($answer)) {
                $name = $this->existingParty($data)?->name ?? 'carine';
                $answer[0] .= "\n\nℹ️ {$name} kişisine doğrudan mesaj atamıyorum. Ekstreyi sana gönderdim; "
                    . "PDF'e basılı tut → *İlet* ile ona gönderebilirsin.";
            }

            return $answer;
        }
        if ($this->kind($data) === ExpenseExtractor::KIND_EXPENSE_SUMMARY) {

            return ExpenseSummary::text($data['date_from'] ?? null, $data['date_to'] ?? null, $data['project_id'] ?? null);
        }

        if ($this->kind($data) === ExpenseExtractor::KIND_REMINDER && ! empty($data['event_date'])) {
            return $this->saveReminderDraft($pending, $phone, $data);
        }

        // Tutar eksik ("Kira"): soruyu sor AMA taslağı tut — cevap ("20000") bu taslağa işlensin.
        // 0 ₺ taslak onaylanamaz (commit tutarı kontrol eder).
        if ((float) ($data['amount'] ?? 0) <= 0) {
            $question = '❓ ' . ($data['question'] ?: 'Tutarı ne kadar? Sadece rakamı yazman yeterli (ör. 20000).');
            if ($pending) {
                $pending->update(['extracted' => $data, 'summary' => $question]);
            } else {
                WhatsappPendingExpense::create([
                    'phone' => $phone, 'extracted' => $data, 'summary' => $question,
                    'media_url' => $mediaUrl, 'status' => 'awaiting_confirmation',
                ]);
            }

            return $question . "\nVazgeçmek için *iptal*.";
        }

        // Hatırlatılan yarım kayıt, eksiği yalnız tutardı: kişi kaydı zaten anlatmıştı → tutar gelince
        // ikinci onay sorulmadan kaydedilir (yanlışsa "son kaydı sil"). Normal kayıtlarda özet + onay aynen.
        if ($pending && $pending->follow_ups > 0 && (float) ($pending->extracted['amount'] ?? 0) <= 0
            && (float) ($data['amount'] ?? 0) > 0 && $this->kind($data) === $this->kind($pending->extracted)
            && DraftGaps::reason($data)['code'] === DraftGaps::CONFIRM && empty($data['party_check'])) {
            $label = DraftGaps::label($data);
            $pending->update(['extracted' => $data, 'summary' => $label]);
            $done = $this->commitThenNext($pending, $phone);

            return is_string($done) ? $label . "\n" . $done . "\n\nYanlışsa *son kaydı sil* yaz." : $done;
        }

        $summary = $this->buildSummary($data);
        // Büyük tutar yazıyla: "96 tosun 17.017000 tl" tek "tamam"la 17 milyon girdi — rakam gözden kaçmasın.
        if ((float) ($data['amount'] ?? 0) >= self::WORDS_FROM) {
            $summary .= "\n💬 Yazıyla: *" . Money::words((float) $data['amount']) . ' lira*';
        }
        // AI şüphelendiyse ("Bu borç ona mı, ondan mı?") alanlar dolu olsa bile soruyu göster — tahmin sessizce geçmesin.
        if (! empty($data['question']) && ! $this->missingParty($data) && ! $this->needsDebtSide($data) && ! DraftGaps::needsSaleOrPurchase($data)
            && ! str_contains($summary, $data['question'])) {
            $summary .= "\n❓ " . $data['question'];
        }
        // Aynı mesajdaki ikinci kalem görünsün — kullanıcı unutulduğunu sanıp tekrar yazmasın.
        if (! empty($data['next_items'])) {
            $summary .= "\n➡️ Sırada: " . ExpenseExtractor::nextItemsLabel($data['next_items']) . ' (bunu onaylayınca sorarım)';
        }
        $footer = match (true) {
            $this->needsContractChoice($data) => "\n\nSözleşme numarasını yaz, vazgeçmek için *iptal*.",
            $this->needsPaymentChoice($data) => "\n\nKısaca yaz, vazgeçmek için *iptal*.",
            $this->missingParty($data) => "\n\nCari adını yaz, vazgeçmek için *iptal*.",
            DraftGaps::needsSaleOrPurchase($data) => "\n\n*sattım* ya da *aldım* yaz, vazgeçmek için *iptal*.",
            $this->needsDebtSide($data) => "\n\n*o bana* (o sana borçlu) ya da *ben ona* (sen ona borçlusun) yaz, vazgeçmek için *iptal*.",
            ! empty($data['settles_expense_id']) => "\n\n✅ Onaylamak için *evet*. Ayrı (yeni) bir gider ise *yeni* yaz, vazgeçmek için *iptal*.",
            ChatAssistant::enabled() => "\n\nKaydedeyim mi? *evet* / *tamam* / *ok* — yanlış bir şey varsa düzeltmeyi yazman yeterli.",
            default => "\n\n✅ Onaylamak için *evet*, vazgeçmek için *iptal* yaz.",
        };

        if ($pending) {
            $pending->update(['extracted' => $data, 'summary' => $summary]);
        } else {
            WhatsappPendingExpense::create([
                'phone' => $phone,
                'extracted' => $data,
                'summary' => $summary,
                'media_url' => $mediaUrl,
                'status' => 'awaiting_confirmation',
            ]);
        }

        return $summary . $footer;
    }

    /**
     * Onaylanan taslaktan kaydı oluştur (türe göre Expense veya cari hareketi).
     */
    private function commit(WhatsappPendingExpense $pending): string
    {
        if (($pending->extracted['kind'] ?? null) === self::KIND_UNDO) {
            return $this->commitUndo($pending);
        }

        // Bu onayla oluşan gider / cari satırları taslağa yazılır → "son kaydı sil" tam olarak bunları siler.
        self::listenForCreatedRecords();
        self::$recording = [];
        try {
            $out = $this->commitDraft($pending);
        } finally {
            $created = self::$recording;
            self::$recording = null;
        }
        if ($created !== [] && $pending->fresh()?->status === 'confirmed') {
            $pending->update(['extracted' => ['created_records' => $created] + $pending->extracted]);
        }

        return $out;
    }

    /** @var list<array{0:string,1:int}>|null commit sırasında oluşan [tür, id] */
    private static ?array $recording = null;

    private static ?int $listeningOn = null;

    private static function listenForCreatedRecords(): void
    {
        $events = spl_object_id(app('events'));
        if (self::$listeningOn === $events) {
            return;
        }
        self::$listeningOn = $events;
        Expense::created(function (Expense $e) {
            if (self::$recording !== null) {
                self::$recording[] = ['expense', $e->id];
            }
        });
        PartyLedgerEntry::created(function (PartyLedgerEntry $l) {
            if (self::$recording !== null) {
                self::$recording[] = ['ledger', $l->id];
            }
        });
        // Stoklu satış: belge silinince stok ve cari satırları birlikte gider (cascade).
        Sale::created(function (Sale $sale) {
            if (self::$recording !== null) {
                self::$recording[] = ['sale', $sale->id];
            }
        });
        Purchase::created(function (Purchase $purchase) {
            if (self::$recording !== null) {
                self::$recording[] = ['purchase', $purchase->id];
            }
        });
        StockMovement::created(function (StockMovement $m) {
            if (self::$recording !== null && $m->reason === StockMovement::REASON_ADJUSTMENT) {
                self::$recording[] = ['stock', $m->id];
            }
        });
    }

    /**
     * "Son kaydı sil / yanlış girdim": bu numaradan son 24 saatte onaylanan SON kaydı göster, onayla sil.
     * Yalnız o onayın OLUŞTURDUĞU gider/cari satırları (created_records) silinir; cari/kategori kalır.
     */
    private function undoDraft(?WhatsappPendingExpense $pending, string $phone, ?array $replacement = null): string
    {
        $last = WhatsappPendingExpense::where('phone', $phone)
            ->where('status', 'confirmed')
            ->where('updated_at', '>=', now()->subDay())
            ->latest('id')
            ->first();
        $targets = collect($last?->extracted['created_records'] ?? [])
            ->filter(fn ($t) => $this->undoModel($t) !== null)
            ->values()->all();

        if (! $last || ($last->extracted['kind'] ?? null) === self::KIND_UNDO || $targets === []) {
            return 'Buradan geri alabileceğim bir kayıt bulamadım (son 24 saatte bu numaradan girilen son kayıt silinebilir). Eski kayıtları panelden düzeltebilirsin.';
        }

        if ($replacement !== null) {
            $replacement = ExpenseExtractor::fitToModules($replacement);
            if ($this->kind($replacement) === ExpenseExtractor::KIND_HELP || (float) ($replacement['amount'] ?? 0) <= 0) {
                $replacement = null; // yazılamayacak düzeltme → yalnız silme önerilir
            }
        }

        $pending?->update(['status' => 'superseded']);
        $isSettle = collect($targets)->contains(fn ($t) => ($t[0] ?? null) === 'settle');
        $summary = ($isSettle ? "↩️ *Şu ödemeyi geri alacağım (borç tekrar açılır):*\n" : "🗑️ *Şu kaydı sileceğim:*\n") . trim((string) $last->summary);
        if ($replacement !== null) {
            $summary .= "\n\n✏️ *Yerine yazacağım:*\n" . $this->shortLine($replacement);
        }
        WhatsappPendingExpense::create([
            'phone' => $phone,
            'extracted' => ['kind' => self::KIND_UNDO, 'undo_of' => $last->id, 'targets' => $targets, 'replacement' => $replacement],
            'summary' => $summary,
            'status' => 'awaiting_confirmation',
        ]);

        return $summary . match (true) {
            $replacement !== null => "\n\nDüzelteyim mi? *evet* / *iptal*",
            $isSettle => "\n\nGeri alayım mı? *evet* / *iptal*",
            default => "\n\nSileyim mi? *evet* / *iptal*",
        };
    }

    /** Düzeltmede yeni kaydın tek satırlık özeti (bakiye yok — eski kayıt silinince değişir). */
    private function shortLine(array $d): string
    {
        $kind = $this->kind($d);
        $label = match ($kind) {
            ExpenseExtractor::KIND_EXPENSE => '📝 Gider',
            ExpenseExtractor::KIND_SALE => '🧾 Satış',
            ExpenseExtractor::KIND_PURCHASE => '🛒 Alış',
            ExpenseExtractor::KIND_PAYMENT => '💸 Ödeme (sen → o)',
            ExpenseExtractor::KIND_COLLECTION => '💰 Tahsilat (o → sana)',
            ExpenseExtractor::KIND_DEBT_NOTE => ($d['debt_side'] ?? null) === 'payable' ? '📒 Borç (sen ona borçlusun)' : '📒 Alacak (o sana borçlu)',
            ExpenseExtractor::KIND_TRADE_SALE => ! empty($d['paid']) ? '🧾 Peşin satış' : '🧾 Veresiye satış',
            ExpenseExtractor::KIND_TRADE_PURCHASE => '🛒 Alış',
            default => '📝 Kayıt',
        };
        $party = $this->existingParty($d)?->name ?? ($d['party_name'] ?? null);
        $paid = in_array($kind, [ExpenseExtractor::KIND_EXPENSE, ExpenseExtractor::KIND_PURCHASE], true) && isset($d['paid'])
            ? ($d['paid'] ? 'ödendi' : 'ödenmedi') : null;

        return implode(' — ', array_filter([$label, $party, Money::format((float) $d['amount']) . ' ₺', $d['description'] ?? null, $paid]));
    }

    private function undoModel(array $target): ?\Illuminate\Database\Eloquent\Model
    {
        return match ($target[0] ?? null) {
            'expense', 'settle' => Expense::find($target[1] ?? 0),
            'ledger' => PartyLedgerEntry::find($target[1] ?? 0),
            'sale' => Sale::find($target[1] ?? 0),
            'purchase' => Purchase::find($target[1] ?? 0),
            'stock' => StockMovement::find($target[1] ?? 0),
            default => null,
        };
    }

    private function commitUndo(WhatsappPendingExpense $pending): string
    {
        $partyIds = [];
        $deleted = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($pending, &$partyIds, &$deleted) {
            foreach ((array) ($pending->extracted['targets'] ?? []) as $target) {
                $model = $this->undoModel($target);
                if ($model && ($target[0] ?? null) === 'settle') {
                    // Borç ödemesi geri alınır: gider silinmez, ödeme öncesi hâline döner (borç tekrar açılır).
                    $model->update(['amount' => $target[2], 'payment_status' => $target[3], 'party_id' => $target[4] ?? null]);
                    $partyIds[] = $model->party_id;
                    $deleted++;
                } elseif ($model) {
                    $partyIds[] = $model->party_id;
                    $model->delete();
                    $deleted++;
                }
            }
            WhatsappPendingExpense::whereKey($pending->extracted['undo_of'] ?? 0)->update(['status' => 'undone']);
            $pending->update(['status' => 'confirmed']);
        });

        if ($deleted === 0) {
            return 'O kayıt zaten silinmiş.';
        }

        // Düzeltme: eski silindi → doğrusu aynı onayla yazılır (o da geri alınabilir).
        $replacement = $pending->extracted['replacement'] ?? null;
        if (is_array($replacement)) {
            $new = WhatsappPendingExpense::create([
                'phone' => $pending->phone, 'extracted' => $replacement,
                'summary' => $this->shortLine($replacement), 'status' => 'awaiting_confirmation',
            ]);

            return "✏️ Düzeltildi.\n" . $this->commit($new);
        }
        $party = Party::find(collect($partyIds)->filter()->last());

        $settled = collect((array) ($pending->extracted['targets'] ?? []))->contains(fn ($t) => ($t[0] ?? null) === 'settle');

        return ($settled ? '↩️ Ödeme geri alındı, borç tekrar açık.' : '🗑️ Silindi.') . ($party ? ' ' . $this->balanceLine($party->name, PartyStatement::build($party)['balance']) : '')
            . "\nDoğrusunu yazarsan hemen kaydederim.";
    }

    private function commitDraft(WhatsappPendingExpense $pending): string
    {
        $d = ExpenseExtractor::fitToModules($pending->extracted);
        if ($d !== $pending->extracted) {
            // Gider kapalıyken kalmış eski gider taslağı: kaydetmeden önce alış olarak göster ya da açıkla.
            if ($this->kind($d) === ExpenseExtractor::KIND_HELP) {
                $pending->update(['status' => 'cancelled']);

                return $d['reply'];
            }
            $pending->update(['extracted' => $d]);
        }
        $kind = $this->kind($d);

        if ($kind === ExpenseExtractor::KIND_REMINDER) {
            return $this->commitReminder($pending, $d);
        }

        if (TradeDraft::handles($d) && ! in_array($kind, TradeDraft::ANSWER_KINDS, true)) {
            if ($question = TradeDraft::blocker($d)) {
                return '❓ ' . $question;
            }
            [$text, $partyId] = TradeDraft::commit($d);
            $this->touchedPartyId = $partyId ?: $this->touchedPartyId;
            $this->committedPartyId = $partyId;
            $pending->update(['status' => 'confirmed']);

            return $text;
        }

        // Eksik varsa neyin eksik olduğu söylenir ("tamam" yazınca "onay bekleyen kayıt yok" denmesin).
        $gap = DraftGaps::reason($d);
        if ($gap['code'] === DraftGaps::AMOUNT) {
            return '❓ ' . DraftGaps::label($d) . ": tutar olmadan kaydedemiyorum.\n" . $gap['ask'];
        }

        if ($kind !== ExpenseExtractor::KIND_EXPENSE) {
            if ($gap['code'] !== DraftGaps::CONFIRM) {
                return $gap['code'] === DraftGaps::PARTY
                    ? '❓ ' . $gap['ask']
                    : 'Önce ' . mb_strtolower(mb_substr($gap['ask'], 0, 1)) . mb_substr($gap['ask'], 1);
            }
            if ($kind === ExpenseExtractor::KIND_PAYMENT && $this->paymentContract($d)) {
                return $this->commitContractPayment($pending, $d, $this->paymentContract($d));
            }

            return $this->commitLedger($pending, $d, $kind);
        }

        if (! empty($d['settles_expense_id'])) {
            return $this->commitSettlement($pending, $d);
        }

        // Match-first; if no existing match but the AI proposed a name, create it on confirm
        // (firstOrCreate guards against duplicates).
        $projectId = $d['project_id'] ?? null;
        if ($projectId === null && ! empty($d['project_name'])) {
            $projectId = Project::firstOrCreate(['name' => $d['project_name']], ['status' => 'active'])->id;
        }

        $partyId = $d['party_id'] ?? null;
        if ($partyId === null && ! empty($d['party_name'])) {
            $partyId = Party::firstOrCreate(['name' => $d['party_name']])->id;
        }

        $categoryId = $d['category_id'] ?? null;
        if ($categoryId === null && ! empty($d['category_name'])) {
            $categoryId = ExpenseCategory::firstOrCreate(['name' => $d['category_name']])->id;
        }

        $this->touchedPartyId = $partyId ?: $this->touchedPartyId;
        $this->committedPartyId = $partyId ?: null;

        Expense::create([
            'project_id' => $projectId,
            'party_id' => $partyId,
            'expense_category_id' => $categoryId,
            'expense_date' => $d['date'] ?? now()->format('Y-m-d'),
            'due_date' => $d['due_date'] ?? null,
            'amount' => Money::store((float) ($d['amount'] ?? 0)),
            'payment_status' => $this->isPaid($d) ? 'paid' : 'unpaid',
            'description' => $d['description'] ?? null,
            'notes' => 'WhatsApp üzerinden girildi.',
        ]);

        $pending->update(['status' => 'confirmed']);

        return '✅ Kaydedildi: ' . Money::format((float) ($d['amount'] ?? 0)) . ' ₺ — ' . ($d['description'] ?? 'gider');
    }

    /**
     * Sözleşmeli cariye ödeme → sözleşme ödemesi (contract_payments). Böylece sözleşme raporundaki
     * "Kalan Bakiye", dashboard ve cari ekstre AYNI rakamı gösterir (cari hareketine yazılsaydı
     * sadece ekstre düşerdi). Sözleşme oluşturma panelden; ödeme WhatsApp'tan.
     */
    private function commitContractPayment(WhatsappPendingExpense $pending, array $d, Contract $contract): string
    {
        $this->touchedPartyId = $contract->party_id ?: $this->touchedPartyId;
        $this->committedPartyId = $contract->party_id;

        if (($d['payment_type'] ?? null) === 'other') {
            return 'Çekle/senetle sözleşme ödemesini şimdilik panelden gir (Sözleşme → Ödemeler): vade ve çek no gerekiyor. Nakit/havale ise *nakit* ya da *havale* yaz.';
        }

        ContractPayment::create([
            'contract_id' => $contract->id,
            'payment_date' => $d['date'] ?? now()->format('Y-m-d'),
            'payment_type' => $this->contractPaymentType($d),
            'status' => 'paid',
            'amount' => Money::store((float) ($d['amount'] ?? 0)),
            'notes' => trim(($d['description'] ?? '') . ' (WhatsApp üzerinden girildi.)'),
        ]);

        $pending->update(['status' => 'confirmed']);

        return '✅ Kaydedildi. ' . $contract->title . ' — kalan: '
            . Money::format($contract->remainingPaymentAmount()) . ' ₺';
    }

    /** WhatsApp ödeme şekli → sözleşme ödeme tipi (Nakit / Havale-EFT). Söylenmediyse havale. */
    private function contractPaymentType(array $d): string
    {
        return ($d['payment_type'] ?? null) === 'cash' ? 'cash' : 'bank_transfer';
    }

    /**
     * Ödeme / satış / tahsilat → cari hareketi. Maliyete GİRMEZ; sadece cari bakiyesini değiştirir.
     */
    private function commitLedger(WhatsappPendingExpense $pending, array $d, string $kind): string
    {
        $party = $this->resolveParty($d);
        $this->touchedPartyId = $party->id; // yeni açılan cari dahil — "5 bin daha verdi" buna bağlansın
        $this->committedPartyId = $party->id;
        $projectId = $d['project_id'] ?? null;
        if ($projectId === null && ! empty($d['project_name'])) {
            $projectId = Project::firstOrCreate(['name' => $d['project_name']], ['status' => 'active'])->id;
        }

        $type = $this->ledgerType($d);
        $description = $d['description'] ?? null;
        if ($kind === ExpenseExtractor::KIND_DEBT_NOTE) {
            // Ekstrede "Satış/Alış" satırı olarak görünür; ne olduğu açıklamadan anlaşılsın.
            $note = ($d['debt_side'] ?? null) === 'payable' ? 'Borç kaydı' : 'Alacak kaydı';
            $description = $note . ($this->isTrivialDebtText($description) ? '' : ' — ' . $description);
        }
        if (($kind === ExpenseExtractor::KIND_PAYMENT && ! empty($d['advance']))
            || ($kind === ExpenseExtractor::KIND_COLLECTION && ($d['payment_purpose'] ?? null) === 'advance')) {
            $label = ($d['payment_purpose'] ?? null) === 'loan' ? 'Borç verildi' : 'Avans';
            // "Avans — Avans ödemesi" / "Borç verildi — Borç verildi" tekrarı olmasın.
            $word = $label === 'Avans' ? 'avans' : 'borç';
            $description = $description && mb_stripos($description, $word) !== false
                ? $description
                : $label . ($description ? ' — ' . $description : '');
        }

        // Satışta birden fazla iş sayıldıysa her biri ayrı satır; yoksa tek satır.
        $rows = ($kind === ExpenseExtractor::KIND_SALE && ! empty($d['items']))
            ? $d['items']
            : [['description' => $description, 'amount' => (float) ($d['amount'] ?? 0)]];

        foreach ($rows as $row) {
            PartyLedgerEntry::create([
                'party_id' => $party->id,
                'project_id' => $projectId,
                'entry_date' => $d['date'] ?? now()->format('Y-m-d'),
                // Ödeme tarihi yalnız satış / alacak-borç kaydında (o sabah hatırlatılır, ödendiyse hatırlatılmaz).
                'due_date' => in_array($kind, [ExpenseExtractor::KIND_SALE, ExpenseExtractor::KIND_DEBT_NOTE, ExpenseExtractor::KIND_PURCHASE], true)
                    && empty($d['paid']) ? ($d['due_date'] ?? null) : null,
                'type' => $type,
                'payment_type' => $kind === ExpenseExtractor::KIND_SALE ? null : ($d['payment_type'] ?? null),
                'description' => $row['description'] ?: null,
                'amount' => Money::store((float) $row['amount']),
                'notes' => 'WhatsApp üzerinden girildi.',
            ]);
        }

        // Avanstan düşülen kısım için ikinci kez para yazılmaz; yalnız kalan kısım ödendi/tahsil edildi.
        $cashAmount = (float) ($d['amount'] ?? 0) - AdvanceOffset::used($d);

        // Peşin alış: alış + ödeme birlikte — borç oluşmaz ama ekstrede ne alındığı görünür.
        if ($kind === ExpenseExtractor::KIND_PURCHASE && ! empty($d['paid']) && $cashAmount > 0.009) {
            PartyLedgerEntry::create([
                'party_id' => $party->id,
                'entry_date' => $d['date'] ?? now()->format('Y-m-d'),
                'type' => PartyLedgerEntry::TYPE_PAYMENT,
                'payment_type' => $d['payment_type'] ?? null,
                'description' => 'Alış ödemesi' . ($description ? ' — ' . $description : ''),
                'amount' => Money::store($cashAmount),
                'notes' => 'WhatsApp üzerinden girildi.',
            ]);
        }

        // Peşin satış ("peynir sattım, parasını aldım"): satış + tahsilat birlikte — alacak oluşmaz ama ekstrede ne satıldığı görünür.
        if ($kind === ExpenseExtractor::KIND_SALE && ! empty($d['paid']) && $cashAmount > 0.009) {
            PartyLedgerEntry::create([
                'party_id' => $party->id,
                'entry_date' => $d['date'] ?? now()->format('Y-m-d'),
                'type' => PartyLedgerEntry::TYPE_COLLECTION,
                'payment_type' => $d['payment_type'] ?? null,
                'description' => 'Satış tahsilatı' . ($description ? ' — ' . $description : ''),
                'amount' => Money::store($cashAmount),
                'notes' => 'WhatsApp üzerinden girildi.',
            ]);
        }

        $pending->update(['status' => 'confirmed']);

        return '✅ Kaydedildi. ' . $this->balanceLine($party->name, PartyStatement::build($party)['balance']);
    }

    /** Stoklu satış taslağı: soru türleri doğrudan cevaplanır; kayıt türleri özet + teyit (eksik varsa soru). */
    private function saveTradeDraft(?WhatsappPendingExpense $pending, string $phone, array $data): string
    {
        if (in_array($this->kind($data), TradeDraft::ANSWER_KINDS, true)) {

            return TradeDraft::answer($data);
        }

        $question = TradeDraft::blocker($data);
        // Sayım / ayar: eksik varsa (ürün, adet) yalnız soru — özet ürünsüz kurulamaz.
        $summary = $question !== null && in_array($this->kind($data), [ExpenseExtractor::KIND_STOCK_COUNT, ExpenseExtractor::KIND_STOCK_SETTING], true)
            ? '❓ ' . $question
            : TradeDraft::summary($data) . ($question !== null ? "\n❓ " . $question : '');
        if ($question === null && (float) ($data['amount'] ?? 0) >= self::WORDS_FROM) {
            $summary .= "\n💬 Yazıyla: *" . Money::words((float) $data['amount']) . ' lira*';
        }
        $footer = $question !== null
            ? "\n\nCevabını yaz, vazgeçmek için *iptal*."
            : (ChatAssistant::enabled()
                ? "\n\nKaydedeyim mi? *evet* / *tamam* / *ok* — yanlış bir şey varsa düzeltmeyi yazman yeterli."
                : "\n\n✅ Onaylamak için *evet*, vazgeçmek için *iptal* yaz.");

        if ($pending) {
            $pending->update(['extracted' => $data, 'summary' => $summary]);
        } else {
            WhatsappPendingExpense::create(['phone' => $phone, 'extracted' => $data, 'summary' => $summary, 'status' => 'awaiting_confirmation']);
        }

        return $summary . $footer;
    }

    /**
     * Hatırlatma taslağı: hatırlatma saatleri KODDA hesaplanır (Reminder::alarmsFor) ve özette
     * gösterilir — kural her seferinde aynı uygulansın, kullanıcı ne zaman hatırlatılacağını görsün.
     */
    private function saveReminderDraft(?WhatsappPendingExpense $pending, string $phone, array $data): string
    {
        $reminder = Reminders::fromDraft($data, $phone);
        $data['question'] = null;

        $text = $reminder->next_fire_at
            ? Reminders::summary($reminder) . "\n\n✅ Onaylamak için *evet*, vazgeçmek için *iptal* yaz."
            : "❓ Bu zaman geçmiş görünüyor. Ne zaman hatırlatayım?\nVazgeçmek için *iptal*.";

        if ($pending) {
            $pending->update(['extracted' => $data, 'summary' => $text]);
        } else {
            WhatsappPendingExpense::create(['phone' => $phone, 'extracted' => $data, 'summary' => $text, 'status' => 'awaiting_confirmation']);
        }

        return $text;
    }

    private function commitReminder(WhatsappPendingExpense $pending, array $d): string
    {
        if (empty($d['event_date'])) {
            return '❓ Ne zaman hatırlatayım?';
        }

        $reminder = Reminders::fromDraft($d, $pending->phone);
        if (! $reminder->next_fire_at) {
            return '❓ Bu zaman geçmiş görünüyor. Ne zaman hatırlatayım?';
        }

        // Erteleme ("yarın tekrar hatırlat"): az önce gönderilen aynı hatırlatma cevaplanmış sayılır — ama
        // olayın kalan hatırlatması varsa (düğünün "o gün" hatırlatması) o korunur, sadece işaretlenir.
        $previous = Reminder::awaitingReply($reminder->phone);
        if ($previous && mb_strtolower($previous->text) === mb_strtolower($reminder->text)) {
            $previous->next_fire_at
                ? $previous->update(['acknowledged_at' => now()])
                : $previous->acknowledge();
        }

        $reminder->save();
        $pending->update(['status' => 'confirmed']);

        return '✅ Tamam, hatırlatacağım: ' . $reminder->text
            . "\n⏰ İlk hatırlatma: " . Reminders::when($reminder->next_fire_at)
            . "\nTüm hatırlatmaların için *hatırlatmalarım* yaz.";
    }

    /** Alacak/borç kaydında yön belirsiz (AI tahmin etmedi) — kullanıcıya sorulur. */
    private function needsDebtSide(array $d): bool
    {
        return DraftGaps::needsDebtSide($d);
    }

    /** Hâlâ ödenmemiş (unpaid / partial) gider; değilse null. */
    private function openExpense(int $id): ?Expense
    {
        return Expense::whereKey($id)->whereIn('payment_status', ['unpaid', 'partial'])->first();
    }

    /** "Kirayı ödedim" → açık borcun ödemesi (yeni gider açılmaz); kısmi ödemede kalan gösterilir. */
    private function buildSettlementSummary(array $d, Expense $open): string
    {
        $amount = (float) $d['amount'];
        $remaining = (float) $open->amount - $amount;
        $who = $open->party ? ' · ' . $open->party->name : '';

        $lines = ['💸 *Borç ödemesi — kontrol et:*'];
        $lines[] = '• Borç: ' . $open->expense_date->format('d.m.Y') . ' — ' . ($open->description ?: 'gider')
            . ' (' . Money::format((float) $open->amount) . ' ₺' . $who . ')';
        $lines[] = '• Ödenen: ' . Money::format($amount) . ' ₺';
        $lines[] = $remaining >= 0.01
            ? '• Kalan borç: ' . Money::format($remaining) . ' ₺'
            : '• Borç kapanır ✅';
        // Kişisiz borç + ödemede isim var ("Tuncay'a kira ödedim") → borç o kişiye bağlanır. Arda'da isim
        // özette görünmediği için kullanıcı eşleşmeyi tanımadı, "yeni" deyip kirayı ikinci kez yazdı.
        $payee = $this->existingParty($d)?->name ?? ($d['party_name'] ?? null);
        if (! $open->party_id && $payee) {
            $lines[] = '• Borç *' . $payee . '* hesabına bağlanır';
        }
        $lines[] = 'Yeni gider açılmaz (bu masraf zaten yazılı).';

        return implode("\n", $lines);
    }

    /**
     * Açık borcu öde: tamamı → gider "ödendi"; kısmi → gider ikiye bölünür (ödenen kısım "ödendi" +
     * kalan "ödenmedi"). Toplam gider değişmez — maliyet iki kez sayılmaz, kalan borç doğru görünür.
     * Gider tablosunda "ödenen tutar" alanı olmadığı için kısmi ödeme bölmeyle tutulur.
     */
    private function commitSettlement(WhatsappPendingExpense $pending, array $d): string
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($pending, $d) {
            $open = Expense::whereKey($d['settles_expense_id'])->whereIn('payment_status', ['unpaid', 'partial'])->lockForUpdate()->first();
            if (! $open) {
                return 'Bu borç bu arada kapanmış görünüyor. Yeni gider olarak yazmak için tekrar gönder.';
            }
            // "Son kaydı sil" bu ödemeyi geri alabilsin: borcun ödeme öncesi hâli (kalan parça ayrıca 'expense' olarak kaydolur).
            if (self::$recording !== null) {
                self::$recording[] = ['settle', $open->id, (string) $open->amount, $open->payment_status, $open->party_id];
            }

            $paid = (float) $d['amount'];
            $remaining = round((float) $open->amount - $paid, 2);

            if (! $open->party_id && (! empty($d['party_id']) || ! empty($d['party_name']))) {
                $open->party_id = $this->resolveParty($d)->id; // kalan kısım (replicate) da aynı kişiye
            }
            if ($remaining < -0.01) {
                return 'Ödenen tutar borçtan fazla. Tutarı kontrol edip tekrar yazar mısın?';
            }

            if ($remaining >= 0.01) {
                $open->replicate()->fill([
                    'amount' => Money::store($remaining),
                    'payment_status' => 'unpaid',
                    'description' => str_ends_with((string) $open->description, '(kalan)')
                        ? $open->description
                        : trim(($open->description ?: 'Gider') . ' (kalan)'),
                ])->save();
                $open->amount = Money::store($paid);
            }
            $open->payment_status = 'paid';
            $open->save();
            $this->touchedPartyId = $open->party_id ?: $this->touchedPartyId;
            $this->committedPartyId = $open->party_id ?: null;

            $pending->update(['status' => 'confirmed']);

            return $remaining >= 0.01
                ? '✅ ' . Money::format($paid) . ' ₺ ödendi. Kalan borç: ' . Money::format($remaining) . ' ₺ (' . ($open->description ?: 'gider') . ')'
                : '✅ Borç kapandı: ' . ($open->description ?: 'gider') . ' — ' . Money::format($paid) . ' ₺';
        });
    }

    /** "Alacak", "Borç kaydı" gibi açıklama yeni bilgi taşımaz — "Alacak kaydı — Alacak" tekrarı olmasın. */
    private function isTrivialDebtText(?string $text): bool
    {
        $t = trim(mb_strtolower(strtr((string) $text, ['İ' => 'i', 'I' => 'ı'])));

        return $t === '' || in_array($t, ['alacak', 'borç', 'borc', 'alacak kaydı', 'borç kaydı', 'alacağım', 'borcum'], true);
    }

    private const LEDGER_TYPES = [
        ExpenseExtractor::KIND_PAYMENT => PartyLedgerEntry::TYPE_PAYMENT,
        ExpenseExtractor::KIND_SALE => PartyLedgerEntry::TYPE_SALE,
        ExpenseExtractor::KIND_COLLECTION => PartyLedgerEntry::TYPE_COLLECTION,
    ];

    /**
     * Alacak/borç kaydı ("Ali'den 40 bin alacağım var") yalnız cari bakiyesini değiştirir: alacak →
     * satış satırı (cari borçlanır), borç → alış satırı. İkisi de maliyete/rapora girmez.
     */
    private function ledgerType(array $d): string
    {
        if ($this->kind($d) === ExpenseExtractor::KIND_DEBT_NOTE) {
            return ($d['debt_side'] ?? null) === 'payable' ? PartyLedgerEntry::TYPE_PURCHASE : PartyLedgerEntry::TYPE_SALE;
        }
        if ($this->kind($d) === ExpenseExtractor::KIND_PURCHASE) {
            return PartyLedgerEntry::TYPE_PURCHASE;
        }

        return self::LEDGER_TYPES[$this->kind($d)];
    }

    /**
     * Teyit metni — tür gider değilse cari hareketi özeti (yön + bakiye öncesi/sonrası).
     */
    private function buildSummary(array $d): string
    {
        $kind = $this->kind($d);
        if ($kind !== ExpenseExtractor::KIND_EXPENSE) {
            return $this->buildLedgerSummary($d, $kind);
        }
        if (! empty($d['settles_expense_id']) && ($open = $this->openExpense((int) $d['settles_expense_id']))) {
            return $this->buildSettlementSummary($d, $open);
        }

        $lines = [];
        $lines[] = '📝 *Gider — kontrol et:*';
        $lines[] = '• Tutar: ' . Money::format((float) ($d['amount'] ?? 0)) . ' ₺'
            . (($d['confidence'] ?? 'high') === 'low' ? '  ⚠️ (tutardan emin değilim, doğrular mısın?)' : '');
        $lines[] = '• Açıklama: ' . ($d['description'] ?: '—');
        $lines[] = '• Tarih: ' . ($d['date'] ?? now()->format('Y-m-d'));

        // Projesiz kurulumda (alacak-verecek) proje satırı ve proje menüsü yok.
        $usesProjects = (bool) config('modules.projects');
        $project = $d['project_id'] ? Project::find($d['project_id']) : null;
        if ($usesProjects) {
            $projectText = $project?->name
                ?? (! empty($d['project_name']) ? $d['project_name'] . ' (yeni)' : '—');
            $lines[] = '• Proje: ' . $projectText;
        }

        $party = $d['party_id'] ? Party::find($d['party_id']) : null;
        $partyText = $party?->name
            ?? (! empty($d['party_name']) ? $d['party_name'] . ' (yeni)' : '—');
        $lines[] = '• Cari: ' . $partyText;

        $category = $d['category_id'] ? ExpenseCategory::find($d['category_id']) : null;
        $categoryText = $category?->name
            ?? (! empty($d['category_name']) ? $d['category_name'] . ' (yeni)' : '—');
        $lines[] = '• Kategori: ' . $categoryText;

        $lines[] = '• Durum: ' . ($this->isPaid($d) ? 'Ödendi' : 'Ödenmedi (borç)')
            . (($d['paid'] ?? null) === null ? '  (değilse *ödendi* / *ödenmedi* yaz)' : '');

        if (! empty($d['due_date'])) {
            $lines[] = '• Ödeme tarihi: ' . \Illuminate\Support\Carbon::parse($d['due_date'])->locale('tr')->translatedFormat('j F Y');
        }

        // Kişisiz borç: soru değil ipucu (her "ödenmedi"de ek soru yormasın); ödemesi yine eşleştirilir.
        if (! $this->isPaid($d) && ! $party && empty($d['party_name'])) {
            $lines[] = "💡 Kime borçlu olduğunu da yazarsan (ör. \"Ahmet Bey'e\") ödemeleri kişi bazında takip ederim.";
        }

        // Sözleşmeli cariye gider: işin maliyeti zaten sözleşmede → çift maliyet riski.
        if ($party && $this->activeContracts($party)->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '⚠️ ' . $party->name . ' ile sözleşmen var. Bu, sözleşmedeki işin parasıysa *iptal* yaz ve '
                . 'ödeme olarak gönder (ör. "… ödedim"), yoksa maliyet iki kez sayılır. Sözleşme dışı ek işse devam et.';
        }

        // Proje boşsa numaralı menü — müteahhit sadece "2" yazsın.
        if ($usesProjects && ! $project && empty($d['project_name'])) {
            $projects = $this->activeProjects();
            if ($projects->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '❓ Hangi proje? Numarayı yaz:';
                foreach ($projects as $i => $p) {
                    $lines[] = '   ' . ($i + 1) . ') ' . $p->name;
                }
                $lines[] = '(bu gider projesizse boş geç, direkt *evet* yaz)';
            }
        }

        if (! empty($d['question'])) {
            $lines[] = '';
            $lines[] = '❓ ' . $d['question'] . ' (cevabını yazıp tekrar gönderebilirsin)';
        }

        return implode("\n", $lines);
    }

    /**
     * Ödeme/satış/tahsilat teyidi. Yön açıkça yazılır ("Sen → Ahmet" / "Ahmet → sana") —
     * "ödedim" ile "ödedi" tek harf; AI yanlış anlarsa müteahhit burada görür.
     */
    private function buildLedgerSummary(array $d, string $kind): string
    {
        $amount = (float) ($d['amount'] ?? 0);
        $party = $this->existingParty($d);
        $name = $party?->name ?? ($d['party_name'] ?? null);

        if ($name === null) {
            return '❓ ' . ($d['question'] ?: match (true) {
                $kind === ExpenseExtractor::KIND_DEBT_NOTE && ($d['debt_side'] ?? null) === 'payable' => 'Kime borcun var? Adını yazar mısın?',
                $kind === ExpenseExtractor::KIND_DEBT_NOTE && ($d['debt_side'] ?? null) === 'unclear' => 'Kiminle olan borç/alacak? Adını yazar mısın?',
                $kind === ExpenseExtractor::KIND_DEBT_NOTE => 'Kimden alacağın var? Adını yazar mısın?',
                default => 'Kime/kimden olduğunu yazar mısın? (cari adı)',
            });
        }

        $label = $party ? $name : $name . ' (yeni cari)';
        if (DraftGaps::needsSaleOrPurchase($d)) {
            return '📦 ' . $label . ': ' . Money::format($amount) . ' ₺' . (! empty($d['description']) ? ', ' . $d['description'] : '')
                . " — *kim kime verdi?*\n• Sen mi ona götürdün (*sattım* → " . $name . ' sana borçlanır), yoksa ondan mı aldın (*aldım* → sen ona borçlanırsın)?';
        }
        $before = $party ? PartyStatement::build($party)['balance'] : 0.0;
        $used = AdvanceOffset::used($d);
        // Ekstre konvansiyonu: bakiye > 0 → cari bize borçlu, < 0 → biz cariye borçluyuz.
        $after = match ($kind) {
            ExpenseExtractor::KIND_PAYMENT => $before + $amount,
            ExpenseExtractor::KIND_SALE => ! empty($d['paid']) ? $before + $used : $before + $amount,
            ExpenseExtractor::KIND_COLLECTION => $before - $amount,
            ExpenseExtractor::KIND_DEBT_NOTE => ($d['debt_side'] ?? null) === 'payable' ? $before - $amount : $before + $amount,
            ExpenseExtractor::KIND_PURCHASE => ! empty($d['paid']) ? $before - $used : $before - $amount,
        };

        $lines = [];
        if ($kind === ExpenseExtractor::KIND_PAYMENT) {
            $lines[] = '💸 *Sen* → ' . $label . ': ' . Money::format($amount) . ' ₺ ödedin';
            if ($this->needsContractChoice($d)) {
                $lines[] = $name . ' ile birden fazla sözleşmen var. Hangisine ödedin?';
                foreach ($this->activeContracts($party) as $i => $c) {
                    $lines[] = '   ' . ($i + 1) . ') ' . $c->title . ' — kalan ' . Money::format($c->remainingPaymentAmount());
                }

                return implode("\n", $lines);
            }
            if ($contract = $this->paymentContract($d)) {
                $remaining = $contract->remainingPaymentAmount();
                $lines[] = '📄 Sözleşme: ' . $contract->title . ' (kalan ' . Money::format($remaining)
                    . ' → ' . Money::format(max(0, $remaining - $amount)) . ')';
                $lines[] = '• Şekli: ' . ContractPayment::PAYMENT_TYPES[$this->contractPaymentType($d)]
                    . (empty($d['payment_type']) ? '  (nakitse *nakit* yaz)' : '');
                if ($amount > $remaining + 0.01) {
                    $lines[] = '⚠️ Ödeme sözleşmenin kalanından fazla.';
                }
            }
            if ($this->needsPaymentChoice($d)) {
                $lines[] = 'Bu cariye açık borcun görünmüyor. *Ne için verdin?*';
                $lines[] = '(ör. "sıva yaptırdım", "iş yaptıracağım, avans", "borç verdim, geri alacağım")';

                return implode("\n", $lines);
            }
            if (! empty($d['advance'])) {
                // Niyet AI'dan okundu — kayıttan önce yorumu göster, yanlışsa kullanıcı düzeltsin.
                $lines[] = ($d['payment_purpose'] ?? null) === 'loan'
                    ? '• Tür: Borç verdin (geri alacaksın)'
                    : '• Tür: Avans (iş sonra yapılacak)';
            }
        } elseif ($kind === ExpenseExtractor::KIND_SALE) {
            $lines[] = '🧾 *Satış* → ' . $label . ': ' . Money::format($amount) . ' ₺'
                . match (true) {
                    $used >= $amount - 0.009 => ' (avanstan düşüldü)',
                    ! empty($d['paid']) => ' (parası alındı)',
                    default => ' (sana borçlandı)',
                };
            if ($used > 0) {
                $rest = $amount - $used;
                $lines[] = '💰 Önceden aldığın ' . Money::format($used) . ' ₺ avanstan düşülür'
                    . ($rest > 0.009 ? ', kalan ' . Money::format($rest) . ' ₺ ' . (! empty($d['paid']) ? 'tahsil edildi' : 'sana borç') : '')
                    . '. Ayrıca yeni para aldıysan *yeni para* yaz.';
            }
            foreach ($d['items'] ?? [] as $item) {
                $lines[] = '   • ' . ($item['description'] ?: '—') . ': ' . Money::format((float) $item['amount']) . ' ₺';
            }
        } elseif ($kind === ExpenseExtractor::KIND_PURCHASE) {
            $lines[] = '🛒 *Alış* ← ' . $label . ': ' . Money::format($amount) . ' ₺'
                . match (true) {
                    $used >= $amount - 0.009 => ' (avanstan düşüldü)',
                    ! empty($d['paid']) => ' (ödendi)',
                    default => ' (ona borçlandın)',
                };
            if ($used > 0) {
                $rest = $amount - $used;
                $lines[] = '💸 Önceden verdiğin ' . Money::format($used) . ' ₺ avanstan düşülür'
                    . ($rest > 0.009 ? ', kalan ' . Money::format($rest) . ' ₺ ' . (! empty($d['paid']) ? 'ödendi' : 'ona borç') : '')
                    . '. Ayrıca yeni ödeme yaptıysan *yeni para* yaz.';
            }
            // Sözleşmeli cariden alış: işin bedeli zaten sözleşmede → çift maliyet riski.
            if ($party && $this->activeContracts($party)->isNotEmpty()) {
                $lines[] = '⚠️ ' . $party->name . ' ile sözleşmen var. Bu, sözleşmedeki işin parasıysa *iptal* yaz ve '
                    . 'ödeme olarak gönder (ör. "… ödedim"), yoksa iki kez sayılır. Sözleşme dışı ek işse devam et.';
            }
        } elseif ($kind === ExpenseExtractor::KIND_DEBT_NOTE && $this->needsDebtSide($d)) {
            return '📒 ' . $label . ': ' . Money::format($amount) . " ₺ — *kim kime borçlu?*\n"
                . '• ' . $name . ' mi sana borçlu, yoksa sen mi ona borçlusun?';
        } elseif ($kind === ExpenseExtractor::KIND_DEBT_NOTE) {
            $lines[] = ($d['debt_side'] ?? null) === 'payable'
                ? '📒 *Borç kaydı*: ' . $label . ' → sen ona ' . Money::format($amount) . ' ₺ borçlusun'
                : '📒 *Alacak kaydı*: ' . $label . ' → sana ' . Money::format($amount) . ' ₺ borçlu';
        } elseif ($kind === ExpenseExtractor::KIND_COLLECTION && ($d['payment_purpose'] ?? null) === 'advance') {
            // Müşteri avansı: para geldi, mal/iş borcu bizde — ekstrede müşteri alacaklı görünür.
            $lines[] = '💰 *Peşin para (avans)* ← ' . $label . ': ' . Money::format($amount) . ' ₺ aldın, mal/iş sonra verilecek';
        } else {
            $lines[] = '💰 ' . $label . ' → *sana*: ' . Money::format($amount) . ' ₺ ödedi';
        }

        if (! empty($d['description']) && ($kind !== ExpenseExtractor::KIND_SALE || empty($d['items']))
            && ! ($kind === ExpenseExtractor::KIND_DEBT_NOTE && $this->isTrivialDebtText($d['description']))) {
            $lines[] = '• Açıklama: ' . $d['description'];
        }
        $lines[] = '• Tarih: ' . ($d['date'] ?? now()->format('Y-m-d'));
        if (! empty($d['due_date']) && in_array($kind, [ExpenseExtractor::KIND_SALE, ExpenseExtractor::KIND_DEBT_NOTE, ExpenseExtractor::KIND_PURCHASE], true)) {
            $lines[] = '• Ödeme tarihi: ' . \Illuminate\Support\Carbon::parse($d['due_date'])->locale('tr')->translatedFormat('j F Y');
        }
        if (! empty($d['payment_type']) && ! $this->paymentContract($d)) {
            $lines[] = '• Şekli: ' . (self::PAYMENT_TYPE_LABELS[$d['payment_type']] ?? $d['payment_type']);
        }
        $project = ! empty($d['project_id']) ? Project::find($d['project_id']) : null;
        $projectText = $project?->name ?? (! empty($d['project_name']) ? $d['project_name'] . ' (yeni)' : null);
        if ($projectText) {
            $lines[] = '• Proje: ' . $projectText;
        }
        $lines[] = '• Bakiye: ' . $this->balancePhrase($before) . ' → ' . $this->balancePhrase($after);
        if (! empty($d['party_check'])) {
            $lines[] = '❓ "' . $d['party_said'] . '" yazdın, *' . $name . '* ile eşleştirdim. *Aynı kişi mi?* Değilse *yeni* yaz.';
        }

        return implode("\n", $lines);
    }

    private const PAYMENT_TYPE_LABELS = [
        'cash' => 'Nakit',
        'bank_transfer' => 'Havale',
        'eft' => 'EFT',
        'other' => 'Diğer',
    ];

    /** "Ahmet'e ne kadar borcum var?" → kayıt açmadan cevap. */
    private function balanceAnswer(array $d): string
    {
        $party = $this->existingParty($d);
        if (! $party) {
            $name = $d['party_name'] ?? null;

            return $name
                ? '"' . $name . '" adında bir cari bulamadım.'
                : 'Hangi carinin bakiyesini soruyorsun?';
        }

        $cari = $this->balanceLine($party->name, PartyStatement::build($party)['balance']);
        $contracts = config('modules.contracts') ? ContractStatus::lines($party) : [];

        if ($contracts === []) {
            return '📊 ' . $cari;
        }

        // Sözleşmeli cari: cari ekstresi sözleşme ödeme/teslimatını içermez — ikisini ayrı göster.
        return '📊 *' . $party->name . "*\n"
            . implode("\n", $contracts)
            . "\n\n💼 Cari hesap (sözleşme dışı): " . lcfirst(Str::after($cari, $party->name . ': '));
    }

    /**
     * "Ali'nin ekstresini at" → kısa özet + PDF eki. PDF linki imzalı ve 10 dk geçerli
     * (Twilio indirebilsin, başkası tahmin edemesin / sonra açamasın).
     *
     * @return array{0:string,1:string}|string  [metin, medya URL] ya da sadece metin
     */
    private function statementAnswer(array $d): array|string
    {
        $party = $this->existingParty($d);
        if (! $party) {
            $name = $d['party_name'] ?? null;

            return $name
                ? '"' . $name . '" adında bir cari bulamadım.'
                : 'Hangi carinin ekstresini istiyorsun?';
        }

        $filters = array_filter([
            'date_from' => $d['date_from'] ?? null,
            'date_to' => $d['date_to'] ?? null,
            'project_id' => $d['project_id'] ?? null,
        ]);
        $statement = PartyStatement::build($party, $filters);

        $lines = ['📄 ' . $party->name . ' — Cari Ekstresi'];
        if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
            $lines[] = 'Dönem: ' . ($filters['date_from'] ?? '…') . ' – ' . ($filters['date_to'] ?? '…');
        }
        if (! empty($filters['project_id'])) {
            $lines[] = 'Proje: ' . (Project::find($filters['project_id'])?->name ?? '—');
            $lines[] = 'Bu projedeki fark: ' . Money::format(abs($statement['balance'])) . ' ₺';
        } else {
            $lines[] = $this->balanceLine($party->name, $statement['balance']);
        }

        $url = URL::temporarySignedRoute('whatsapp.statement.pdf', now()->addMinutes(10), [
            'party' => $party->id,
            'file' => 'Ekstre-' . (Str::slug($party->name) ?: $party->id) . '.pdf',
        ] + $filters + array_filter(['firm' => Tenancy::current()?->id])); // tek panel: Twilio oturumsuz indirir, firma imzalı linkte

        return [implode("\n", $lines), $url];
    }

    /**
     * "Toplam alacağım ne kadar?" → tüm carilerin ekstre bakiyesi. Alacak ve borç AYRI toplanır,
     * netleştirilmez (Ali'den alacak ile Kuşak Beton'a borç farklı hesaplar).
     */
    private function totalsAnswer(array $d): string
    {
        $filters = array_filter(['project_id' => $d['project_id'] ?? null]);
        $side = $d['totals_side'] ?? 'both';
        $project = ! empty($filters['project_id']) ? Project::find($filters['project_id']) : null;

        $sections = [];
        if ($side !== 'payable') {
            $sections[] = $this->totalsSection('Toplam alacağın', PartyBalances::receivables($filters));
        }
        if ($side !== 'receivable') {
            $sections[] = $this->totalsSection('Toplam borcun', PartyBalances::payables($filters), $this->unpaidExpensesWithoutParty($filters));
        }

        $head = '📊' . ($project ? ' ' . $project->name . ' projesi — ' : ' ');

        return $head . implode("\n\n", $sections)
            . ($project ? "\n\n(Sadece bu projeye etiketli hareketler.)" : '');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Expense>|null  $unpaid  carisiz ödenmemiş giderler (yalnız borç tarafı)
     */
    private function totalsSection(string $title, \Illuminate\Support\Collection $rows, ?\Illuminate\Support\Collection $unpaid = null): string
    {
        $unpaid ??= collect();
        if ($rows->isEmpty() && $unpaid->isEmpty()) {
            return $title . ': 0 ₺';
        }

        $total = $rows->sum('balance') + (float) $unpaid->sum('amount');
        $lines = [$title . ': ' . Money::format($total) . ' ₺' . ($unpaid->isEmpty() ? ' (' . $rows->count() . ' cari)' : '')];
        foreach ($rows->take(5) as $row) {
            $lines[] = '• ' . $row['party']->name . ': ' . Money::format($row['balance']);
        }
        if ($rows->count() > 5) {
            $lines[] = '… ve ' . ($rows->count() - 5) . ' cari daha';
        }
        if ($unpaid->isNotEmpty()) {
            // Carisiz giderler cari ekstresine girmez; ödenmemişse yine de borçtur (Arda: kira "borç" diye
            // girildi, "toplam borcun 0" deniyordu). Ayrı satır — cari bakiyeleriyle çift sayılmaz.
            $names = $unpaid->take(3)->map(fn (Expense $e) => $e->description ?: 'gider')->implode(', ');
            $lines[] = '• Ödenmemiş giderler (cari yok): ' . Money::format((float) $unpaid->sum('amount'))
                . ' — ' . $names . ($unpaid->count() > 3 ? ' …' : '');
        }

        return implode("\n", $lines);
    }

    /** @return \Illuminate\Support\Collection<int, Expense> */
    private function unpaidExpensesWithoutParty(array $filters): \Illuminate\Support\Collection
    {
        if (! config('modules.expenses')) {
            return collect();
        }

        return Expense::query()
            ->whereNull('party_id')
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->when($filters['project_id'] ?? null, fn ($q, $id) => $q->where('project_id', $id))
            ->orderByDesc('amount')
            ->get();
    }

    /** "Hangi carim var?" — bakiyesi olanlar önce, en fazla 15; hiç cari yoksa nasıl açılacağını söyle. */
    private function partyListAnswer(): string
    {
        $count = Party::count();
        if ($count === 0) {
            return "Henüz carin yok. Cari, bir işlemle birlikte adını yazınca kendiliğinden açılır. Örnek:\n"
                . "• \"Ali'den 40 bin alacağım var\"\n• \"Ali 10 bin ödedi\"";
        }

        $rows = PartyBalances::all();
        $lines = ['📒 *Carilerin* (' . $count . ')'];
        foreach ($rows->take(15) as $row) {
            $lines[] = '• ' . $this->balanceLine($row['party']->name, $row['balance']);
        }
        if ($rows->count() > 15) {
            $lines[] = '… ve bakiyesi olan ' . ($rows->count() - 15) . ' cari daha';
        }
        // Hesabı kapalı olanlar da adıyla (Arda: "1 carinin hesabı kapalı" yazısında kim olduğu görünmüyordu).
        $closedNames = Party::whereNotIn('id', $rows->pluck('party.id'))->orderBy('name')->pluck('name');
        if ($closedNames->isNotEmpty()) {
            $room = max(5, 15 - min(15, $rows->count()));
            $lines[] = '• Hesabı kapalı: ' . $closedNames->take($room)->implode(', ')
                . ($closedNames->count() > $room ? ' … (+' . ($closedNames->count() - $room) . ')' : '');
        }
        $lines[] = "\nAyrıntı için: \"Ali'nin ekstresini at\"";

        return implode("\n", $lines);
    }

    private function balanceLine(string $name, float $balance): string
    {
        return PartyBalances::line($name, $balance);
    }

    private function balancePhrase(float $balance): string
    {
        if (abs($balance) < 0.01) {
            return '0';
        }

        return $balance < 0
            ? 'borcun ' . Money::format(abs($balance))
            : 'alacağın ' . Money::format($balance);
    }

    /** Boş mesaja cevap — yalnız bu kurulumda açık işlemleri örnekler (mimar'a "gider" denmesin). */
    /**
     * Rehber ("nasıl" / boş mesaj / ilk mesaj) — yalnız bu kurulumda açık işlemleri örnekler
     * (mimar'a "gider" denmesin). Kaydet / sor diye ikiye ayrılır.
     */
    /**
     * İlk mesajın karşılaması: kısa, firmanın işine göre 3 kayıt + 1 soru + 1 hatırlatma örneği.
     * Uzun liste "nasıl" ile (guideText).
     */
    private function welcomeText(): string
    {
        $record = [
            ExpenseExtractor::KIND_TRADE_SALE => '• "Bugün 10 bin nakit 15 bin kart" (günlük ciro)',
            ExpenseExtractor::KIND_TRADE_PURCHASE => '• "Ak Un\'dan 20 çuval un aldım" (alış, stoğa girer)',
            ExpenseExtractor::KIND_PURCHASE => '• "Ahmet\'ten 10 bin malzeme aldım" (alış)',
            ExpenseExtractor::KIND_SALE => '• "Ahmet Bey\'e 20 bine iş yaptım" (satış)',
            ExpenseExtractor::KIND_EXPENSE => '• "5 bin mazot aldım" (gider)',
            ExpenseExtractor::KIND_COLLECTION => '• "Ahmet Bey 50 bin ödedi" (gelen para)',
            ExpenseExtractor::KIND_DEBT_NOTE => '• "Ali\'den 40 bin alacağım var" (alacak)',
        ];
        // Müteahhit çoğunlukla alır: satış örneği alış/giderin arkasına.
        if (config('app.profile') === 'muteahhit') {
            $sale = $record[ExpenseExtractor::KIND_SALE];
            unset($record[ExpenseExtractor::KIND_SALE]);
            $record[ExpenseExtractor::KIND_SALE] = $sale;
        }
        $kinds = ExpenseExtractor::aiKinds();
        $lines = array_slice(array_values(array_intersect_key($record, array_flip($kinds))), 0, 3);
        $lines[] = in_array(ExpenseExtractor::KIND_STOCK_QUERY, $kinds, true)
            ? '• "Un ne kadar kaldı?" (soru)'
            : '• "Ahmet\'in borcu ne?" (soru)';
        $lines[] = '• "Cuma Ali\'den parayı almayı hatırlat"';

        return implode("\n", [
            '👋 Hoş geldin! Ben *Hesap Asistanım*. Bana normal konuşur gibi yaz, kaydı ben tutarım; kaydetmeden önce sana sorarım.',
            '',
            ...$lines,
            '',
            'Daha fazla örnek için *nasıl* yaz.',
        ]);
    }

    private function guideText(): string
    {
        $record = [
            ExpenseExtractor::KIND_EXPENSE => "• Harcama: \"5 bin yakıt aldım\", \"Kira 20 bin ödendi\"",
            ExpenseExtractor::KIND_SALE => "• Satış: \"Ahmet Bey'e 80 bine iş yaptım\"",
            ExpenseExtractor::KIND_COLLECTION => "• Gelen para: \"Ahmet Bey 50 bin ödedi\"",
            ExpenseExtractor::KIND_PAYMENT => "• Verdiğin para: \"Ahmet ustaya 10 bin ödedim\"",
            ExpenseExtractor::KIND_PURCHASE => "• Alış: \"Ahmet'ten 10 bin mal aldım\" (peşinse \"nakit ödedim\" ekle)",
            ExpenseExtractor::KIND_TRADE_SALE => "• Satış: \"30 ekmek sattım\", \"Lokantaya 50 ekmek veresiye\", akşam ciro: \"bugün 10 bin nakit 15 bin kart\"",
            ExpenseExtractor::KIND_TRADE_PURCHASE => "• Mal alışı: \"Ak Un'dan 20 çuval un aldım\", \"unlar geldi 35 çuval\" (peşinse \"nakit ödedim\" ekle)",
            ExpenseExtractor::KIND_PRODUCT_ADD => "• Ürün ekle: \"un, maya, tuz kullanıyorum\"",
            ExpenseExtractor::KIND_STOCK_COUNT => "• Sayım: \"un 9 çuval kaldı\"",
            ExpenseExtractor::KIND_STOCK_SETTING => "• Uyarı / fiyat: \"unu günde 3 kullanıyorum, 6 kala haber ver\", \"unu çuvalı 850'den alıyorum\"",
            ExpenseExtractor::KIND_DEBT_NOTE => "• Alacak / borç: \"Ali'den 40 bin alacağım var\""
                . (config('modules.cari_supplier') ? ", \"Mehmet'e 15 bin borcum var\"" : ''),
        ];
        $ask = [
            ExpenseExtractor::KIND_BALANCE_QUERY => "• \"Ahmet'in borcu ne?\"",
            ExpenseExtractor::KIND_TOTALS_QUERY => "• \"Toplam alacağım ne kadar?\"",
            ExpenseExtractor::KIND_EXPENSE_SUMMARY => "• \"Bu ay ne kadar harcadım?\"",
            ExpenseExtractor::KIND_PARTY_LIST => "• \"Hangi carilerim var?\"",
            ExpenseExtractor::KIND_STATEMENT => "• \"Ahmet'in ekstresini at\" (PDF)",
            ExpenseExtractor::KIND_STOCK_QUERY => "• \"Un ne kadar kaldı?\", \"stokta ne var?\"",
            ExpenseExtractor::KIND_MONTH_RESULT => "• \"Bu ay ne kazandım?\"",
        ];
        $kinds = ExpenseExtractor::aiKinds();
        $pick = fn (array $examples) => array_values(array_intersect_key($examples, array_flip($kinds)));

        $lines = ['Bana normal konuşur gibi yazman yeterli. Örnekler:'];
        $lines[] = '';
        $lines[] = '*Kaydetmek için:*';
        array_push($lines, ...$pick($record));
        $lines[] = '';
        $lines[] = '*Sormak için:*';
        array_push($lines, ...$pick($ask));
        if (config('modules.checks')) {
            $lines[] = '• "çekler" (vadesi yaklaşan çeklerin)';
        }
        $lines[] = '';
        $lines[] = '*Hatırlatmak için:*';
        $lines[] = '• "20 Ekim\'de düğün çekimim var, 2 gün önce hatırlat"';
        $lines[] = '• "Her ayın 10\'unda kredi kartı ödemem var"';
        $lines[] = '• "hatırlatmalarım" (liste), "iptal 2" (sil) — hatırlatmalar firmanın panel takviminde de görünür';
        $lines[] = '• "Yarın ne var?", "Bu hafta neler var?", "20 Ekim boş mu?"';
        $lines[] = '';
        if (in_array(ExpenseExtractor::KIND_EXPENSE, $kinds, true)) {
            $lines[] = '📷 Fiş / dekont fotoğrafı da atabilirsin.';
        }
        $lines[] = 'Bu listeyi tekrar görmek için *nasıl* yaz.';

        return implode("\n", $lines);
    }

    /** Sadece tutar yazıldıysa ("20000", "20.000", "20.000 tl", "1.250,50 ₺") → sayı; değilse null. */
    private function typedAmount(string $body): ?float
    {
        $text = trim(preg_replace('/\s*(tl|₺|lira)$/iu', '', trim($body)) ?? '');
        if (! preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$|^\d+(,\d{1,2})?$/', $text)) {
            return null;
        }
        $amount = Money::parse($text);

        return $amount > 0 ? $amount : null;
    }

    /** Yeni eklenen numaranın ilk mesajı mı? (karşılama rehberi — bkz. WhatsappMessage::shouldWelcome) */
    private function isFirstContact(string $phone): bool
    {
        return WhatsappMessage::shouldWelcome(Phone::normalize($phone));
    }

    /** Konuşma kaydı — yazılamazsa asıl akışı asla durdurmaz. */
    private function logMessage(string $phone, string $direction, ?string $body, bool $hasMedia = false): ?WhatsappMessage
    {
        try {
            return WhatsappMessage::create([
                'phone' => Phone::normalize($phone),
                'direction' => $direction,
                'body' => $body === null ? null : mb_substr($body, 0, 4000),
                'has_media' => $hasMedia,
            ]);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp konuşma kaydı yazılamadı', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Gider ödendi mi? Mesaj açıkça söylediyse o; söylemediyse: carili → ödenmedi (veresiye, borç
     * cari ekstresinde, "ödedim" ile kapanır), carisiz → ödendi (kime borçlu olunduğu belli değil,
     * WhatsApp'tan kapatılamaz). Kullanıcı kararı.
     */
    private function isPaid(array $d): bool
    {
        if (($d['paid'] ?? null) !== null) {
            return (bool) $d['paid'];
        }

        return ! ($this->existingParty($d) || ! empty($d['party_name']));
    }

    private function kind(array $d): string
    {
        return $d['kind'] ?? ExpenseExtractor::KIND_EXPENSE;
    }

    private function existingParty(array $d): ?Party
    {
        return DraftGaps::party($d);
    }

    private function resolveParty(array $d): Party
    {
        return $this->existingParty($d) ?? Party::firstOrCreate(['name' => $d['party_name']]);
    }

    private function missingParty(array $d): bool
    {
        return DraftGaps::missingParty($d);
    }

    /**
     * Carinin aktif ALIM sözleşmeleri (satış sözleşmeleri global scope ile hariç), sabit sıra.
     *
     * @return \Illuminate\Support\Collection<int, Contract>
     */
    private function activeContracts(?Party $party): \Illuminate\Support\Collection
    {
        return DraftGaps::activeContracts($party);
    }

    /** Ödemenin yazılacağı sözleşme: seçildiyse o, tek aktif sözleşme varsa o, yoksa null. */
    private function paymentContract(array $d): ?Contract
    {
        if ($this->kind($d) !== ExpenseExtractor::KIND_PAYMENT) {
            return null;
        }
        $contracts = $this->activeContracts($this->existingParty($d));
        if (! empty($d['contract_id'])) {
            return $contracts->firstWhere('id', $d['contract_id']);
        }

        return $contracts->count() === 1 ? $contracts->first() : null;
    }

    private function needsContractChoice(array $d): bool
    {
        return DraftGaps::needsContractChoice($d);
    }

    /**
     * Ödeme ama cariye açık borcumuz yok (yeni cari dahil) → bu para yeni bir masraf mı,
     * avans mı? Sormadan yazarsak ya maliyet kaçar ya da çift sayılır.
     */
    private function needsPaymentChoice(array $d): bool
    {
        return DraftGaps::needsPaymentChoice($d);
    }

    /**
     * WhatsApp'ta "yazıyor…" + mavi tik (Twilio Typing Indicator, Public Beta). 25 sn ya da cevap
     * gidene kadar görünür. Fotoğraf okuma 10-15 sn sürebiliyor; müteahhit "gitmedi mi" diye
     * tekrar atmasın. Başarısızsa sessizce geçilir — asıl akışı asla durdurmaz.
     */
    private function sendTypingIndicator(string $messageSid): void
    {
        $sid = (string) config('services.twilio.sid');
        $token = (string) config('services.twilio.token');
        if ($messageSid === '' || $sid === '' || $token === '') {
            return;
        }

        try {
            $response = Http::withBasicAuth($sid, $token)
                ->asJson()
                ->timeout(2)
                ->post('https://messaging.twilio.com/v3/Indicators/Typing.json', [
                    'messageId' => $messageSid,
                    'channel' => 'WHATSAPP', // büyük harf şart: 'whatsapp' → 400 (doküman metni yanıltıcı)
                ]);

            if ($response->failed()) {
                Log::warning('WhatsApp yazıyor göstergesi gönderilemedi', ['status' => $response->status(), 'body' => $response->body()]);
            }
        } catch (\Throwable $e) {
            Log::warning('WhatsApp yazıyor göstergesi hatası', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * Twilio medyasını indirip base64'e çevirir (medya erişimi basic auth ister).
     *
     * @return array{media_type:string,data:string}|null
     */
    private function downloadMedia(Request $request): ?array
    {
        $url = (string) $request->input('MediaUrl0', '');
        $type = (string) $request->input('MediaContentType0', 'image/jpeg');
        if ($url === '' || ! Str::startsWith($type, 'image/')) {
            return null;
        }

        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');

        $resp = Http::withBasicAuth((string) $sid, (string) $token)->timeout(30)->get($url);
        if ($resp->failed()) {
            Log::warning('Twilio medya indirilemedi', ['status' => $resp->status()]);

            return null;
        }

        return [
            'media_type' => $type,
            'data' => base64_encode($resp->body()),
        ];
    }

    /**
     * Menüde gösterilecek/eşleştirilecek projeler — sabit sıra (id) ki "2" hep aynı projeyi seçsin.
     */
    private function activeProjects(): \Illuminate\Support\Collection
    {
        return Project::whereNotIn('status', ['cancelled'])
            ->orderBy('id')
            ->take(30)
            ->get(['id', 'name'])
            ->values();
    }

    private function isConfirm(string $body): bool
    {
        return in_array($this->word($body), ChatAssistant::ASSENT_WORDS, true);
    }

    private function isCancel(string $body): bool
    {
        return in_array($this->word($body), ['iptal', 'hayır', 'hayir', 'vazgeç', 'vazgec', 'h'], true);
    }

    /**
     * Tek kelimelik cevabı karşılaştırmaya hazırla. Türkçe büyük harf: mb_strtolower('İ') "i̇"
     * (i + birleşik nokta) üretir → "İptal" eşleşmiyordu. Önce İ→i, I→ı; uçtaki noktalama/boşluk atılır.
     */
    private function word(string $body): string
    {
        $lower = mb_strtolower(strtr($body, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');

        return preg_replace('/^[\s\p{P}]+|[\s\p{P}]+$/u', '', $lower) ?? $lower;
    }

    /**
     * @param  string|array{0:string,1:string}  $message  metin ya da [metin, medya URL] (PDF eki)
     */
    private function isAllowedPhone(Request $request, string $from): bool
    {
        // Hub'dan imzalı geldiyse telefon hub'da zaten kontrol edildi (telefon → firma tablosu).
        if ($request->attributes->get('via_hub')) {
            return true;
        }

        $mine = Phone::normalize($from);
        if ($mine === '') {
            return false;
        }

        foreach (config('services.twilio.allowed_phones', []) as $allowed) {
            if (Phone::normalize($allowed) === $mine) {
                return true;
            }
        }

        return false;
    }

    private function twiml(string|array $message)
    {
        return $this->twimlMany([$message]);
    }

    /**
     * Birden fazla WhatsApp mesajı (ör. cevap + ilk mesaj rehberi). Her biri ayrı balon.
     *
     * @param  list<string|array{0:string,1:string}>  $messages
     */
    private function twimlMany(array $messages)
    {
        $this->replies = $messages;
        $esc = fn (string $v) => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $xml = '';
        foreach ($messages as $message) {
            [$text, $media] = is_array($message) ? $message : [$message, null];
            $xml .= '<Message>' . ($media === null
                ? $esc($text)
                : '<Body>' . $esc($text) . '</Body><Media>' . $esc($media) . '</Media>') . '</Message>';
        }

        return response(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response>{$xml}</Response>",
            200,
            ['Content-Type' => 'text/xml']
        );
    }
}
