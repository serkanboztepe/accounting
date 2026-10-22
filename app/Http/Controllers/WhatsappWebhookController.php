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
use App\Models\PurchaseReturn;
use App\Models\Reminder;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\AdvanceOffset;
use App\Services\Whatsapp\AgreementDeliveryDraft;
use App\Services\Whatsapp\AgreementDraft;
use App\Services\Whatsapp\AgreementQuery;
use App\Services\Whatsapp\PartyInfoDraft;
use App\Services\Whatsapp\BundleDraft;
use App\Services\Whatsapp\ChatAssistant;
use App\Services\Whatsapp\CheckSplit;
use App\Services\Whatsapp\DraftFollowUp;
use App\Services\Whatsapp\DraftGaps;
use App\Services\Whatsapp\ExpenseContext;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\TradeDraft;
use App\Services\Whatsapp\IssuedChecks;
use App\Services\Whatsapp\Understanding;
use App\Services\Whatsapp\StockChoice;
use App\Services\Whatsapp\WorkChoice;
use App\Services\Whatsapp\HubRouter;
use App\Services\Whatsapp\PartyNameCheck;
use App\Services\Whatsapp\DeferTurn;
use App\Services\Whatsapp\DraftWarnings;
use App\Services\Whatsapp\EmployeeDraft;
use App\Services\Whatsapp\MemoryDraft;
use App\Services\Whatsapp\NaturalVoice;
use App\Services\Whatsapp\RecurringDraft;
use App\Services\Whatsapp\ReturnDraft;
use App\Services\Whatsapp\WhatsappSender;
use App\Services\Whatsapp\ProjectChoice;
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
use Illuminate\Support\Facades\DB;
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
    private const PAID_WORDS = ['ödedim', 'odedim', 'ödendi', 'odendi', 'ödedim evet', 'nakit', 'kartla', 'kart', 'peşin', 'pesin', 'nakit ödedim', 'kartla ödedim'];

    private const UNPAID_WORDS = ['ödemedim', 'odemedim', 'ödenmedi', 'odenmedi', 'veresiye', 'veresiye aldım', 'borç', 'borca', 'sonra ödeyeceğim', 'ödemedim veresiye'];

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

        // İşlem geçmişi: bu mesajla yapılan her iş "WhatsApp · ad (numara)" kanalıyla yazılır.
        \App\Models\ActivityLog::via('whatsapp', self::activityActor($phone));

        // Peş peşe gelen mesajlar ("kira 20 bin" + hemen "ödeme yapıldı") aynı anda işlenince ikincisi
        // taslağı göremiyor, ayrı ayrı cevaplanıyordu → aynı telefonun mesajları sıraya girer.
        // Kilit süresi en uzun işlemden (AI 60 sn) uzun olmalı; yoksa süre dolunca ikinci mesaj araya girer (review C).
        // Arka plana bırakılan tur (DeferTurn) süresiz çalışır (5 AI turu × 60 sn + kontroller) — kilit onu kapsasın (review 4).
        $lock = Cache::lock(Tenancy::key('wa-msg:' . Phone::normalize($phone)), 360);
        try {
            $lock->block(self::$lockWait);
        } catch (LockTimeoutException) {
            // Kilitsiz devam ETME: iki mesaj aynı taslağa aynı anda yazıp çift/yarım kayıt üretebilir (review C).
            // Twilio 15 sn bekliyor; daha uzun bekleyemeyiz → kişiye tekrar göndermesini söyle (son 7 günde en uzun 12 sn).
            // Aynı mesajın tekrarı (Twilio, ilk istek hâlâ işlenirken — firma 7 22.10 11:16: tek sözleşme fotoğrafı, okuma uzun sürdü,
            // tekrar "yeni fotoğraf" sanılıp sıraya alındı: "Fotoğrafı aldım…" + "Sırada 1 fotoğraf var" boşuna gitti) → sessizce atla.
            $retrySid = (string) $request->input('MessageSid', '');
            if ($retrySid !== '' && ! $request->attributes->get('queued') && Cache::has(Tenancy::key('wa-sid:' . $retrySid))) {
                Log::info('WhatsApp aynı mesaj işlenirken tekrar geldi — atlandı', ['sid' => $retrySid]);

                return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200, ['Content-Type' => 'text/xml']);
            }
            Log::warning('WhatsApp kilit alınamadı — mesaj işlenmedi', ['phone' => Phone::normalize($phone)]);

            // Fotoğraf sıraya alınır (firma 7, 21.10: 3 çek sayfası birden atıldı → ikisine "tekrar gönder" dendi): önceki iş bitince
            // aynı istek arka planda işlenir, cevap ayrı mesaj gider. Yazı mesajı eskisi gibi (tekrar gönder) — sırası önemli olabilir.
            if ($numMedia > 0 && ! $request->attributes->get('queued')) {
                $this->enqueuePhoto($request, $phone);
                $this->scheduleDrain($phone);
                // Birlikte gönderilen sözleşme sayfası öncekiyle birleşebilir — "sırayla" demek yanıltıyordu (22.10 Kamuran).
                $busy = '📷 Bunu da aldım, bakıyorum.';
                $this->logMessage($phone, 'out', $busy, false); // gelen mesaj sırası gelince kaydedilir

                return $this->twiml($busy);
            }
            $this->logMessage($phone, 'in', $body, $numMedia > 0);
            $busy = '⏳ Bir önceki mesajını hâlâ işliyorum. Bu mesajı birkaç saniye sonra tekrar gönderir misin?';
            $this->logMessage($phone, 'out', $busy, false);

            return $this->twiml($busy);
        }

        // Aynı Twilio mesajı ikinci kez gelirse (ağ tekrarı) yeniden işlenmez — çift kayıt olmasın (review G).
        $sid = (string) $request->input('MessageSid', '');
        if ($sid !== '' && ! Cache::add(Tenancy::key('wa-sid:' . $sid), true, now()->addDay())) {
            $lock->release();
            Log::info('WhatsApp aynı mesaj tekrar geldi — atlandı', ['sid' => $sid]);

            return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200, ['Content-Type' => 'text/xml']);
        }

        // Twilio ~15 sn bekler: AI turu isteğin başından en geç 10 sn içinde bitmezse ertelenir ("bakıyorum").
        // İsteğin geldiği an (testte istek oluşturulduğu an); yoksa şimdi.
        // Sıraya alınmış fotoğraf zaten arka planda: süre sınırı yok (ertelenen tur iç içe çalışmaz).
        ChatAssistant::$deadline = $request->attributes->get('queued') ? null
            : (float) ($request->server('REQUEST_TIME_FLOAT') ?: microtime(true)) + self::SYNC_SECONDS;
        $deferred = false;
        try {
            $firstContact = $this->firstContact = $this->isFirstContact($phone);
            // İkinci okuma aynı gelen mesajdır — konuşma kaydına tekrar yazılmaz.
            $incoming = $request->attributes->get('reread') ? null : $this->logMessage($phone, 'in', $body, $numMedia > 0);
            // "Müşteriye sor" (Faz 3): ona sorulmuş soru varsa bu mesaj cevabıdır → derse yazılır (akış aynen sürer).
            \App\Models\AssistantLesson::captureAnswer($phone, $body);
            \App\Models\RecordReview::captureAnswer($phone, $body);

            $response = $this->respond($request, $extractor, $phone, $body, $numMedia);
            $this->tagReviewDrafts($phone);

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
            // Sırada fotoğraf varken açık kartın altına (21.10: kullanıcı diğer fotoğrafları bekledi, sıranın cevabını beklediğini anlamadı).
            if ($this->replies !== [] && ($waiting = count($this->photoQueue($phone))) > 0
                && WhatsappPendingExpense::where('phone', $phone)->where('status', 'awaiting_confirmation')->exists()) {
                $last = array_key_last($this->replies);
                $line = "\n📷 Sırada {$waiting} fotoğraf var — bunu bitirince geçeceğim.";
                $this->replies[$last] = is_array($this->replies[$last]) ? [$this->replies[$last][0] . $line, $this->replies[$last][1] ?? null]
                    : $this->replies[$last] . $line;
                $response = $this->twimlMany($this->replies);
            }
            foreach ($this->replies as $reply) {
                // [metin, PDF linki] → ek olduğu kayda geçsin (analiz "hareketler gösterilmedi" sanmasın).
                $this->logMessage($phone, 'out', is_array($reply) ? $reply[0] : $reply, is_array($reply));
            }

            if ($this->deferredTurn !== null) {
                // Kilit arka plandaki tur bitene kadar tutulur — sıradaki mesaj araya girmesin.
                $this->runAfterResponse($phone, $incoming, $lock->owner());
                $deferred = true;
            }
            // Sırada fotoğraf varsa (meşgulken gelmişti) açık kart kapanınca işlenir.
            if (! $request->attributes->get('queued') && $this->photoQueue($phone) !== []) {
                $this->scheduleDrain($phone);
            }
            // Twilio cevabı ~15 sn bekler; geç kalan cevap (fotoğraf okuma + kontroller) düşüyordu (21.10 10:54: soru kartı hiç gitmedi) →
            // süre aşıldıysa cevap WhatsApp API ile gönderilir, Twilio'ya boş cevap.
            if (! $deferred && ! $request->attributes->get('queued') && $this->replies !== []
                && microtime(true) - (float) ($request->server('REQUEST_TIME_FLOAT') ?: microtime(true)) > self::LATE_SECONDS) {
                foreach ($this->replies as $reply) {
                    $this->deliver($phone, $reply, log: false);
                }
                Log::info('WhatsApp cevabı geç kaldı — API ile gönderildi', ['phone' => Phone::normalize($phone)]);

                return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200, ['Content-Type' => 'text/xml']);
            }

            return $response;
        } finally {
            ChatAssistant::$deadline = null;
            if (! $deferred) {
                $lock->release();
            }
        }
    }

    private static function isStaff(array $d, ?Party $party): bool
    {
        return ! empty($d['staff_hint']) || (bool) $party?->is_employee;
    }

    /** Özetin alt satırından ("Kaydedeyim mi?…", "Cevabını yaz…") hemen önce ekle; alt satır yoksa sona. */
    /** Kartta "Doğru mu?" diye soran uyarı var mı (olağandışı tutar / çift kayıt / birim fiyat) — "Doğru" cevabı o zaman onaydır. */
    private function asksDoğru(WhatsappPendingExpense $pending): bool
    {
        return $pending->status === 'awaiting_confirmation'
            && (str_contains(mb_strtolower((string) $pending->summary, 'UTF-8'), 'doğru mu') || DraftWarnings::for((array) $pending->extracted) !== []);
    }

    private static function insertBeforeFooter(string $out, string $lines): string
    {
        if (preg_match('/\n\n(Kaydedeyim mi\?|Cevabını yaz|Kısaca yaz|Cari adını yaz|✅ Onaylamak|\*evet\* \/)/u', $out, $m, PREG_OFFSET_CAPTURE)) {
            return substr($out, 0, $m[0][1]) . "\n" . $lines . substr($out, $m[0][1]);
        }
        // Soru kartı: uyarı soru ile "Vazgeçmek için *iptal*." arasına.
        if (preg_match('/\nVazgeçmek için \*iptal\*\.$/u', $out, $m, PREG_OFFSET_CAPTURE)) {
            return substr($out, 0, $m[0][1]) . "\n" . $lines . substr($out, $m[0][1]);
        }

        return $out . "\n" . $lines;
    }

    /** Stoklu firmada ürünsüz alışın kartında (soru değil; cevapsız da kaydedilir). */
    // Mal mı hizmet mi AI her zaman doğru işaretlemiyor (Haiku, Yeni Yapı 19.10 "Boyacı Ahmet'e 50 bin") — satır ikisine de uyar.
    private const WHAT_BOUGHT = '📦 Mal aldıysan ne aldığını yaz (ör. *susam 50 kg*), stoğa girsin. Hizmetse (nakliye, usta…) gerek yok, *evet* yeter.';

    /** Bu süreden sonra hazırlanan cevap TwiML yerine API ile gider (Twilio ~15 sn bekler). */
    private const LATE_SECONDS = 13;

    /** Meşgul numarada sıradaki mesajın kilidi bekleme süresi (sn); testte kısaltılır. */
    public static int $lockWait = 12;

    /** Senkron cevap için AI'a ayrılan süre (Twilio 15 sn bekler; payı kayıt ve ağ için). */
    private const SYNC_SECONDS = 10;

    /** @var array{pending_id:?int,body:string}|null bu mesajın turu arka plana bırakıldı (DeferTurn) */
    private ?array $deferredTurn = null;

    /**
     * Ertelenen tur: istek kapandıktan sonra (Twilio cevabı aldı) aynı firmanın veritabanında süresiz yeniden çalışır,
     * sonuç WhatsApp API ile ayrı mesaj olarak gider; kilit en sonda bırakılır.
     */
    private function runAfterResponse(string $phone, ?WhatsappMessage $incoming, string $lockOwner): void
    {
        $firm = Tenancy::current();
        $turn = $this->deferredTurn;
        $lockName = Tenancy::key('wa-msg:' . Phone::normalize($phone));

        app()->terminating(function () use ($firm, $turn, $phone, $incoming, $lockName, $lockOwner) {
            $work = function () use ($turn, $phone, $incoming) {
                ChatAssistant::$deadline = null;
                $this->deferredTurn = null;
                $this->replies = [];
                $pending = $turn['pending_id'] ? WhatsappPendingExpense::whereKey($turn['pending_id'])
                    ->where('status', 'awaiting_confirmation')->first() : null;
                if (! empty($turn['source'])) {
                    $this->inboundBody = $turn['source']; // sıradaki kalem: emniyetler asıl mesajı görsün
                }
                try {
                    $out = $this->chatTurn($pending, $phone, $turn['body']);
                } catch (\Throwable $e) {
                    Log::error('WhatsApp ertelenen tur hatası', ['msg' => $e->getMessage()]);
                    $this->inboundKind = 'error';
                    $out = 'Şu an toparlayamadım, birazdan tekrar yazar mısın? 🙏';
                }
                $this->deliver($phone, $out);
                if ($this->touchedPartyId) {
                    if ($this->committedPartyId
                        && ($reminder = Reminder::awaitingReply(Phone::normalize($phone), $this->committedPartyId))) {
                        $reminder->acknowledge();
                    }
                    ConversationContext::remember($phone, $this->touchedPartyId);
                }
                $incoming?->update(['kind' => $this->inboundKind]);
            };
            try {
                $firm ? Tenancy::run($firm, $work) : $work();
            } catch (\Throwable $e) {
                Log::error('WhatsApp ertelenen tur çalıştırılamadı', ['msg' => $e->getMessage()]);
            } finally {
                Cache::restoreLock($lockName, $lockOwner)->release();
            }
        });
    }

    /**
     * Fotoğraf sırası (firma 7, 21.10: 3 çek sayfası birden). Meşgulken gelen fotoğraf sıraya girer; açık kart (son 30 dk'da
     * onay/cevap bekleyen) kapanınca sıradaki işlenir — ekranda hep tek soru olsun (sıradaki fotoğraf açık kartın devamı sanılıp
     * tutarı/çekleri karışmıştı). İstek kapandıktan sonra çalışır, cevap ayrı mesaj gider.
     */
    private function photoQueueKey(string $phone): string
    {
        return Tenancy::key('wa-photo-queue:' . Phone::normalize($phone));
    }

    /** @return list<array<string, mixed>> sıradaki fotoğrafların Twilio alanları */
    private function photoQueue(string $phone): array
    {
        return (array) Cache::get($this->photoQueueKey($phone), []);
    }

    /** $reread: aynı fotoğraf sıranın BAŞINA, ikinci okuma olarak (aynı mesaj kimliği "çift mesaj" sayılmasın). */
    private function enqueuePhoto(Request $request, string $phone, bool $reread = false): void
    {
        $item = $reread ? ['_reread' => 1, 'MessageSid' => $request->input('MessageSid', '') . '-r'] + $request->all()
            : ['_queued_at' => microtime(true)] + $request->all(); // birlikte gönderilen sayfalar (çok sayfalı sözleşme) tanınsın
        Cache::lock($this->photoQueueKey($phone) . ':w', 5)->block(5, function () use ($item, $phone, $reread) {
            $queue = $this->photoQueue($phone);
            Cache::put($this->photoQueueKey($phone), $reread ? [$item, ...$queue] : [...$queue, $item], now()->addDay());
        });
    }

    /**
     * Bu fotoğrafla birlikte (en çok 20 sn sonra) gelip sıraya giren fotoğraflar — sıradan alınır.
     *
     * @return list<array<string, mixed>>
     */
    private function siblingPhotos(Request $request, string $phone): array
    {
        $start = (float) ($request->input('_queued_at') ?: $request->server('REQUEST_TIME_FLOAT') ?: microtime(true));

        return Cache::lock($this->photoQueueKey($phone) . ':w', 5)->block(5, function () use ($phone, $start) {
            $take = $keep = [];
            foreach ($this->photoQueue($phone) as $item) {
                $at = (float) ($item['_queued_at'] ?? 0);
                $near = empty($item['_reread']) && $at >= $start - 1 && $at <= $start + 20 && count($take) < 4;
                $near ? $take[] = $item : $keep[] = $item;
            }
            $keep === [] ? Cache::forget($this->photoQueueKey($phone)) : Cache::put($this->photoQueueKey($phone), $keep, now()->addDay());

            return $take;
        });
    }

    /** Sayfaları tek okumada oku; aynı belge değilse (ya da okunamazsa) kardeşler sıraya geri, null. */
    private function readPagesTogether(ExpenseExtractor $extractor, ?string $text, array $first, array $siblings, string $phone): ?array
    {
        $images = [$first];
        foreach ($siblings as $item) {
            if ($img = $this->downloadMedia(Request::create('/', 'POST', $item))) {
                $images[] = $img;
            }
        }
        // Sıraya geri; $separate: AI "ayrı belge" dedi → açık kart kapanana kadar bekler, bir daha "devamı mı" sorulmaz.
        $requeue = function (bool $separate = false) use ($siblings, $phone) {
            $items = $separate ? array_map(fn ($i) => ['_separate' => 1] + $i, $siblings) : $siblings;
            Cache::lock($this->photoQueueKey($phone) . ':w', 5)->block(5, fn () => Cache::put($this->photoQueueKey($phone),
                [...$items, ...$this->photoQueue($phone)], now()->addDay()));
        };
        try {
            $all = $extractor->extract(trim(($text ?? '') . "\n(" . count($images) . ' sayfa birlikte gönderildi.)'), $images, null,
                ConversationContext::get($phone), $phone);
        } catch (\Throwable $e) {
            Log::error('WhatsApp çok sayfalı okuma hatası', ['msg' => $e->getMessage()]);
            $requeue();

            return null;
        }
        if (! AgreementDraft::handles($all) || ($all['pages_related'] ?? null) === false) {
            $requeue(separate: true);

            return null;
        }
        // Kardeş mesajlar konuşma kaydına da (sırada işlenmeyecekler).
        foreach ($siblings as $item) {
            $this->logMessage($phone, 'in', (string) ($item['Body'] ?? ''), true);
            Cache::add(Tenancy::key('wa-sid:' . ($item['MessageSid'] ?? '')), true, now()->addDay());
        }

        return ['from_document' => true, 'pages' => count($images)] + $all;
    }

    private function popPhoto(string $phone): ?array
    {
        return Cache::lock($this->photoQueueKey($phone) . ':w', 5)->block(5, function () use ($phone) {
            $queue = $this->photoQueue($phone);
            $next = array_shift($queue);
            $queue === [] ? Cache::forget($this->photoQueueKey($phone)) : Cache::put($this->photoQueueKey($phone), $queue, now()->addDay());

            return $next;
        });
    }

    private bool $drainScheduled = false;

    private function scheduleDrain(string $phone): void
    {
        if ($this->drainScheduled) {
            return;
        }
        $this->drainScheduled = true;
        $firm = Tenancy::current();
        $lockName = Tenancy::key('wa-msg:' . Phone::normalize($phone));
        app()->terminating(function () use ($phone, $firm, $lockName) {
            $work = function () use ($phone, $lockName) {
                for ($i = 0; $i < 20; $i++) {
                    try {
                        // Açık kart varken sıradaki foto bekler — belgeden gelen ANLAŞMA kartı hariç (sıradaki sayfa onun devamı olabilir, 22.10).
                        $item = Cache::lock($lockName, 360)->block(300, fn () => ($open = WhatsappPendingExpense::where('phone', $phone)
                            ->where('status', 'awaiting_confirmation')->where('updated_at', '>=', now()->subMinutes(30))->latest('updated_at')->first())
                            && ! (AgreementDraft::handles($open->extracted) && ! empty($open->extracted['from_document'])
                                && empty(($this->photoQueue($phone)[0] ?? [])['_separate']))
                            ? null : $this->popPhoto($phone));
                    } catch (LockTimeoutException) {
                        return;
                    }
                    if ($item === null) {
                        return; // açık kart var (cevabı gelince yeniden denenir) ya da sıra boş
                    }
                    $request = Request::create('/whatsapp/webhook', 'POST', $item);
                    $request->attributes->set('via_hub', true); // telefon/imza ilk gelişte denetlendi
                    $request->attributes->set('queued', true);
                    $request->attributes->set('reread', ! empty($item['_reread']));
                    $controller = app()->make(self::class);
                    app()->call([$controller, '__invoke'], ['request' => $request]);
                    foreach ($controller->replies as $n => $reply) {
                        if ($n === 0 && empty($item['_reread'])) {
                            $reply = is_array($reply) ? ['📷 *Sıradaki fotoğraf*' . "\n" . $reply[0], $reply[1] ?? null] : '📷 *Sıradaki fotoğraf*' . "\n" . $reply;
                        }
                        $this->deliver($phone, $reply, log: false); // konuşma kaydını iç işlem yazdı
                    }
                }
            };
            try {
                $firm ? Tenancy::run($firm, $work) : $work();
            } catch (\Throwable $e) {
                Log::error('WhatsApp sıradaki fotoğraf işlenemedi', ['msg' => $e->getMessage()]);
            }
        });
    }

    /** Ertelenen cevabı WhatsApp API ile gönder + konuşma ve maliyet kaydı. */
    private function deliver(string $phone, string|array $out, bool $log = true): void
    {
        [$text, $media] = is_array($out) ? [$out[0], $out[1] ?? null] : [$out, null];
        $sender = app(WhatsappSender::class);
        $ok = $media ? $sender->sendMedia($phone, $text, $media) : $sender->sendText($phone, $text);
        if ($log) {
            $this->logMessage($phone, 'out', $text, $media !== null);
        }
        if ($ok && ($firm = Tenancy::current())) {
            try {
                \App\Models\HubMessageLog::create(['hub_firm_id' => $firm->id, 'phone' => Phone::normalize($phone), 'direction' => 'out']);
            } catch (\Throwable) {
                // maliyet kaydı yazılamadıysa cevap yine gitti
            }
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
        // Tek "çek" bekleyen sorunun cevabı olabilir (firma 7 22.10: "el yazısı kelime çek mi?" → "çek" → çek listesi geldi) — o zaman komut değil.
        if ($numMedia === 0 && config('modules.checks')
            && in_array($this->word($body), ['çekler', 'cekler', 'çeklerim', 'ceklerim', 'çek', 'cek'], true)
            && ! (in_array($this->word($body), ['çek', 'cek'], true) && $this->draftAsking($phone))) {
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
        // Toplu düzeltme yalnız net cevapla: "evet" (hepsi), numara ("1 3") ya da "iptal". Başka her mesaj normal işlenir, öneri
        // dokunulmadan bekler — "süt iptal olmasın, gerisi tamam" hepsini yaptırmasın (yanlış anladıysak hiçbir şey kendiliğinden olmaz).
        if ($pending && ($pending->extracted['kind'] ?? null) === BundleDraft::KIND) {
            if ($numMedia === 0 && ($answer = BundleDraft::answer($pending, $body, $this->isConfirm($body), $this->isCancel($body))) !== null) {
                $this->inboundKind = 'quick';

                return $this->twiml($answer);
            }
            $pending = null;
        }
        // "Belgede telefonu … var — cari kartına yazayım mı?" (anlaşma kaydından sonra): yalnız *evet* / *iptal*; başka mesaj soruyu kapatır.
        if ($pending && ($pending->extracted['kind'] ?? null) === PartyInfoDraft::KIND) {
            if ($numMedia === 0 && ($this->isConfirm($body) || $this->isCancel($body))) {
                $this->inboundKind = 'quick';
                $yes = $this->isConfirm($body);
                $pending->update(['status' => $yes ? 'confirmed' : 'cancelled']);

                return $this->twiml($yes ? PartyInfoDraft::commit($pending->extracted) : 'Tamam, cari kartına yazmadım.');
            }
            $pending->update(['status' => 'superseded']);
            $pending = null;
        }
        $this->openDraft = $pending;

        // Ses / video / belge: şimdilik okuyamıyoruz — sessizce yardım metni yerine açıkça söyle.
        // (Sesi yazıya çevirme planlı; bkz. hafıza: ses kaydı desteği.)
        $mediaType = (string) $request->input('MediaContentType0', '');
        if ($numMedia > 0 && $mediaType !== '' && ! Str::startsWith($mediaType, 'image/') && $mediaType !== 'application/pdf') {
            return $this->twiml(Str::startsWith($mediaType, 'audio/')
                ? "🎤 Sesli mesajları henüz dinleyemiyorum. Yazıyla gönderir misin? 🙏\nÖrnek: \"Ali'ye 15 bin verdim\""
                : "Şu an yazı, fotoğraf ve PDF (dekont, fatura) okuyabiliyorum. Fotoğrafını çekip gönderir misin? 📷");
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
        // Açık kayıt kontrolüne kısa cevap ("evet" / "hayır"): 👍 ile geçmesin — asistan soruyla birlikte yorumlar
        // ("kurayım mı?" → evet → önerilen işlemin kartı).
        $reviewReply = ! $pending && $image === null && ($this->isConfirm($body) || $this->isCancel($body))
            && \App\Models\RecordReview::activeFor($phone) !== null;

        if (! $reviewReply && ! $pending && $image === null && $this->isConfirm($body)
            && ($seen = Reminder::allAwaitingReply(Phone::normalize($phone)))->isNotEmpty()) {
            $this->inboundKind = 'reminder_ack';
            $lines = $seen->map(function (Reminder $r) {
                $r->markSeen();

                return '• ' . $r->text . ($r->next_fire_at ? ' (yine hatırlatacağım: ' . Reminders::when($r->next_fire_at) . ')' : '');
            });

            return $this->twiml("✅ Tamam.\n" . $lines->implode("\n"));
        }

        // Az önce yazılan düzenli gider mesajına "iptal" → o gider silinir (mesaj bunu söylüyor).
        if (! $pending && $image === null && $this->isCancel($body) && ($undone = RecurringDraft::undoPosted($phone)) !== null) {
            $this->inboundKind = 'undo';

            return $this->twiml($undone);
        }

        if (! $reviewReply && ! $pending && $image === null && ($this->isConfirm($body) || $this->isCancel($body) || ctype_digit($body))) {
            $this->inboundKind = 'no_draft';
            // "Tamam / ok" çoğu zaman sohbet kapanışıdır (Akkuş 17.10 22:46) — yarım kalan kayıt varsa adıyla sorulur.
            if ($this->isConfirm($body)) {
                return $this->twiml($this->ackWithoutDraft($phone));
            }

            return $this->twiml($this->isCancel($body) ? '👍 İptal edilecek bekleyen bir kayıt yok.'
                : 'Bu sayı bir şeye cevap değil gibi — ne yapmak istediğini kısaca yazar mısın?');
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

        // Okunamayan yer → "not": olduğu gibi anlaşmanın notuna, kart onaya gelir (AI'sız; kullanıcı 22.10).
        if ($pending && $image === null && AgreementDraft::handles($pending->extracted) && ! empty($pending->extracted['unreadable'])
            && in_array($this->word($body), AgreementDraft::NOTE_WORDS, true)) {
            $this->inboundKind = 'quick';

            return $this->twiml($this->saveDraft($pending, $phone, AgreementDraft::noteUnreadable($pending->extracted)));
        }

        // "Bu sayfa X sözleşmesinin devamı mı? *evet* / *ayrı*" (AI emin değildi): evet → karta eklenir; ayrı → foto sıraya, kart kapanınca.
        if ($pending && $image === null && AgreementDraft::handles($pending->extracted) && ! empty($pending->extracted['page_offer'])
            && ($this->isConfirm($body) || in_array($this->word($body), ['ayrı', 'ayri', 'ayrı belge', 'değil', 'degil', 'başka'], true))) {
            $this->inboundKind = 'quick';
            $d = $pending->extracted;
            $offer = $d['page_offer'];
            unset($d['page_offer']);
            if ($this->isConfirm($body)) {
                return $this->twiml($this->saveDraft($pending, $phone, AgreementDraft::appendPage($d, $offer['data'])));
            }
            $pending->update(['extracted' => $d]);
            // _separate: açık kart kapanana kadar bekler, bir daha "devamı mı" sorulmaz.
            Cache::lock($this->photoQueueKey($phone) . ':w', 5)->block(5, fn () => Cache::put($this->photoQueueKey($phone),
                [...$this->photoQueue($phone), ['_separate' => 1] + $offer['item']], now()->addDay()));

            return $this->twiml('Tamam, ayrı belge — bu kartı bitirince ona bakacağım.' . "\n\n" . $pending->summary);
        }

        // Anlaşma belgesinde okunamayan yer soruldu → "geç": önemli değil, kart onaya gelir (AI'sız).
        if ($pending && $image === null && AgreementDraft::handles($pending->extracted) && AgreementDraft::skippable($pending->extracted)
            && in_array($this->word($body), AgreementDraft::SKIP_WORDS, true)) {
            $this->inboundKind = 'quick';

            return $this->twiml($this->saveDraft($pending, $phone, AgreementDraft::skipUnreadable($pending->extracted)));
        }

        // Anlaşma: "fark tam %20 KDV. *1* Fiyatlara KDV ekle · *2* Kalemler doğru" → numara (AI'sız).
        if ($pending && $image === null && ctype_digit($body) && AgreementDraft::handles($pending->extracted)
            && AgreementDraft::awaitsTotalChoice($pending->extracted)) {
            $this->inboundKind = 'quick';
            $chosen = AgreementDraft::chooseTotal($pending->extracted, (int) $body);

            return $this->twiml($chosen === null ? 'Listede o numara yok. ' . AgreementDraft::question($pending->extracted)
                : $this->saveDraft($pending, $phone, $chosen));
        }

        // Anlaşma: "11 çeke nasıl böleyim? *1* … *3* Eşit" → numara (AI'sız).
        if ($pending && $image === null && ctype_digit($body) && AgreementDraft::handles($pending->extracted)
            && AgreementDraft::awaitsSplit($pending->extracted)) {
            $this->inboundKind = 'quick';
            $chosen = AgreementDraft::chooseSplit($pending->extracted, (int) $body);

            return $this->twiml($chosen === null ? 'Listede o numara yok. ' . AgreementDraft::question($pending->extracted)
                : $this->saveDraft($pending, $phone, $chosen));
        }

        // Anlaşmadan teslim: "hangi anlaşma?" / fiyat farkı sorusuna numara (AI'sız).
        if ($pending && $image === null && ctype_digit($body) && AgreementDeliveryDraft::handles($pending->extracted)
            && AgreementDeliveryDraft::awaitsNumber($pending->extracted)) {
            $this->inboundKind = 'quick';
            $chosen = AgreementDeliveryDraft::choose($pending->extracted, (int) $body);
            if ($chosen === null) {
                return $this->twiml('Listede o numara yok. ' . AgreementDeliveryDraft::question($pending->extracted));
            }
            if (isset($chosen['cancel'])) {
                $pending->update(['status' => 'cancelled']);

                return $this->twiml($chosen['cancel']);
            }

            return $this->twiml($this->saveDraft($pending, $phone, $chosen));
        }
        // "Kaç m³ geldi?" → yalnız sayı: miktar (tutar sanılmasın — eski "tutarı eksik taslak" kuralı buraya gelmesin).
        if ($pending && $image === null && AgreementDeliveryDraft::handles($pending->extracted)
            && ($typed = $this->typedAmount($body)) !== null && ($filled = AgreementDeliveryDraft::fillNumber($pending->extracted, $typed)) !== null) {
            $this->inboundKind = 'quick';

            return $this->twiml($this->saveDraft($pending, $phone, $filled));
        }

        // Eşleşen çek o değilse ("başka") → eski soru: kime yazılmıştı (AI'sız).
        if ($pending && $image === null && $this->kind($pending->extracted) === self::KIND_CHECK_CLEARED
            && in_array($this->word($body), ['başka', 'baska', 'değil', 'degil', 'o değil', 'başka çek', 'baska cek'], true)) {
            $this->inboundKind = 'quick';

            return $this->twiml($this->saveDraft($pending, $phone, ['skip_check_match' => true] + (array) ($pending->extracted['check_original'] ?? [])));
        }

        // K2: çek kime sorusuna "gider/masraf" → gider olarak kalsın (AI'sız).
        if ($pending && $image === null && ! empty($pending->extracted['check_payment'])
            && in_array($this->word($body), ['gider', 'masraf', 'gider yaz', 'masraf yaz'], true)) {
            $this->inboundKind = 'quick';
            $data = ['kind' => ExpenseExtractor::KIND_EXPENSE, 'check_payment' => false, 'check_answered' => true, 'question' => null] + $pending->extracted;

            return $this->twiml($this->saveDraft($pending, $phone, $data));
        }

        // Fiş: "ödedim" / "veresiye" (soru ya da alışkanlıkla yazılanı değiştirme) — AI'sız, kesin cevap.
        if ($pending && $image === null && ! empty($pending->extracted['from_document'])
            && in_array($this->kind($pending->extracted), [ExpenseExtractor::KIND_PURCHASE, ExpenseExtractor::KIND_EXPENSE], true)) {
            $w = $this->word($body);
            $paid = in_array($w, self::PAID_WORDS, true) ? true : (in_array($w, self::UNPAID_WORDS, true) ? false : null);
            if ($paid !== null) {
                $this->inboundKind = 'quick';
                $data = ['paid' => $paid, 'paid_from_habit' => false] + $pending->extracted;

                return $this->twiml($this->saveDraft($pending, $phone, $data));
            }
        }

        // Eşleşen cari ile yazılan ad farklıydı, "aynı kişi mi?" → "yeni": yazılan adla yeni cari (AI'sız, kesin cevap).
        if ($pending && $image === null && (! empty($pending->extracted['party_check']) || ! empty($pending->extracted['document_name']))
            && in_array($this->word($body), ['yeni', 'yeni cari', 'yeni kişi', 'farklı', 'farklı kişi', 'başka', 'başka kişi', 'başka biri',
                'aynı değil', 'değil', 'o değil', 'o degil', 'aynı kişi değil'], true)) {
            $this->inboundKind = 'quick';
            $data = $pending->extracted;
            $data['party_name'] = $data['party_said'];
            $data['party_id'] = Party::where('name', $data['party_said'])->value('id'); // aynı adla açılmış cari varsa o
            $data['party_said'] = null;

            return $this->twiml($this->saveDraft($pending, $phone, $data));
        }

        // Stok kartı "17 mi olsun, 5 + 17 = 22 mi?" → yalnız sayı cevabı (AI'sız; tutar sanılmasın).
        if ($pending && $image === null && $this->kind($pending->extracted) === ExpenseExtractor::KIND_STOCK_UPDATE
            && ($answered = \App\Services\Whatsapp\StockCard::answer($pending->extracted, $body)) !== null) {
            $this->inboundKind = 'quick';

            return $this->twiml($this->saveDraft($pending, $phone, ['question' => null] + $answered));
        }

        // Sohbet asistanı (deneme, ayarla açılır): yazılı mesajlar konuşmayı gören asistana gider.
        // Kesin cevaplar (evet / iptal / numara) eskisi gibi kodda kalır — onu AI'a sormaya gerek yok.
        if (ChatAssistant::enabled() && $image === null && $body !== ''
            && ! ($pending && ($this->isConfirm($body) || $this->isCancel($body) || ctype_digit($body)
                || (WorkChoice::shown($pending->extracted) && WorkChoice::match($pending->extracted, $body) !== null)
                // Stok sorusu cevabı ("1 20 çuval", "20 çuval") kodda — AI'a gidince soru tekrarlanıyordu (eval 21.10, Sonnet).
                || (StockChoice::shown($pending->extracted) && StockChoice::answer($pending->extracted, $body) !== null)))) {
            return $this->twiml($this->chatTurn($pending, $phone, $body));
        }

        if ($pending) {
            if ($this->isConfirm($body)) {
                return $this->twiml($this->commitThenNext($pending, $phone));
            }
            if ($this->isCancel($body)) {
                $pending->update(['status' => 'cancelled']);

                return $this->twiml('İptal edildi. ✖️' . self::leftoverNextItem($pending));
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
            // Anlaşma / anlaşmadan teslim tutarsız olabilir (fiyat listesi) — numara orada proje menüsü cevabı.
            if ($typedAmount !== null && (float) ($pending->extracted['amount'] ?? 0) <= 0
                && ! in_array($this->kind($pending->extracted), [ExpenseExtractor::KIND_REMINDER, ExpenseExtractor::KIND_AGREEMENT,
                    ExpenseExtractor::KIND_AGREEMENT_DELIVERY], true)) {
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

            // Numaralı iş durumu sorusu ("*1* Mal geldi · *2* Bağlantı / peşin · *3* Eski borcu ödedim") → seçim kayda çevrilir. Eski
            // "*1* iş/mal aldım, *2* avans" menüsünden ÖNCE (yoksa "2" orada yakalanıp kayıt yarım kalıyordu).
            // Çek okunamadı / tekrar çek / vade yok: numara bu sorunun cevabı değil (21.10: "2" iş durumu sanıldı, çeksiz kaydetti).
            if (in_array($pending->extracted['check_choice'] ?? null, ['unread', 'dup', 'due'], true) && ctype_digit($body)) {
                return $this->twiml('❓ ' . CheckSplit::question($pending->extracted) . "\nVazgeçmek için *iptal*.");
            }
            // Çek bölme / toplam sorusu ("*1* 10 × 100.000 + son çek 187.000 · …") → kod çekleri yazar.
            if (ctype_digit($body) && CheckSplit::shown($pending->extracted)) {
                $data = CheckSplit::choose($pending->extracted, (int) $body);
                if ($data !== null) {
                    $this->inboundKind = 'quick';

                    return $this->twiml($this->saveDraft($pending, $phone, $data));
                }

                return $this->twiml('Listede o numara yok. ' . CheckSplit::question($pending->extracted));
            }

            // Stok sorusu ("*1* Geldi — kaç? · *2* tedarikçide · *0* stok tutmuyorum"): "1 20 çuval", "20 çuval", "2", "0".
            if (StockChoice::shown($pending->extracted) && ($res = StockChoice::answer($pending->extracted, $body)) !== null) {
                if (isset($res['ask'])) {
                    $data = $pending->extracted;
                    $data['stock_need_qty'] = str_starts_with($res['ask'], 'Kaç');
                    $pending->update(['extracted' => $data]);

                    return $this->twiml('❓ ' . $res['ask'] . "\nVazgeçmek için *iptal*.");
                }
                $this->inboundKind = 'quick';

                return $this->twiml($this->saveDraft($pending, $phone, $res['data']));
            }

            if (WorkChoice::shown($pending->extracted)
                && (ctype_digit($body) || ($typed = WorkChoice::match($pending->extracted, $body)) !== null)) {
                $data = WorkChoice::choose($pending->extracted, ctype_digit($body) ? (int) $body : $typed);
                if ($data !== null) {
                    $this->inboundKind = 'quick';

                    return $this->twiml($this->saveDraft($pending, $phone, $data));
                }

                return $this->twiml('Listede o numara yok. ' . WorkChoice::question($pending->extracted));
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
            if (ctype_digit($body) && ProjectChoice::applies($pending->extracted) && ProjectChoice::menuShown($pending->extracted)
                && empty($pending->extracted['settles_expense_id'])) {
                $data = ProjectChoice::choose($pending->extracted, (int) $body);
                if ($data !== null) {
                    $pending->update(['extracted' => $data]);

                    return $this->twiml($this->commitThenNext($pending, $phone));
                }

                return $this->twiml('Listede o numara yok. Numarayı yaz (0 = genel).');
            }

            // Metin (fotoğrafsız): önceki taslağa düzeltme mi, yoksa tamamen yeni bir kayıt mı?
            // Kararı AI verir (is_new_entry) — kurallar kırılgan. Yeni giderse eski taslağın
            // proje/cari/kategorisi SIZMASIN diye taze taslak açılır.
            if ($image === null && $body !== '') {
                try {
                    $data = $extractor->extract($body, null, $pending->extracted, null, $phone);
                } catch (\Throwable $e) {
                    Log::error('WhatsApp gider düzeltme hatası', ['msg' => $e->getMessage()]);

                    $this->inboundKind = 'error';

                    return $this->twiml('Şu an okuyamadım, birazdan tekrar dener misin? 🙏');
                }

                if (! empty($data['is_new_entry'])) {
                    // Yeni gider → SIFIRDAN çıkar: previous verilince model önceki proje/cari'yi sızdırabiliyor;
                    // temiz çıkarım için previous=null ile yeniden. Eski taslak açık kalır (yarım kayıt takibi).
                    try {
                        $data = $extractor->extract($body, null, null, ConversationContext::get($phone), $phone);
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

            // Açık sözleşme kartına yeni sayfa (22.10 Kamuran: 2. sayfa ödeme planıydı, ayrı okundu/kayboldu): aynı belgenin devamı mı?
            // AI karar verir: devamıysa karta eklenir, emin değilse sorulur, başka belgeyse aşağıdaki eski akış (yeni kayıt).
            if ($image !== null && AgreementDraft::handles($pending->extracted) && ! empty($pending->extracted['from_document'])
                && ! $request->input('_separate')) {
                try {
                    $page = $extractor->extract($body ?: null, $image, $pending->extracted, null, $phone);
                } catch (\Throwable $e) {
                    Log::error('WhatsApp sözleşme sayfası okunamadı', ['msg' => $e->getMessage()]);
                    $this->inboundKind = 'error';

                    return $this->twiml('Şu an okuyamadım, birazdan tekrar dener misin? 🙏');
                }
                $continues = AgreementDraft::handles($page) ? ($page['continues_agreement'] ?? null) : false;
                if ($continues === true) {
                    return $this->twiml($this->saveDraft($pending, $phone, AgreementDraft::appendPage($pending->extracted, $page)));
                }
                if ($continues === null) {
                    $pending->update(['extracted' => ['page_offer' => ['data' => $page, 'item' => $request->all()]] + $pending->extracted]);
                    $this->inboundKind = 'agreement';

                    return $this->twiml('📄 Bu sayfa *' . AgreementDraft::partyLabel($pending->extracted) . '* sözleşmesinin devamı mı? '
                        . '*evet* (karta ekleyeyim) / *ayrı* (ayrı belge)');
                }
            }

            // Tutarı beklenen taslağa belge geldi (Taner: "borcum ödendi" → tutar soruldu → dekont PDF'i): belge o kaydın tamamlayıcısı.
            // Anlaşma kartı hariç: fiyat listesinde tutar 0 — başka belge karta karışmasın (devam sayfası yukarıda).
            if ($image !== null && (float) ($pending->extracted['amount'] ?? 0) <= 0
                && ! in_array($this->kind($pending->extracted), [ExpenseExtractor::KIND_REMINDER, ExpenseExtractor::KIND_AGREEMENT], true)) {
                try {
                    $data = $extractor->extract($body ?: null, $image, $pending->extracted, null, $phone);
                    $data['from_document'] = true;
                } catch (\Throwable $e) {
                    Log::error('WhatsApp belge düzeltme hatası', ['msg' => $e->getMessage()]);
                    $this->inboundKind = 'error';

                    return $this->twiml('Şu an okuyamadım, birazdan tekrar dener misin? 🙏');
                }
                // Kod emniyetleri (borç ödendi vb.) kaydı başlatan cümleyi de görsün — belgenin kendisinde yazı yok.
                $this->inboundBody = trim((string) WhatsappMessage::where('phone', Phone::normalize($phone))->where('direction', 'in')
                    ->whereNotNull('body')->where('body', '!=', '')->where('created_at', '>=', $pending->created_at->copy()->subMinute())
                    ->orderBy('id')->value('body') . ' ' . $this->inboundBody);

                return $this->twiml($this->saveDraft($pending, $phone, $data));
            }

            // Yeni fotoğraf → yeni gider (düzeltme değil): aşağıda taze başla, eski taslak yarım kayıt olarak kalır.
        }

        // 2) Yeni gider girişi (metin ve/veya fotoğraf)
        if ($image === null && $body === '') {
            $this->inboundKind = 'guide';

            return $this->twiml($this->guideText());
        }

        // İkinci okuma: ilk okumada fotoğraftaki çekler boş geldiyse (Opus ~5'te 1, 21.10) aynı fotoğraf bu talimatla bir kez daha okunur.
        $reread = (bool) $request->attributes->get('reread');
        $text = $reread ? trim(($body ?: '') . "\n(Fotoğraftaki HER çeki `checks` alanına tek tek yaz: numara, vade yazıldığı gibi, tutar.)") : ($body ?: null);
        try {
            $data = $extractor->extract($text, $image, null, ConversationContext::get($phone), $phone);
            $data['from_document'] = $image !== null;
            // Birlikte gönderilen sayfalar (Kamuran 22.10: iki foto 1 sn arayla): ilk sayfa anlaşmaysa, okunurken sıraya giren
            // kardeş sayfalar tek okumada birlikte okunur → tek kart. Başka belgeyse sıraya geri (eskisi gibi tek tek).
            if ($image !== null && ! $reread && AgreementDraft::handles($data) && ($siblings = $this->siblingPhotos($request, $phone)) !== []) {
                $data = $this->readPagesTogether($extractor, $text, $image, $siblings, $phone) ?? $data;
            }
        } catch (\Throwable $e) {
            Log::error('WhatsApp gider çıkarma hatası', ['msg' => $e->getMessage()]);
            $this->inboundKind = 'error';

            return $this->twiml('Şu an okuyamadım, birazdan tekrar dener misin? 🙏');
        }

        $out = $this->saveDraft(null, $phone, $data, $numMedia > 0 ? (string) $request->input('MediaUrl0') : null);
        if ($image !== null && ! $reread && ($open = WhatsappPendingExpense::where('phone', $phone)->where('status', 'awaiting_confirmation')
            ->latest('id')->first()) && ($open->extracted['check_choice'] ?? null) === 'unread' && $open->created_at->gte(now()->subMinutes(2))) {
            $open->update(['status' => 'superseded']);
            $this->enqueuePhoto($request, $phone, reread: true);
            $this->scheduleDrain($phone);
            Log::info('WhatsApp çekler okunamadı — fotoğraf ikinci kez okunacak');

            return $this->twiml('🔎 Çeklere bir daha bakıyorum…');
        }

        return $this->twiml($out);
    }

    /**
     * Kaydet; aynı mesajda başka kalem kaldıysa ("5400 parça, 10.000 işçilik") sıradakini hemen öner
     * (Oktay, 11 Ekim: ikinci kalem kayboluyordu, ayrıca yazmak zorunda kaldı).
     */
    private function commitThenNext(WhatsappPendingExpense $pending, string $phone): string|array
    {
        // Önce anla: "Sinan Sele mi?" sorusuna "evet" = kişi teyidi (kayıt onayı değil) → kişi netleşir, tam özet onaya gelir.
        if (! empty($pending->extracted['party_asked']) && empty($pending->extracted['party_confirmed'])
            && (int) $pending->extracted['party_asked'] === (int) ($pending->extracted['party_id'] ?? 0)) {
            return $this->saveDraft($pending, $phone, ['party_confirmed' => true, 'question' => null] + $pending->extracted);
        }
        // Önce anla: cevaplanmamış soru varken "evet" kaydetmez — soru tekrar (tahmin yok). Tutar ve çapraz kontrol sorusu da.
        // Süzgeç sorusuna "evet" (çoğu zaman "A mı B mi?" sorusuna verilen doğal cevap): aynı soru TEKRARLANMAZ — kayıt kartı gösterilir,
        // kullanıcı gördüğünü onaylar ya da düzeltir (19.10 Yeni Yapı: aynı soru üç tur döndü).
        if (! empty($pending->extracted['cross_question']) && ($pending->extracted['scale_question'] ?? null) === null) {
            return $this->saveDraft($pending, $phone, ['cross_question' => null, 'question' => null] + $pending->extracted);
        }
        if (($ask = ($pending->extracted['scale_question'] ?? null)
            ?? CheckSplit::question($pending->extracted)
            ?? (WorkChoice::shown($pending->extracted) ? ($pending->extracted['question'] ?? null) : null)
            ?? Understanding::gap($pending->extracted)) !== null) {
            return '❓ ' . $ask . "\nCevabını yaz; vazgeçmek için *iptal*.";
        }
        // K8: hatırlatılmış ve AI'ın şüphe sorusu cevaplanmamış taslak tek "evet"le kaydolmaz — önce soru.
        if ($pending->follow_ups > 0 && ($gap = DraftFollowUp::gap($pending))['code'] === DraftGaps::DETAIL
            && trim((string) ($pending->extracted['question'] ?? '')) !== '') {
            return $gap['ask'] . "\nGerek yoksa *iptal* yaz.";
        }
        $done = $this->commit($pending);
        $next = $pending->extracted['next_items'] ?? null;
        if ($pending->fresh()?->status !== 'confirmed') {
            return $done;
        }
        // Kayıttan sonra kalıcı bilgi önerisi (Faz 2): "🧠 Bir de şunu hatırlayayım mı?" — sıradaki kalem yoksa.
        $suggest = $pending->extracted['memory_suggestion'] ?? null;
        if (! $next && is_array($suggest) && ! empty($suggest['fact'])) {
            $memory = ['kind' => ExpenseExtractor::KIND_REMEMBER, 'memory_kind' => $suggest['kind'] ?? 'note',
                'memory_subject' => $suggest['subject'] ?? null, 'memory_fact' => $suggest['fact'],
                'party_id' => $pending->extracted['party_id'] ?? null, 'memory_source' => $this->inboundBody, 'amount' => 0];
            if (MemoryDraft::blocker($memory, $phone) === null) {
                $summary = MemoryDraft::summary($memory, $phone);
                WhatsappPendingExpense::create(['phone' => $phone, 'extracted' => $memory, 'summary' => $summary, 'status' => 'awaiting_confirmation']);

                return $done . "\n\n" . str_replace('🧠 *Hatırlayayım mı?*', '🧠 *Bir de şunu hatırlayayım mı?*', $summary) . "\n*evet* / *iptal*";
            }
        }
        if (! ChatAssistant::enabled() || ! $next) {
            return $this->withLeftover($done, $phone, $pending->id);
        }

        // Sıradaki kalem asıl mesajla işlenir (review 8b: emniyetler "evet"e bakıyordu) ve kontroller atlanmaz: süre yetmezse
        // kalem arka planda hazırlanır, ayrı mesajla gelir.
        $source = (string) ($pending->extracted['source_text'] ?? '');
        $deferNext = function () use ($done, $next, $source) {
            $this->deferredTurn = ['pending_id' => null, 'body' => 'Sıradaki kalem: ' . $next, 'source' => $source];

            return $done . "\n\n➡️ Sıradaki kalemi (" . ExpenseExtractor::nextItemsLabel($next) . ') hazırlıyorum…';
        };
        if (ChatAssistant::$deadline !== null && ChatAssistant::$deadline - microtime(true) < 8) {
            return $deferNext();
        }
        try {
            $result = app(ChatAssistant::class)->respond($phone, 'Sıradaki kalem: ' . $next, null);
        } catch (DeferTurn) {
            return $deferNext();
        } catch (\Throwable $e) {
            Log::warning('WhatsApp sıradaki kalem önerilemedi', ['msg' => $e->getMessage()]);

            return $done . "\n\nAynı mesajdaki diğer kalemi (" . ExpenseExtractor::nextItemsLabel($next) . ') ayrıca yazar mısın?';
        }
        if ($result['type'] !== 'entry') {
            // Sıradaki kalem sessizce düşmesin (Oktay 19.10: "Ekle" → düzenli gider kuruldu, 18 Ekim kalemi boş cevapla kayboldu).
            $text = $result['type'] === 'reply' ? trim((string) ($result['text'] ?? '')) : '';

            return $done . "\n\n" . ($text !== '' ? $text
                : 'Aynı mesajdaki diğer kalemi (' . ExpenseExtractor::nextItemsLabel($next) . ') ayrıca yazar mısın?');
        }
        $data = $result['data'];
        $data['is_new_entry'] = true;
        $data['source_text'] = $source !== '' ? $source : null;
        $said = $this->inboundBody;
        $this->inboundBody = $source !== '' ? $source : $said;
        try {
            Understanding::$deferOnTimeout = ChatAssistant::$deadline !== null;
            $out = $this->saveDraft(null, $phone, $data);
        } catch (DeferTurn) {
            return $deferNext();
        } finally {
            Understanding::$deferOnTimeout = false;
            $this->inboundBody = $said;
        }

        return is_string($out) ? $done . "\n\n➡️ *Sıradaki:*\n" . $out : $done;
    }

    /**
     * Bekleyen taslak yokken "tamam / ok" (kullanıcı kararı 19.10; Akkuş 17.10: açıklamaya "Tamam" → "Onay bekleyen kayıt yok
     * (taslaklar 30 dk geçerli)"). Yarım kalmış (30 dk'yı geçmiş) kayıt varsa adıyla anılıp tekrar sorulur — onay sanılıp
     * sessizce kaydedilmez de, unutulmaz da; yoksa yalnız 👍.
     */
    private function ackWithoutDraft(string $phone): string
    {
        $left = WhatsappPendingExpense::where('phone', $phone)->where('status', 'awaiting_confirmation')
            ->where('updated_at', '>=', now()->subDay())->latest('updated_at')->first();
        if (! $left) {
            return '👍';
        }
        $left->touch(); // tekrar etkin: "evet" ona gider

        return 'Az önceki *' . DraftGaps::label($left->extracted) . "* henüz kaydedilmedi. Kaydedeyim mi? *evet* / *iptal*";
    }

    /**
     * Firmanın BAŞKA numarasında son 30 dk'da onay bekleyen kasa / satış / alış / gider (Akkuş 18.10 22:46: Ergenekon numarası kasayı
     * girmiş, onay bekliyordu; Emre "Ergenekon ciro ne kadar" diye sorunca "henüz kasa yok" dendi). Yalnız bilgi; kayda dokunmaz.
     */
    private function otherPhonesPending(string $phone): string
    {
        try {
            $mine = Phone::normalize($phone);
            $rows = WhatsappPendingExpense::where('status', 'awaiting_confirmation')->where('updated_at', '>=', now()->subMinutes(30))
                ->latest('updated_at')->limit(10)->get()
                ->filter(fn ($p) => Phone::normalize((string) $p->phone) !== $mine && in_array($this->kind($p->extracted), [ExpenseExtractor::KIND_TRADE_SALE,
                    ExpenseExtractor::KIND_SALE, ExpenseExtractor::KIND_TRADE_PURCHASE, ExpenseExtractor::KIND_PURCHASE, ExpenseExtractor::KIND_EXPENSE], true)
                    && (float) ($p->extracted['amount'] ?? 0) > 0);
        } catch (\Throwable $e) {
            Log::warning('Diğer numaraların bekleyen kayıtları okunamadı', ['msg' => $e->getMessage()]);

            return '';
        }

        return $rows->isEmpty() ? '' : "\n\n⏳ Diğer numaradan onay bekleyen (henüz sayılmadı):\n" . $rows->map(fn ($p) => '• …'
            . mb_substr(Phone::normalize((string) $p->phone), -4) . ': ' . DraftGaps::label($p->extracted)
            . (! empty($p->extracted['project_id']) && ($b = Project::whereKey($p->extracted['project_id'])->value('name')) ? ' · ' . $b : ''))->implode("\n");
    }

    /** İlk kalem iptal edilince aynı mesajdaki sıradaki iş kaybolmasın (review 8: sessizce düşüyordu). */
    private static function leftoverNextItem(WhatsappPendingExpense $pending): string
    {
        $next = $pending->extracted['next_items'] ?? null;

        return $next ? "\n➡️ Aynı mesajda bir de şu vardı: " . ExpenseExtractor::nextItemsLabel((string) $next) . ' — kaydetmemi istersen yazman yeterli.' : '';
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
        // Kasa mesajı ("Ergenekon kasa / Nakit 5900 / Pos 21540") başka türden açık taslağın düzeltmesi DEĞİLDİR:
        // alış taslağı "Hangi şube?" diye sorarken kasa yazılınca şube cevabı sanılıp kasa kaydedilmiyordu
        // (deneme seti 16.10). AI taslaksız görür → yeni kasa; eski taslak yarım kayıt olarak açık kalır.
        if ($pending && $this->kind($pending->extracted) !== ExpenseExtractor::KIND_TRADE_SALE
            && in_array(ExpenseExtractor::KIND_TRADE_SALE, ExpenseExtractor::allowedKinds(), true)
            && ExpenseExtractor::looksLikeTill($body)) {
            $pending = null;
            $fresh = true;
        }
        // Kayıt kontrolü cevabı: bu turda açılan taslak o kontrole bağlanır (onaylanınca "Düzeltildi").
        $this->turnReview = \App\Models\RecordReview::activeFor($phone);
        $this->pendingBefore = (int) WhatsappPendingExpense::max('id');
        try {
            $result = app(ChatAssistant::class)->respond($phone, $body, $pending?->extracted, $fresh ?? false);
        } catch (DeferTurn $e) {
            // early = isteği erken kapatabiliyor muyuz (LiteSpeed/FPM); yoksa "bakıyorum" iş bitince gider.
            Log::info('WhatsApp tur arka plana bırakıldı', ['why' => $e->getMessage(),
                'early' => function_exists('litespeed_finish_request') || function_exists('fastcgi_finish_request')]);
            $this->deferredTurn = ['pending_id' => $pending?->id, 'body' => $body];
            $this->inboundKind = 'deferred';

            return '🔎 Bakıyorum, birkaç saniye…';
        } catch (\Throwable $e) {
            Log::error('WhatsApp sohbet asistanı hatası', ['msg' => $e->getMessage()]);
            $this->inboundKind = 'error';

            return 'Şu an okuyamadım, birazdan tekrar dener misin? 🙏';
        }

        switch ($result['type']) {
            case 'confirm':
                $this->inboundKind = 'quick';
                if (! $pending) {
                    return $this->ackWithoutDraft($phone);
                }
                if (($result['paid'] ?? null) !== null
                    && in_array($this->kind($pending->extracted), [ExpenseExtractor::KIND_EXPENSE, ExpenseExtractor::KIND_PURCHASE], true)) {
                    $pending->update(['extracted' => ['paid' => $result['paid']] + $pending->extracted]);
                }

                return $this->commitThenNext($pending, $phone);
            case 'ask_confirm':
                // "Doğru mu?" (tutar uyarısı / kontrol sorusu) → "Doğru" teyittir (Oktay 20.10: iki kez "Dogru" yazdı, kart yeniden soruldu).
                if ($pending && ChatAssistant::affirmsCheck($body) && $this->asksDoğru($pending)) {
                    $this->inboundKind = 'quick';

                    return $this->commitThenNext($pending, $phone);
                }
                $this->inboundKind = 'chat';
                // Kontrol sorusunun cevabı ("Avanstan düşecek") onay sanılınca aynı soru kartla tekrar geliyordu (firma 7, 20.10;
                // Yeni Yapı 19.10 Boyacı Ahmet). Soru bir daha sorulmaz — kayıt kartı gösterilir, kullanıcı onaylar ya da düzeltir.
                if ($pending && ! empty($pending->extracted['cross_question'])) {
                    return $this->saveDraft($pending, $phone, ['cross_question' => null, 'question' => null] + $pending->extracted);
                }

                return $pending
                    // Kart az önce gitti — tamamı tekrar gönderilmez, tek satır (kullanıcı 20.10: "cevaplar çok uzun").
                    ? '⏳ ' . DraftGaps::label($pending->extracted) . "\nKaydedeyim mi? *evet* yaz; değiştireceğin bir şey varsa onu yaz."
                    : $this->ackWithoutDraft($phone);
            case 'undo':
                $this->inboundKind = 'undo';

                return $this->undoDraft($pending, $phone, $result['replacement'] ?? null, $result['find'] ?? null);
            case 'review_ok':
                $this->inboundKind = 'review';
                if (! $this->turnReview) {
                    return $this->ackWithoutDraft($phone);
                }
                if (($result['verdict'] ?? 'correct') === 'declined') {
                    $this->turnReview->closeBy('dismissed', 'Müşteri istemedi: "' . mb_substr($body, 0, 300) . '"');

                    return '👍 Tamam, dokunmadım.';
                }
                $this->turnReview->closeBy('correct', 'Müşteri doğru dedi: "' . mb_substr($body, 0, 300) . '"');

                return '👍 Teşekkürler, kayıt doğruymuş — dokunmadım.';
            case 'return':
                $this->inboundKind = 'return';

                return ReturnDraft::propose($pending, $phone, $result['find'] ?? []);
            case 'cancel':
                $this->inboundKind = 'quick';
                $pending?->update(['status' => 'cancelled']);

                return $pending ? 'İptal edildi. ✖️' . self::leftoverNextItem($pending) : 'Tamam.';
            case 'entry':
                $data = $result['data'];
                if ($pending && ! empty($data['is_new_entry'])) {
                    $pending = null; // eski taslak açık kalır — iş bitince "yarım kalan var" diye sorulur
                }
                if ($pending && ($data['party_id'] ?? null) === ($pending->extracted['party_id'] ?? null)) {
                    $data += array_intersect_key($pending->extracted, ['contract_id' => true, 'advance' => true, 'payment_purpose' => true, 'advance_declined' => true]);
                }
                // Önce anla: belgeden okunan çekler soru-cevap turunda kaybolmasın.
                if ($pending && empty($data['checks']) && empty($data['check_plan']) && ! empty($pending->extracted['checks'])) {
                    $data['checks'] = $pending->extracted['checks'];
                    $data['check_plan'] = $pending->extracted['check_plan'] ?? null;
                }
                if ($pending && empty($data['check_plan']) && empty($data['checks']) && ! empty($pending->extracted['check_plan'])) {
                    $data['check_plan'] = $pending->extracted['check_plan']; // bölme sorusu cevaplanmadan başka soru turu
                }
                // Düzeltmede sıradaki kalem unutulmasın (Recep takası: peynir satışı düzeltme turunda kayboldu).
                if ($pending && empty($data['next_items']) && ! empty($pending->extracted['next_items'])) {
                    $data['next_items'] = $pending->extracted['next_items'];
                }
                // Süzgeç / ikinci göz için senkron süre yetmezse tur arka plana (DeferTurn) — kontrol atlanmaz (17.10).
                try {
                    Understanding::$deferOnTimeout = ChatAssistant::$deadline !== null;
                    $out = $this->saveDraft($pending, $phone, $data);
                } catch (DeferTurn $e) {
                    Log::info('WhatsApp tur arka plana bırakıldı', ['why' => $e->getMessage()]);
                    $this->deferredTurn = ['pending_id' => $pending?->id, 'body' => $body];
                    $this->inboundKind = 'deferred';

                    return '🔎 Bakıyorum, birkaç saniye…';
                } finally {
                    Understanding::$deferOnTimeout = false;
                }
                // Doğal konuşmada AI'ın ayrı ön metni tekrar olur — özet zaten konuşur gibi.
                $said = NaturalVoice::enabled() ? '' : trim((string) ($result['text'] ?? ''));

                return $said !== '' && is_string($out) ? $said . "\n\n" . $out : $out;
            default:
                $this->inboundKind = 'chat';

                return $result['text'] . $this->otherPhonesPending($phone);
        }
    }

    /**
     * Taslağı kaydet/güncelle ve müteahhide gidecek metni döndür.
     * Bakiye sorusu taslak açmaz — direkt cevaplanır.
     */
    /** Kişisiz "çek ödendi" → bekleyen verilmiş çekle eşleşti (kodda kalan taslak türü; AI üretmez). */
    private const KIND_CHECK_CLEARED = 'check_cleared';

    /** Bu mesaj geldiğinde açık (onay bekleyen) taslak — soru cevabının altında hatırlatılır (K7). */
    private ?WhatsappPendingExpense $openDraft = null;

    /** Kayıt açmayan soru türleri: cevap verilir, bekleyen taslak varsa altına "onay bekliyor" eklenir. */
    private const ANSWER_KINDS = [ExpenseExtractor::KIND_BALANCE_QUERY, ExpenseExtractor::KIND_TOTALS_QUERY,
        ExpenseExtractor::KIND_STATEMENT, ExpenseExtractor::KIND_EXPENSE_SUMMARY, ExpenseExtractor::KIND_AGENDA,
        ExpenseExtractor::KIND_PARTY_LIST, ExpenseExtractor::KIND_MONTH_RESULT, ExpenseExtractor::KIND_STOCK_QUERY,
        ExpenseExtractor::KIND_MEMORY_LIST, ExpenseExtractor::KIND_RECURRING_LIST];

    /**
     * Akkuş 15.10 22:13: kasa onay beklerken "toplam ciro?" soruldu, cevap bekleyen 51.517'yi anmadı (dahil sandı).
     * Soru cevabının sonuna "⏳ … onay bekliyor — kaydedeyim mi?" eklenir; taslak açık kalır, *evet* ona gider.
     */
    /** Hızlı kayıt sırasında commitThenNext → saveDraft → fastSave döngüsü olmasın. */
    private bool $fastSaving = false;

    private const CONFIRM_FOOTER = 'Kaydedeyim mi? *evet*';

    /**
     * Hızlı kayıt (modül fast_save, kullanıcı 20.10: "başka soru yoksa özeti gösterip kaydetsin, yanlışlık varsa düzelt desin —
     * pratik tak tak"): kart onaya hazırsa ve kartta soru (❓), uyarı (⚠️), kişi teyidi, proje menüsü YOKSA beklemeden kaydeder.
     * Tutar sınırı yok (kullanıcı kararı); büyük tutarda "Yazıyla" satırı kartta kalır. Geri alma / düzeltme son kayıt üzerinde.
     */
    private function fastSave(string|array $out, string $phone): string|array
    {
        if (! is_string($out) || $this->fastSaving || ! config('modules.fast_save') || ! ChatAssistant::enabled()
            || in_array($this->inboundKind, self::ANSWER_KINDS, true) || ! str_ends_with(rtrim($out), self::CONFIRM_FOOTER)
            || str_contains($out, '❓') || str_contains($out, '⚠️')) {
            return $out;
        }
        $draft = WhatsappPendingExpense::where('phone', $phone)->where('status', 'awaiting_confirmation')->latest('updated_at')->first();
        if (! $draft || trim((string) $draft->summary) === '' || ! str_contains($out, trim((string) $draft->summary))
            || DraftGaps::reason($draft->extracted)['code'] !== DraftGaps::CONFIRM || ! empty($draft->extracted['party_check'])
            || ProjectChoice::menuShown($draft->extracted)) {
            return $out;
        }
        $this->fastSaving = true;
        try {
            $done = $this->commitThenNext($draft, $phone);
        } finally {
            $this->fastSaving = false;
        }
        if (! is_string($done) || $draft->fresh()?->status !== 'confirmed') {
            return $done;
        }
        $card = rtrim(mb_substr(rtrim($out), 0, mb_strlen(rtrim($out)) - mb_strlen(self::CONFIRM_FOOTER)));
        $rest = trim((string) preg_replace('/^✅[^\n]*\n?/u', '', $done)); // "✅ Kaydedildi. X: bakiye" kartta zaten var

        return "✅ *Kaydedildi*\n" . $card . ($rest !== '' ? "\n\n" . $rest : '') . "\n\nYanlışsa düzeltmeyi yaz ya da *geri al*.";
    }

    /** Bu numarada soru soran (cevap bekleyen) bir taslak var mı. */
    private function draftAsking(string $phone): bool
    {
        $draft = WhatsappPendingExpense::where('phone', $phone)->where('status', 'awaiting_confirmation')->latest('updated_at')->first();

        return $draft !== null && ! empty($draft->extracted['question']);
    }

    private function saveDraft(?WhatsappPendingExpense $pending, string $phone, array $data, ?string $mediaUrl = null): string|array
    {
        $out = $this->saveDraftInner($pending, $phone, $data, $mediaUrl);
        // Faz 4: çift kayıt / olağandışı tutar uyarısı — özete ⚠️ satırı (kayıt yine onayla; engellemez).
        if (is_string($out) && ! in_array($this->inboundKind, self::ANSWER_KINDS, true)
            && ($draftNow = WhatsappPendingExpense::where('phone', $phone)->where('status', 'awaiting_confirmation')->latest('updated_at')->first())
            && trim((string) $draftNow->summary) !== '' && str_contains($out, trim((string) $draftNow->summary))
            && ($warnings = DraftWarnings::for($draftNow->extracted))) {
            $out = self::insertBeforeFooter($out, implode("\n", $warnings));
        }
        $out = $this->fastSave($out, $phone);
        // Doğal konuşma (modül): taslak özetini AI insan gibi yazar; rakam güvenliği tutmazsa kalıp aynen gider.
        // Anlaşma kartı hariç: numaralı seçenek + "okuyamadım" sorusu yeniden yazılınca bozuluyordu (firma 7 22.10: "çek mi, yoksa geç mi diyeyim?").
        if (is_string($out) && NaturalVoice::enabled() && ! in_array($this->inboundKind, self::ANSWER_KINDS, true)
            && ($open = WhatsappPendingExpense::where('phone', $phone)->where('status', 'awaiting_confirmation')->latest('updated_at')->first())
            && ! AgreementDraft::handles($open->extracted) && ! AgreementDeliveryDraft::handles($open->extracted)
            && trim((string) $open->summary) !== '' && str_contains($out, trim((string) $open->summary))) {
            $out = NaturalVoice::render($out, $this->inboundBody, (float) ($open->extracted['amount'] ?? 0) ?: null);
        }
        // Soru cevabı: firmanın başka numarasında onay bekleyen kasa/satış/gider de söylenir (Akkuş 18.10 22:46).
        if (in_array($this->inboundKind, self::ANSWER_KINDS, true) && ($others = $this->otherPhonesPending($phone)) !== '') {
            $out = is_array($out) ? [$out[0] . $others, $out[1] ?? null] : $out . $others;
        }
        $draft = $this->openDraft?->fresh();
        if (! in_array($this->inboundKind, self::ANSWER_KINDS, true) || ! $draft || $draft->status !== 'awaiting_confirmation') {
            return $out;
        }
        $note = "\n\n⏳ Onay bekleyen: *" . DraftGaps::label($draft->extracted) . "* — kaydedeyim mi? *evet* / *iptal*";
        if (is_array($out)) {
            $out[0] = (is_string($out[0] ?? null) ? $out[0] : '') . $note;

            return $out;
        }

        return $out . $note;
    }

    private function saveDraftInner(?WhatsappPendingExpense $pending, string $phone, array $data, ?string $mediaUrl = null): string|array
    {
        // Kaydı başlatan mesaj — sıradaki kalem "evet" turunda işlenirken emniyetler bunu görür (review 8b).
        $data['source_text'] = $pending?->extracted['source_text'] ?? ($data['source_text'] ?? null) ?? ($this->inboundBody !== '' ? mb_substr($this->inboundBody, 0, 1000) : null);
        // Anlaşma (sözleşme fotoğrafı / "…'ye anlaştık"): kendi yolu — alış/ödeme emniyetleri (çek bölme, iş durumu) ona uygulanmaz.
        if (AgreementDraft::handles($data)) {
            return $this->saveAgreementDraft($pending, $phone, $data);
        }
        if (AgreementDeliveryDraft::handles($data)) {
            return $this->saveAgreementDeliveryDraft($pending, $phone, $data);
        }
        if (AgreementQuery::handles($data)) {
            return AgreementQuery::answer($data); // soru — taslak yok, bekleyen kayda dokunulmaz
        }
        // Peşin ödenip gidere yazılan firma (kişisiz) düzeltme turunda kaybolmasın — "ödenmedi" denirse cariye döner.
        if ($pending && ! empty($pending->extracted['supplier_name']) && empty($data['party_id']) && empty($data['party_name'])) {
            $data['supplier_name'] = $pending->extracted['supplier_name'];
        }
        $data = ExpenseExtractor::guardUndeliveredSale(ExpenseExtractor::fitToModules($data), $this->inboundBody);
        $data = ExpenseExtractor::guardDebtRepayment($data, $this->inboundBody);
        $data = ExpenseExtractor::guardSaleWithExpense($data, $this->inboundBody);
        $data = ExpenseExtractor::guardNightTill($data, $this->inboundBody);
        if ($pending === null) {
            $data = ExpenseExtractor::guardAssumedQuantity($data, $this->inboundBody); // yeni kayıtta; düzeltmede miktar önceki mesajda
        }
        $data = ExpenseExtractor::guardStatedQuantity($data, $this->inboundBody);
        // Kişi bizim çalışanımız mı — AI anlamdan karar verir (is_staff: personel, çoban, şoför, "maaşından"…). Düzeltme turunda korunur.
        if ($this->kind($data) === ExpenseExtractor::KIND_PAYMENT && (! empty($data['is_staff']) || ! empty($pending?->extracted['staff_hint']))) {
            $data['staff_hint'] = true;
        }
        $data = ExpenseExtractor::guardSelfParty($data, ExpenseContext::selfNames($phone), $this->inboundBody);
        if ($pending === null && ChatAssistant::enabled()) {
            $data = ExpenseExtractor::guardSwap($data, $this->inboundBody); // yeni kayıtta; düzeltmede AI kuralı yeterli
        }
        // Tutar ikinci mesajda gelince AI kişinin yazılışını tekrarlamıyor — "aynı kişi mi?" kaybolmasın.
        if ($pending && empty($data['party_said']) && ! empty($pending->extracted['party_said'])
            && ($data['party_id'] ?? null) === ($pending->extracted['party_id'] ?? null)) {
            $data['party_said'] = $pending->extracted['party_said'];
        }
        // Düzeltme turunda AI borç ödemesi / dekont ücretini tekrarlamayabilir — aynı kişi ve tür ise korunur.
        if ($pending && ($data['party_id'] ?? null) === ($pending->extracted['party_id'] ?? null)
            && $this->kind($data) === $this->kind($pending->extracted)) {
            $data['debt_repayment'] = ! empty($data['debt_repayment']) || ! empty($pending->extracted['debt_repayment']);
            $data['bank_fee'] = $data['bank_fee'] ?? ($pending->extracted['bank_fee'] ?? null);
            $data['from_document'] = ! empty($data['from_document']) || ! empty($pending->extracted['from_document']);
        }
        $data = $this->applyReceiptPayment($pending, $data);
        $data = $this->askCheckParty($data);
        if ($this->kind($data) === self::KIND_CHECK_CLEARED) {
            return $this->saveCheckClearedDraft($pending, $phone, $data);
        }
        // "📦 Ne aldın?" sorusunun cevabı ("20 çuval un") malın alındığını söyler — "mal elinize geçti mi?" diye ayrıca sorulmaz.
        if ($pending && ! empty($pending->extracted['supplier_name']) && $this->kind($data) === ExpenseExtractor::KIND_TRADE_PURCHASE) {
            $data = ['work_status' => Understanding::DONE, 'work_status_known' => true, 'question' => null] + $data;
        }
        // Önce anla: iş durumu sorulmuştu, AI cevabı "biliyorum"a çevirdi → cevap soruyu gerçekten cevaplıyor mu (ikinci göz)?
        // "asansör bedeli" cevap değildir — soru tekrar (tahmin yok). Yalnız kişiyle para/borç kaydında, soru-cevap anında.
        if ($pending && ($pending->extracted['work_status_known'] ?? null) === false && ! empty($pending->extracted['question'])
            && ! empty($data['work_status_known']) && Understanding::applies($data) && empty($pending->extracted['party_asked'])
            && empty($data['work_chosen']) // numaralı seçim kesin cevaptır — ikinci göz gerekmez
            && ! Understanding::answerSettles((string) ($pending->extracted['work_question'] ?? $pending->extracted['question']), $this->inboundBody)) {
            $data['work_status_known'] = false;
            $data['question'] = $pending->extracted['question'];
        }
        // "Borçtan mı, yeni iş mi?" bir kez sorulur — cevap turunda aynı kişiyse tekrar sorulmaz.
        if ($pending && ! empty($pending->extracted['debt_asked']) && ($data['party_id'] ?? null) === ($pending->extracted['party_id'] ?? null)) {
            $data['debt_asked'] = true;
        }
        // Adı birebir tutan cari varsa taslağa baştan bağlanır (kayıtta zaten ona yazılıyordu) — avans / bakiye görünsün.
        if (in_array($this->kind($data), self::PARTY_KINDS, true) && empty($data['party_id'])
            && ($same = DraftGaps::exactParty($data['party_name'] ?? null)) !== null) {
            $data['party_id'] = $same->id;
        }
        // Önce anla (16.10): AI'ın anladığı iş durumu kayda çevrilir ("başlamadı, anlaştık" → borç DEĞİL); çekler açıklamaya.
        $data = Understanding::apply($data);
        // Çek bölme hesabı kodda; toplam ödemeyle tutmazsa soru (firma 7, 20.10 Yusuf Dündar 11 çek).
        $data = CheckSplit::prepare($data, $pending?->extracted, $this->inboundBody);
        if (($checks = Understanding::checksNote($data)) && ! str_contains((string) ($data['description'] ?? ''), $checks)) {
            // Düzeltmede önceki çek notu açıklamada kalmışsa yenisiyle değişir (iki liste olmasın).
            $desc = preg_replace('/\s*—\s*\d+ çek: .*$/u', '', (string) ($data['description'] ?? ''));
            $data['description'] = trim($desc . ' — ' . $checks, ' —');
        }
        // Açıklama sütunu 255; kayıtta "Avans — " gibi önek de eklenir — taşarsa kayıt sessizce düşüyordu (firma 7, 20.10).
        if (mb_strlen((string) ($data['description'] ?? '')) > 200) {
            $data['description'] = mb_strimwidth((string) $data['description'], 0, 200, '…');
        }
        if ($pending && ! empty($pending->extracted['project_choice_done']) && $this->kind($data) === $this->kind($pending->extracted)
            && empty($data['project_id']) && empty($data['project_name'])) {
            $data['project_choice_done'] = true; // "0 = genel" seçimi düzeltme turunda kaybolmasın
            $data['project_id'] = $pending->extracted['project_id'] ?? null;
        }
        $data = ProjectChoice::prepare($data, $phone);
        $data = AdvanceOffset::apply($data, $this->inboundBody);
        $party = in_array($this->kind($data), self::PARTY_KINDS, true) ? $this->existingParty($data) : null;
        $differs = $party && PartyNameCheck::differs($party, $data['party_said'] ?? null)
            && ! MemoryDraft::isKnownAlias($party->id, $data['party_said'] ?? null); // öğretilmiş takma ad: sorma
        // Dekonttaki resmî ad carinin adını tamamen içeriyorsa ("Evrim Hazal Doğan" ⊇ "Hazal Doğan") aynı kişi: sorma,
        // yalnız bilgi olarak göster; kullanıcı "o değil" derse yeni cari (Taner, 13 Ekim).
        $documentSame = $differs && ! empty($data['from_document']) && PartyNameCheck::containedIn($party, (string) $data['party_said']);
        $data['party_check'] = $differs && ! $documentSame;
        $data['document_name'] = $documentSame ? $data['party_said'] : null;
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

        // Önce anla (16.10): kaydı değiştirecek bir şey tahminse taslak onaya HAZIRLANMAZ — soru gider, cevap bu taslağa işlenir.
        // Kişi sorulmuştu ve cevapta aynı cari kaldıysa ("evet Sinan Sele") kişi netleşti — tekrar sorulmaz.
        if ($pending && ! empty($data['party_id']) && (int) ($pending->extracted['party_asked'] ?? 0) === (int) $data['party_id']
            && ($data['party_certain'] ?? null) !== false) {
            $data['party_confirmed'] = true;
        }
        $data['party_confirmed'] = ! empty($data['party_confirmed']) || (! empty($pending?->extracted['party_confirmed'])
            && ($pending->extracted['party_id'] ?? null) === ($data['party_id'] ?? null));
        // Yazılandan farklı tutar ("65 tl" → 65.000) sessizce geçmesin (17.10, Emre).
        $data['scale_question'] = Understanding::amountScaleQuestion($data, $this->inboundBody);
        // Aynı soru az önce soruldu ve cevapta tutar değişmediyse ("65 TL doğru") kullanıcı teyit etti — tekrar sorulmaz.
        if ($data['scale_question'] !== null && $data['scale_question'] === ($pending?->extracted['scale_question'] ?? null)) {
            $data['scale_question'] = null;
        }
        // Çapraz kontrol (17.10): kaydın anlamı kullanıcının anlattığıyla çelişiyorsa ("borcum ödendi" ↔ "borçlusunuz") dur, sor.
        // Taslak başına bir kez; cevap gelince yeniden önerilen kayıt yine kontrol edilmez (döngü yok).
        // Kayıt DEĞİŞTİYSE (cevaptan sonra başka tür/tutar) yeniden süzülür; aynı kayıt bir daha sorulmaz; taslak başına en çok 2 soru.
        $crossSig = implode('|', [$data['kind'] ?? '', round((float) ($data['amount'] ?? 0)), $data['party_id'] ?? $data['party_name'] ?? '',
            $data['payment_purpose'] ?? '', var_export($data['paid'] ?? null, true)]);
        $crossDone = array_values(array_filter((array) ($pending?->extracted['cross_asked'] ?? []), 'is_string'));
        $data['cross_asked'] = $crossDone;
        $ask = $data['scale_question'] ?? CheckSplit::question($data) ?? Understanding::gap($data);
        // Taslak başına EN ÇOK BİR kez (19.10 Yeni Yapı: cevap sonrası kayıt biraz değişince yeniden süzülüp aynı soru tekrar soruldu).
        // Önceki ödemeden düşülen iş süzülmez: tek belirsizlik "ayrıca para verdin mi?" — kart bunu zaten söylüyor, ayrıca para
        // verdiyse kullanıcı yazar (firma 7, 20.10: "Asansörcü 250 binlik iş yaptı" → her seferinde "avanstan mı, ayrıca mı?").
        // Stok sorusu numarayla cevaplandıysa (1 20 çuval / 2 / 3 / 0) anlam kesin — ikinci göz yeniden sormaz (eval 21.10: "teslim
        // alırken mi ödediniz?" diye sordu, ardından gelen "evet" o soruya gitti, kayıt gidere döndü).
        if ($ask === null && $crossDone === [] && AdvanceOffset::used($data) <= 0 && empty($data['stock_choice_done'])
            && ($cross = Understanding::crossCheck($data, $this->inboundBody, $phone)) !== null) {
            $ask = $cross;
            $data['cross_asked'] = [...$crossDone, $crossSig];
            $data['cross_question'] = $cross;
        }
        // Açık borcu olmayan cariye ödeme: eski serbest "Ne için verdin?" menüsü yerine aynı numaralı soru (21.10).
        // Personel ödemesi eski soruda kalır ("Maaş mı, avans mı?").
        if ($ask === null && DraftGaps::needsPaymentChoice($data) && empty($data['work_chosen']) && empty($data['party_check'])
            && ! self::isStaff($data, DraftGaps::party($data)) && WorkChoice::applies(['work_status_known' => false] + $data)) {
            $data['work_status_known'] = false;
            $data['work_loan_option'] = true;
            $ask = 'Bu ödeme ne için: iş/mal geldi mi, önceden mi ödüyorsun, yoksa eski bir borç mu?';
        }
        // Aynı taslağın devamında (kişi cevabı, düzeltme) AI malın adını bazen düşürüyor → stok sorusu kayboluyordu (eval 21.10):
        // önceki taslağın malı ve "stok sorusu cevaplandı" bilgisi korunur. Yeni kayıtta taşınmaz.
        if ($pending && empty($data['is_new_entry'])) {
            $data['goods_name'] = ($data['goods_name'] ?? null) ?: ($pending->extracted['goods_name'] ?? null);
            $data['stock_choice_done'] = ! empty($data['stock_choice_done']) || ! empty($pending->extracted['stock_choice_done']);
        }
        // Kartı olmayan MAL (goods_name), adet yok: "Börek unu nerede? 1 geldi (kaç?) · 2 tedarikçide · [3 eski borç] · 0 stok tutmuyorum"
        // (kullanıcı 21.10 Akkuş: "burada stok sormalıydık"). Başka soru yoksa ya da tek soru iş durumuysa ("ne için?") onun YERİNE —
        // aynı şeyi soruyorlar, iki soru üst üste gelmesin.
        $data['stock_choice'] = null;
        $workAsk = $ask !== null && $ask !== ($data['scale_question'] ?? null) && $ask !== ($data['cross_question'] ?? null)
            && CheckSplit::question($data) === null && WorkChoice::applies($data);
        if (($ask === null || $workAsk) && StockChoice::applies($data)) {
            $ask = StockChoice::question($data);
            $data['stock_choice'] = true;
            $workAsk = false;
        }
        // İş durumu belirsizse serbest soru yerine numaralı seçenek (kullanıcı 20.10: "yazmak zor, 1 / 2 gibi olsun").
        $data['work_choice'] = null;
        if ($workAsk) {
            // AI'ın asıl sorusu ("Asansör takıldı mı, yoksa peşin mi?") saklanır: serbest cevabı ("Takılmadı") ikinci göz onunla
            // karşılaştırır — numaralı başlık ("— ne için?") cevabı anlamsız gösteriyordu (eval 21.10, soru döngüsü).
            $data['work_question'] = str_contains($ask, '*1* ') ? ($pending?->extracted['work_question'] ?? $ask) : $ask;
            $ask = WorkChoice::question($data);
            $data['work_choice'] = true;
        }
        if ($ask !== null) {
            $data['question'] = $ask;
            $data['party_asked'] = Understanding::partyUnclear($data) ? ($data['party_id'] ?? null) : null;
            $text = implode("\n", ['❓ ' . $ask, ...Understanding::checkWarnings($data)]);
            if ($pending) {
                $pending->update(['extracted' => $data, 'summary' => $text]);
            } else {
                WhatsappPendingExpense::create(['phone' => $phone, 'extracted' => $data, 'summary' => $text,
                    'media_url' => $mediaUrl, 'status' => 'awaiting_confirmation']);
            }

            return $text . "\nVazgeçmek için *iptal*.";
        }

        if ($this->kind($data) === ExpenseExtractor::KIND_HELP) {
            // Anlaşılamayan mesaj: zorla en yakın türe sokmak yerine yönlendir. Bekleyen taslağa dokunma.
            // Fotoğraf/belge geldiyse menü değil: ne olduğunu sor (21.10: sözleşme fotoğrafına yardım menüsü gitti).
            if (empty($data['reply']) && ! empty($data['from_document'])) {
                return "📄 Belgeyi aldım ama ne olduğunu tam anlayamadım. Ne yapayım?\n"
                    . 'Ör. "bu bir fiş, gider yaz", "bu sözleşme, anlaşma olarak kaydet", "bu dekont, ödeme".';
            }

            return $data['reply'] ?: $this->guideText();
        }
        // Personel bilgisi / maaş (her firmada).
        if (EmployeeDraft::handles($data)) {
            $data = EmployeeDraft::guardSalaryAmount($data, $this->inboundBody);

            return $this->saveEmployeeDraft($pending, $phone, $data);
        }
        // İşletme hafızası (Faz 2): hatırla / unut onaylı; "neleri hatırlıyorsun" hemen cevap.
        if (MemoryDraft::handles($data)) {
            if (MemoryDraft::isAnswer($data)) {
                return MemoryDraft::listText($phone);
            }
            $data['memory_source'] = $data['memory_source'] ?? $this->inboundBody;
            // Kendiliğinden iş vaat eden not ("her gün gider ekle") kaydedilmez — soru değil, açık bilgi.
            if (($data['kind'] ?? null) === ExpenseExtractor::KIND_REMEMBER && MemoryDraft::promisesAction((string) ($data['memory_fact'] ?? ''))) {
                return (string) MemoryDraft::blocker($data, $phone);
            }

            return $this->saveConfirmDraft($pending, $phone, $data, MemoryDraft::summary($data, $phone), MemoryDraft::blocker($data, $phone));
        }
        // Düzenli gider (ekmek her gün, kira her ay): onaylı kart; liste hemen cevap.
        if (RecurringDraft::handles($data)) {
            if (RecurringDraft::isAnswer($data)) {
                return RecurringDraft::listText();
            }
            $data['recurring_source'] = $data['recurring_source'] ?? $this->inboundBody;
            $blocker = RecurringDraft::blocker($data);

            return $this->saveConfirmDraft($pending, $phone, $data, $blocker === null ? RecurringDraft::summary($data) : '', $blocker);
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

        // Anlam cümlesi kartta GÖSTERİLMEZ (kullanıcı 20.10: "bu da gereksiz, direkt alıştan başlasın") — kart yönü ve tutarı
        // zaten açıkça yazıyor; anlam yine süzgeçte ve AI'ın anlamasında kullanılır.
        $summary = $this->buildSummary($data);
        if ($warnings = Understanding::checkWarnings($data)) {
            $summary .= "\n" . implode("\n", $warnings);
        }
        if ($projectLines = ProjectChoice::lines($data)) {
            $summary .= "\n" . implode("\n", $projectLines);
        }
        // Büyük tutar yazıyla: "96 tosun 17.017000 tl" tek "tamam"la 17 milyon girdi — rakam gözden kaçmasın (yalnız kartta;
        // kart yerine soru gidiyorsa gereksiz).
        if ((float) ($data['amount'] ?? 0) >= self::WORDS_FROM && ! str_starts_with($summary, '❓')) {
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
            // Kişi eksik ama AI başka bir şey soruyor ("Asansör takıldı mı?") → "Cari adını yaz" yanlış talimat (firma 7, 20.10).
            $this->missingParty($data) && ! empty($data['question']) => "\n\nVazgeçmek için *iptal*.",
            $this->missingParty($data) => "\n\nCari adını yaz, vazgeçmek için *iptal*.",
            DraftGaps::needsSaleOrPurchase($data) => "\n\n*sattım* ya da *aldım* yaz, vazgeçmek için *iptal*.",
            DraftGaps::needsPaidAnswer($data) => "\n\nVazgeçmek için *iptal*.",
            $this->needsDebtSide($data) => "\n\n*o bana* (o sana borçlu) ya da *ben ona* (sen ona borçlusun) yaz, vazgeçmek için *iptal*.",
            ! empty($data['settles_expense_id']) => "\n\n✅ Onaylamak için *evet*. Ayrı (yeni) bir gider ise *yeni* yaz, vazgeçmek için *iptal*.",
            // Proje menüsü sorudur: numara seçince kaydeder — ayrıca "Kaydedeyim mi?" fazlalık (kullanıcı 20.10).
            ChatAssistant::enabled() && ProjectChoice::menuShown($data) => '',
            ChatAssistant::enabled() => "\n\n" . self::CONFIRM_FOOTER,
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
     * K2 (Akkuş 15.10: "105.000 çek ödendi" gider sayıldı, ay sonucu eksiye düştü): çek modülü kapalı firmada carisiz
     * "çek ödendi/ödedim" bir borcun ödemesidir → kime yazıldığı sorulur (ödeme). Masrafsa kullanıcı *gider* yazar.
     */
    private function askCheckParty(array $data): array
    {
        // Modül açık/kapalı fark etmez (17.10): kişisiz "çek ödendi" önce bekleyen VERİLMİŞ çekle eşleşir (vadesi gelen çek
        // bankadan ödendi — para çek verildiği gün düşülmüştü, gider/yeni ödeme YAZILMAZ); eşleşme yoksa "kime yazılmıştı?".
        if ($this->kind($data) !== ExpenseExtractor::KIND_EXPENSE || ! empty($data['check_answered'])
            || ! empty($data['party_id']) || ! empty($data['party_name'])
            || ! in_array(ExpenseExtractor::KIND_PAYMENT, ExpenseExtractor::allowedKinds(), true)
            || (float) ($data['amount'] ?? 0) <= 0) {
            return $data;
        }
        $t = mb_strtolower(strtr($this->inboundBody . ' ' . ($data['description'] ?? ''), ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
        if (! preg_match('/(^|[^\p{L}])[çc]ek(i|in|ini)?([^\p{L}]|$)/u', $t)) {
            return $data;
        }
        if (empty($data['skip_check_match']) && ($check = \App\Support\CheckReminders::matchDue((float) $data['amount'])) !== null) {
            return ['kind' => self::KIND_CHECK_CLEARED, 'check_id' => $check->id, 'amount' => (float) $check->amount,
                'description' => 'Çek ödendi', 'check_original' => $data, 'question' => null, 'confidence' => 'high'];
        }

        return array_merge($data, ['kind' => ExpenseExtractor::KIND_PAYMENT, 'check_payment' => true, 'category_id' => null,
            'category_name' => null, 'settles_expense_id' => null,
            'question' => 'Bu çek kime yazılmıştı? Adını yaz (borcun ondan düşsün). Masrafsa *gider* yaz.']);
    }

    /**
     * Fiş (belge) ödeme kuralları (Ferhat, 15 Ekim):
     *  - gider AÇIK firmada fişe "ödemedim" → gider değil, fişteki satıcının carisine veresiye alış (gider↔cari ayrılıyor);
     *  - gider KAPALI firmada fiş = satıcıdan alış; ödendi mi söylenmediyse o carinin son alışı gibi (veresiye/peşin),
     *    hiç alış yoksa sorulur ("Ödedin mi?").
     */
    private function applyReceiptPayment(?WhatsappPendingExpense $pending, array $data): array
    {
        if ($pending && empty($data['seller_name']) && ! empty($pending->extracted['seller_name'])) {
            $data['seller_name'] = $pending->extracted['seller_name'];
        }
        if (empty($data['from_document'])) {
            return $data;
        }
        $kind = $this->kind($data);
        if ($kind === ExpenseExtractor::KIND_EXPENSE && ($data['paid'] ?? null) === false && ! empty($data['seller_name'])
            && config('modules.cari_supplier')) {
            $data['kind'] = ExpenseExtractor::KIND_PURCHASE;
            $data['party_id'] = Party::where('name', $data['seller_name'])->value('id');
            $data['party_name'] = $data['party_id'] ? null : $data['seller_name'];
            $data['settles_expense_id'] = null;

            return $data;
        }
        if ($kind === ExpenseExtractor::KIND_PURCHASE && empty($data['party_id']) && ! empty($data['party_name'])
            && ($existing = Party::where('name', $data['party_name'])->value('id'))) {
            $data['party_id'] = $existing; // aynı adlı cari zaten var — yeni açma, geçmişi buradan
            $data['party_name'] = null;
        }
        if ($kind === ExpenseExtractor::KIND_PURCHASE && ! config('modules.expenses') && ($data['paid'] ?? null) === null) {
            $habit = DraftGaps::purchaseHabit($this->existingParty($data));
            if ($habit !== null) {
                $data['paid'] = $habit;
                $data['paid_from_habit'] = true;
            }
        }

        return $data;
    }

    /**
     * Onaylanan taslaktan kaydı oluştur (türe göre Expense veya cari hareketi).
     */
    private function commit(WhatsappPendingExpense $pending): string
    {
        if (($pending->extracted['kind'] ?? null) === self::KIND_UNDO) {
            return $this->commitUndo($pending);
        }
        // Stok sorusu atlanamaz (hangi yoldan "evet" gelirse gelsin): cevaplanmadan kayıt yok — 0 "stok tutmuyorum" her zaman var.
        if (StockChoice::shown($pending->extracted)) {
            return '❓ ' . StockChoice::question($pending->extracted) . "\nVazgeçmek için *iptal*.";
        }

        // Bu onayla oluşan gider / cari satırları taslağa yazılır → "son kaydı sil" tam olarak bunları siler.
        self::listenForCreatedRecords();
        self::$recording = [];
        try {
            // Tek parça (review A): alış + ödeme + banka masrafı + taslak durumu birlikte yazılır ya da hiçbiri.
            $out = DB::transaction(fn () => $this->commitDraft($pending));
        } finally {
            $created = self::$recording;
            self::$recording = null;
        }
        if ($created !== [] && $pending->fresh()?->status === 'confirmed') {
            $pending->update(['extracted' => ['created_records' => $created] + $pending->extracted]);
        }
        $this->closeReviewFor($pending);

        return $out;
    }

    private ?\App\Models\RecordReview $turnReview = null;

    private int $pendingBefore = 0;

    /** Kayıt kontrolüne cevap turunda açılan taslak(lar) o kontrole bağlanır — onaylanınca kontrol "Düzeltildi" olur. */
    private function tagReviewDrafts(string $phone): void
    {
        if (! $this->turnReview) {
            return;
        }
        foreach (WhatsappPendingExpense::where('phone', $phone)->where('id', '>', $this->pendingBefore)->where('status', 'awaiting_confirmation')->get() as $p) {
            $p->update(['extracted' => ['review_id' => $this->turnReview->id] + $p->extracted]);
        }
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
        // İade: başlık silinince stok ve cari satırı birlikte gider (cascade).
        SaleReturn::created(function (SaleReturn $r) {
            if (self::$recording !== null) {
                self::$recording[] = ['sale_return', $r->id];
            }
        });
        PurchaseReturn::created(function (PurchaseReturn $r) {
            if (self::$recording !== null) {
                self::$recording[] = ['purchase_return', $r->id];
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
    private function undoDraft(?WhatsappPendingExpense $pending, string $phone, ?array $replacement = null, ?array $find = null): string
    {
        if ($find) {
            return $this->undoFound($pending, $phone, $replacement, $find);
        }
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
        // Tek satır (kullanıcı 20.10 "cevaplar çok uzun"; hızlı kayıtta kart az önce gitti — tamamı tekrar gönderilmez).
        $summary = ($isSettle ? "↩️ *Şu ödemeyi geri alayım mı?* (borç tekrar açılır)\n" : "🗑️ *Geri alayım mı?*\n") . DraftGaps::label((array) $last->extracted);
        if ($replacement !== null) {
            $summary .= "\n\n✏️ *Yerine yazacağım:*\n" . $this->shortLine($replacement);
        }
        WhatsappPendingExpense::create([
            'phone' => $phone,
            'extracted' => ['kind' => self::KIND_UNDO, 'undo_of' => $last->id, 'targets' => $targets, 'replacement' => $replacement],
            'summary' => $summary,
            'status' => 'awaiting_confirmation',
        ]);

        return $summary . ($replacement !== null ? "\n\nDüzelteyim mi? *evet* / *iptal*" : "\n*evet* / *iptal*");
    }

    /**
     * K3 (Akkuş 15.10: "kategorisiz olan yanlış, 25.113, 14.10.2026" 4 kez denendi, bulunamadı): son 60 günde tutar /
     * tarih / açıklama kelimesiyle gider, cari hareketi ve satış aranır. Tek eşleşme → göster + tek onayla sil/düzelt
     * (mevcut geri alma akışı); birden çok → kısa liste, daha belirgin yazması istenir; yoksa söylenir.
     */
    private function undoFound(?WhatsappPendingExpense $pending, string $phone, ?array $replacement, array $find): string
    {
        $since = now()->subDays(60)->toDateString();
        $amount = $find['amount'] ?? null;
        $date = $find['date'] ?? null;
        $text = $find['text'] ?? null;
        // Kişiyle (Oktay 18.10: "Akkuş fırına giden son satışı iptal et" → bulunamadı): kişi adı ya da kelime bir cariye uyuyorsa
        // o carinin kayıtları aranır; kelime açıklamada aranmaz.
        $like = (new Party)->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $partyIds = [];
        foreach (array_filter([$find['party'] ?? null, $text]) as $name) {
            $ids = Party::where('name', $like, '%' . $name . '%')->pluck('id')->all();
            if ($ids !== []) {
                $partyIds = $ids;
                $text = $text === $name ? null : $text;
                break;
            }
        }
        if (! empty($find['party']) && $partyIds === []) {
            return 'Carilerde "' . $find['party'] . '" adında biri yok. Adını listedeki gibi yazar mısın? (*cariler* yazarsan listeyi gösteririm)';
        }
        $filter = function ($q, string $amountCol, string $dateCol, ?string $textCol) use ($amount, $date, $text, $since, $partyIds) {
            $q->where($dateCol, '>=', $since);
            if ($amount) {
                $q->whereBetween($amountCol, [$amount - 0.01, $amount + 0.01]);
            }
            if ($date) {
                $q->whereDate($dateCol, $date);
            }
            if ($text) {
                $q->where($textCol, 'like', '%' . $text . '%');
            }
            if ($partyIds !== []) {
                $q->whereIn('party_id', $partyIds);
            }

            return $q->orderByDesc($dateCol)->orderByDesc('id')->limit(6)->get();
        };
        $day = fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d.m');
        $hits = collect()
            ->merge($filter(Expense::query(), 'amount', 'expense_date', 'description')
                ->map(fn ($e) => [['expense', $e->id], '📝 Gider — ' . $e->expense_date->format('d.m') . ' — ' . Money::format((float) $e->amount) . ' ₺ — ' . ($e->description ?: '—'), $e->expense_date]))
            // Belgeli satış/alışın carideki aynası ayrıca listelenmez — belgeyle birlikte gider.
            ->merge($filter(PartyLedgerEntry::with('party')->whereNull('sale_id')->whereNull('purchase_id')->whereNull('sale_return_id')->whereNull('purchase_return_id'), 'amount', 'entry_date', 'description')
                ->map(fn ($l) => [['ledger', $l->id], '📒 ' . (PartyLedgerEntry::TYPES[$l->type]['label'] ?? $l->type) . ' — ' . ($l->party?->name ?? '—') . ' — '
                    . $l->entry_date->format('d.m') . ' — ' . Money::format((float) $l->amount) . ' ₺ — ' . ($l->description ?: '—'), $l->entry_date]))
            // Satış / alış belgesi: silinince bağlı stok hareketleri ve cari satırı da gider (veritabanı cascade; panelin "geri al"ı gibi).
            // İadesi olan satış silinmez (iade ona bağlı).
            ->merge($filter(Sale::with('party')->whereDoesntHave('saleReturns'), 'total_amount', 'sale_date', 'notes')
                ->map(fn ($x) => [['sale', $x->id], '🧾 Satış — ' . ($x->party ? $x->party->name . ' — ' : '') . $day($x->sale_date) . ' — '
                    . Money::format((float) $x->total_amount) . ' ₺ — ' . ($x->notes ?: '—'), $x->sale_date]))
            ->merge($filter(Purchase::with('party')->whereDoesntHave('purchaseReturns'), 'total_amount', 'purchase_date', 'notes')
                ->map(fn ($x) => [['purchase', $x->id], '🛒 Alış — ' . ($x->party ? $x->party->name . ' — ' : '') . $day($x->purchase_date) . ' — '
                    . Money::format((float) $x->total_amount) . ' ₺ — ' . ($x->notes ?: '—'), $x->purchase_date]));
        // "son satışı / en son" → en yeni olan (aynı kişinin birden çok kaydı varken liste yerine).
        if (! empty($find['latest']) && $hits->count() > 1) {
            $hits = $hits->sortByDesc(fn ($h) => \Illuminate\Support\Carbon::parse($h[2])->format('Y-m-d') . sprintf('%012d', $h[0][1]))->take(1)->values();
        }

        // Mesajdaki sayı yalnız bir adayda geçiyorsa o (Oktay: "50 litre süt düş" → 30 ve 50 litrelik iki satıştan 50'lik).
        if ($hits->count() > 1 && preg_match_all('/\d+(?:[.,]\d+)*/u', (string) ($find['message'] ?? ''), $m)) {
            $nums = array_unique($m[0]);
            $narrowed = $hits->filter(fn ($h) => collect($nums)->contains(fn ($n) => preg_match('/(?<![\d.,])' . preg_quote($n, '/') . '(?![\d]|[.,]\d)/u', $h[1])))->values();
            if ($narrowed->count() === 1) {
                $hits = $narrowed;
            }
        }
        if ($hits->isEmpty()) {
            return 'Tarif ettiğin kaydı bulamadım (son 60 gün). Tutarı ya da tarihi yazar mısın? (ör. "14 Ekim 25.113 gider")';
        }
        if ($hits->count() > 1) {
            return "Birden çok kayıt buldum:\n" . $hits->take(5)->map(fn ($h, $i) => ($i + 1) . ') ' . $h[1])->implode("\n")
                . "\nHangisi olduğunu tarih ya da açıklamayla yaz (ör. \"14 Ekim'deki 25.113\").";
        }

        [$target, $line] = $hits->first();
        if ($replacement !== null) {
            $replacement = ExpenseExtractor::fitToModules($replacement);
            if ($this->kind($replacement) === ExpenseExtractor::KIND_HELP || (float) ($replacement['amount'] ?? 0) <= 0) {
                $replacement = null;
            }
        }
        $pending?->update(['status' => 'superseded']);
        $summary = "🗑️ *Şu kaydı sileceğim:*\n" . $line;
        if ($replacement !== null) {
            $summary .= "\n\n✏️ *Yerine yazacağım:*\n" . $this->shortLine($replacement);
        }
        WhatsappPendingExpense::create([
            'phone' => $phone,
            'extracted' => ['kind' => self::KIND_UNDO, 'undo_of' => null, 'targets' => [$target], 'replacement' => $replacement],
            'summary' => $summary,
            'status' => 'awaiting_confirmation',
        ]);

        return $summary . ($replacement !== null ? "\n\nDüzelteyim mi? *evet* / *iptal*" : "\n\nSileyim mi? *evet* / *iptal*");
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
            'sale_return' => SaleReturn::find($target[1] ?? 0),
            'purchase_return' => PurchaseReturn::find($target[1] ?? 0),
            'stock' => StockMovement::find($target[1] ?? 0),
            default => null,
        };
    }

    /** Kayıt kontrolüne bağlı taslak onaylandı → kontrol "Düzeltildi" (müşteri düzeltti). */
    private function closeReviewFor(WhatsappPendingExpense $pending): void
    {
        $id = $pending->extracted['review_id'] ?? null;
        if ($id && $pending->fresh()?->status === 'confirmed' && ($review = \App\Models\RecordReview::find($id))) {
            $review->closeBy('resolved', 'Müşteri düzeltti: ' . mb_substr(preg_replace('/\s+/u', ' ', strip_tags((string) $pending->summary)), 0, 400));
        }
    }

    private function commitUndo(WhatsappPendingExpense $pending): string
    {
        try {
            $out = DB::transaction(fn () => $this->commitUndoInner($pending));
            $this->closeReviewFor($pending);

            return $out;
        } catch (UndoAborted $e) {
            // Doğrusu yazılamadı (eksik bilgi) → silme de geri alındı; eski kayıt yerinde (review B).
            return "✋ Düzeltmeyi yapamadım, eski kayıt yerinde duruyor.\n" . $e->getMessage()
                . "\nDoğru hâlini eksiksiz yazarsan düzeltirim.";
        }
    }

    private function commitUndoInner(WhatsappPendingExpense $pending): string
    {
        $partyIds = [];
        $deleted = 0;
        DB::transaction(function () use ($pending, &$partyIds, &$deleted) {
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

            $out = $this->commit($new);
            if ($new->fresh()?->status !== 'confirmed') {
                throw new UndoAborted($out);
            }

            return "✏️ Düzeltildi.\n" . $out;
        }
        $party = Party::find(collect($partyIds)->filter()->last());

        $settled = collect((array) ($pending->extracted['targets'] ?? []))->contains(fn ($t) => ($t[0] ?? null) === 'settle');

        return ($settled ? '↩️ Ödeme geri alındı, borç tekrar açık.' : '🗑️ Silindi.') . ($party ? ' ' . $this->balanceLine($party->name, PartyStatement::build($party)['balance']) : '')
            . "\nDoğrusunu yazarsan hemen kaydederim.";
    }

    private function commitDraft(WhatsappPendingExpense $pending): string
    {
        // Çift kayıt emniyeti (review 2): taslak hâlâ onay bekliyor mu — satır kilitli okunur (commit() işlemi içinde). Mesaj kilidi
        // dolsa ya da aynı onay iki kez gelse bile ikinci "evet" yeni kayıt açmaz. Mesaj tekrarından (SID) bağımsız.
        $locked = WhatsappPendingExpense::whereKey($pending->id)->lockForUpdate()->first();
        if (! $locked || $locked->status !== 'awaiting_confirmation') {
            return $locked?->status === 'confirmed' ? '✅ Bu kayıt zaten kaydedildi.' : 'Bu kayıt artık açık değil. Yeni bir işlem yazabilirsin.';
        }
        if (($pending->extracted['kind'] ?? null) === self::KIND_CHECK_CLEARED) {
            return $this->commitCheckCleared($pending);
        }
        if (($pending->extracted['kind'] ?? null) === ReturnDraft::KIND) {
            return ReturnDraft::commit($pending);
        }
        if (($pending->extracted['kind'] ?? null) === BundleDraft::KIND) {
            return BundleDraft::commit($pending);
        }
        if (AgreementDeliveryDraft::handles($pending->extracted)) {
            if ($question = AgreementDeliveryDraft::question($pending->extracted)) {
                return '❓ ' . $question . "\n\nVazgeçmek için *iptal*.";
            }
            [$text] = AgreementDeliveryDraft::commit($pending->extracted);
            $this->touchedPartyId = $this->committedPartyId = (int) $pending->extracted['party_id'];
            $pending->update(['status' => 'confirmed']);

            return $text;
        }
        if (AgreementDraft::handles($pending->extracted)) {
            // "Belgede X yazıyor — carilerindeki Y mi?" → *evet*: eşleşme teyit, sıradaki soru / kart.
            if (AgreementDraft::partyUnclear($pending->extracted)) {
                return $this->saveAgreementDraft($pending, $pending->phone, ['party_confirmed' => true] + $pending->extracted);
            }
            // "Kalemleri topladım X, belgede Y — kalemler doğruysa *evet*" → toplam teyit.
            if (AgreementDraft::totalUnclear($pending->extracted)) {
                return $this->saveAgreementDraft($pending, $pending->phone, ['total_ok' => true] + $pending->extracted);
            }
            if ($question = AgreementDraft::question($pending->extracted)) {
                return '❓ ' . $question . "\n\nVazgeçmek için *iptal*.";
            }
            [$text, $agreement] = AgreementDraft::commit($pending->extracted, $pending->phone);
            $this->touchedPartyId = $agreement->party_id;
            $this->committedPartyId = $agreement->party_id;
            $pending->update(['status' => 'confirmed']);
            // Belgede karşı tarafın telefonu/adresi varsa ve kartta yoksa sor (kullanıcı 22.10: "sen yazma, asistan yapayım mı desin").
            // Kartta "(yeni)" diye açılan tek proje zaten açıldı — tekrar sorulmaz.
            $projects = array_filter($pending->extracted['new_projects'] ?? [],
                fn ($p) => mb_strtolower(trim($p)) !== mb_strtolower(trim((string) ($pending->extracted['project_name'] ?? ''))));
            if ($info = PartyInfoDraft::offer($agreement->party, $pending->extracted['party_phone'] ?? null, $pending->extracted['party_address'] ?? null,
                $projects)) {
                $summary = PartyInfoDraft::summary($info);
                WhatsappPendingExpense::create(['phone' => $pending->phone, 'extracted' => $info, 'summary' => $summary, 'status' => 'awaiting_confirmation']);
                $text .= "\n\n" . $summary;
            }

            return $text;
        }
        // Taslak ile onay arasında silinen proje/cari/kategori (review H) — kayıt hatası yerine boşalır, eksikse sorulur.
        $d = ExpenseExtractor::fitToModules(ExpenseExtractor::dropUnknownIds($pending->extracted));
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

        if (MemoryDraft::handles($d) && ! MemoryDraft::isAnswer($d)) {
            if ($question = MemoryDraft::blocker($d, $pending->phone)) {
                return '❓ ' . $question;
            }
            [$text] = MemoryDraft::commit($d, $pending->phone);
            $pending->update(['status' => 'confirmed']);

            return $text;
        }

        if (RecurringDraft::handles($d) && ! RecurringDraft::isAnswer($d)) {
            if ($question = RecurringDraft::blocker($d)) {
                return '❓ ' . $question;
            }
            [$text] = RecurringDraft::commit($d, $pending->phone);
            $pending->update(['status' => 'confirmed']);

            return $text;
        }

        if (EmployeeDraft::handles($d)) {
            if ($question = EmployeeDraft::blocker($d)) {
                return '❓ ' . $question;
            }
            [$text, $partyId] = EmployeeDraft::commit($d, $pending->phone);
            $this->touchedPartyId = $partyId ?: $this->touchedPartyId;
            $this->committedPartyId = $partyId;
            $pending->update(['status' => 'confirmed']);

            return $text;
        }

        if (TradeDraft::handles($d) && ! in_array($kind, TradeDraft::ANSWER_KINDS, true)) {
            if ($question = TradeDraft::blocker($d) ?? ProjectChoice::blocker($d)) {
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
        if (! empty($d['staff_hint']) && ! $party->is_employee) {
            $party->update(['is_employee' => true]); // "personelim Fatih'e avans" (Serkan 16.10)
        }
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

        // Kayıtlı olmayan eski borcun ödemesi (Taner/Hazal): önce eski borç, sonra ödeme — bakiye 0, ekstrede geçmiş görünür.
        if (($missing = DraftGaps::unrecordedDebt($d)) > 0) {
            PartyLedgerEntry::create([
                'party_id' => $party->id,
                'entry_date' => $d['date'] ?? now()->format('Y-m-d'),
                'type' => PartyLedgerEntry::TYPE_PURCHASE,
                'description' => 'Borç kaydı — ödemeden önce kayıtlı değildi',
                'amount' => Money::store($missing),
                'notes' => 'WhatsApp üzerinden girildi.',
            ]);
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

        // Çekle ödeme (Faz F): her çek ödeme satırına bağlı "verildi" — cari Çekler bölümünde, vadeden önce hatırlatılır.
        $checksSaved = in_array($kind, [ExpenseExtractor::KIND_PAYMENT, ExpenseExtractor::KIND_PURCHASE], true)
            ? IssuedChecks::store($d, $party, $projectId) : 0;

        // Dekonttaki banka masrafı (FAST + BSMV): gider açıksa ayrı gider; kapalıysa özette söylendi, yazılmaz.
        if (($fee = (float) ($d['bank_fee'] ?? 0)) > 0 && config('modules.expenses')) {
            Expense::create([
                'expense_category_id' => ExpenseCategory::firstOrCreate(['name' => 'Banka masrafı'])->id,
                'expense_date' => $d['date'] ?? now()->format('Y-m-d'),
                'amount' => Money::store($fee),
                'payment_status' => 'paid',
                'description' => 'Banka masrafı (FAST + BSMV) — ' . $party->name,
                'notes' => 'WhatsApp üzerinden girildi (dekont).',
            ]);
        }

        $pending->update(['status' => 'confirmed']);

        // Personel ama maaşı bilinmiyor → hemen sor (Serkan 16.10): cevabı ("45 bin, ayın 5'i") karta yazılır, maaş günü
        // hatırlatması kurulur; sonra "maaşından ne kadar kaldı" hesaplanabilir.
        $ask = $party->is_employee && ! $party->salary_amount
            ? "\n\n👷 " . $party->name . " personel olarak kayıtlı. Aylık maaşı ne kadar, ayın kaçında ödüyorsun? (ör. *45 bin, ayın 5'i*) — avansları maaşından düşer, maaş gününde hatırlatırım."
            : '';

        $balance = PartyStatement::build($party)['balance'];
        // Personelde "sana borcu" değil, avans dili: kaç avans aldı, maaşından ne kaldı.
        $line = $party->is_employee && $balance > 0.009
            ? $party->name . ' toplam ' . Money::format($balance) . ' ₺ avans aldı — maaşından düşülecek'
                . ($party->salary_amount ? ' (maaşından kalan ' . Money::format(max(0, (float) $party->salary_amount - $balance)) . ' ₺).' : '.')
            : $this->balanceLine($party->name, $balance);

        $checksLine = $checksSaved > 0 ? "\n🧾 {$checksSaved} çek kaydedildi — vadesinden önce hatırlatırım." : '';

        return '✅ Kaydedildi. ' . $line . $checksLine . $ask;
    }

    /** Kişisiz "çek ödendi" bekleyen verilmiş çekle eşleşti: işaretleme teyidi (gider/ödeme yazılmaz). */
    private function saveCheckClearedDraft(?WhatsappPendingExpense $pending, string $phone, array $data): string
    {
        $check = \App\Models\Check::with('party')->find($data['check_id']);
        $summary = '🧾 ' . ($check?->party?->name ?? 'Çek') . ' — ' . Money::format((float) $data['amount']) . ' ₺ çek'
            . ($check?->due_date ? ' (vade ' . $check->due_date->format('d.m.Y') . ')' : '') . ($check?->check_number ? ' · no ' . $check->check_number : '')
            . "\nBu çek *ödendi* olarak işaretlensin mi? Çek verildiği gün ödeme olarak yazılmıştı; gider ya da yeni ödeme yazılmaz.";
        if ($pending) {
            $pending->update(['extracted' => $data, 'summary' => $summary]);
        } else {
            WhatsappPendingExpense::create(['phone' => $phone, 'extracted' => $data, 'summary' => $summary, 'status' => 'awaiting_confirmation']);
        }
        $this->inboundKind = 'check_cleared';

        return $summary . "\n\n*evet* / başka bir çekse *başka* yaz.";
    }

    /** Çek ödendi: sözleşmeye bağlıysa sözleşme ödemesi "ödendi" (çek oradan senkron), değilse çekin kendisi. */
    private function commitCheckCleared(WhatsappPendingExpense $pending): string
    {
        $check = \App\Models\Check::with(['party', 'contractPayment'])->find($pending->extracted['check_id'] ?? 0);
        if (! $check || ! in_array($check->status, ['issued', 'portfolio'], true)) {
            $pending->update(['status' => 'cancelled']);

            return 'Bu çek artık bekleyenler arasında değil (zaten işaretlenmiş olabilir).';
        }
        if ($check->contractPayment) {
            $check->contractPayment->update(['status' => 'paid']);
        }
        if ($check->fresh()->status !== 'paid') {
            $check->update(['status' => 'paid']);
        }
        $pending->update(['status' => 'confirmed']);

        return '✅ ' . ($check->party?->name ?? 'Çek') . ' — ' . Money::format((float) $check->amount) . ' ₺ çek *ödendi* olarak işaretlendi.';
    }

    /** Basit onaylı taslak (hafıza): özet + teyit; eksik bilgi varsa yalnız soru. */
    private function saveConfirmDraft(?WhatsappPendingExpense $pending, string $phone, array $data, string $summary, ?string $question): string
    {
        $text = $question !== null ? '❓ ' . $question : $summary;
        $footer = $question !== null ? "\n\nCevabını yaz, vazgeçmek için *iptal*." : "\n\n*evet* / *iptal*";
        if ($question === null) {
            if ($pending) {
                $pending->update(['extracted' => $data, 'summary' => $summary]);
            } else {
                WhatsappPendingExpense::create(['phone' => $phone, 'extracted' => $data, 'summary' => $summary, 'status' => 'awaiting_confirmation']);
            }
        }

        return $text . $footer;
    }

    /**
     * Anlaşma taslağı: kart + (varsa) soru. Soru varken de saklanır — cevap ("çimento torba", "geç") aynı taslağa bağlansın.
     * Düzeltme turunda AI kalemleri/ödemeleri tekrarlamazsa öncekiler korunur (12 kalemli belge her turda yeniden yazılmasın).
     */
    private function saveAgreementDraft(?WhatsappPendingExpense $pending, string $phone, array $data): string
    {
        if ($pending && AgreementDraft::handles($pending->extracted)) {
            $prev = $pending->extracted;
            $data['agreement_items'] = AgreementDraft::mergeItems($prev['agreement_items'] ?? [], $data['agreement_items'] ?? []);
            if (empty($data['agreement_payments']) && ! empty($prev['agreement_payments'])) {
                $data['agreement_payments'] = $prev['agreement_payments'];
            }
            // Kişi: cevapta yeni ad söylendiyse o ("Hüseyin Yılmaz yeni cari"); hiç kişi yoksa öncekisi.
            if (empty($data['party_id']) && trim((string) ($data['party_name'] ?? '')) === '') {
                $data['party_id'] = $prev['party_id'] ?? null;
                $data['party_name'] = $prev['party_name'] ?? null;
                $data['party_said'] = $data['party_said'] ?? $prev['party_said'] ?? null;
                $data['party_certain'] = $data['party_certain'] ?? $prev['party_certain'] ?? null;
            }
            // Bölme seçeneği bekliyordu, AI cevapta planı tekrarlamadı → plan kalır (tutar/numara cevabı).
            if (empty($data['check_plan']) && empty($data['agreement_payments']) && in_array($prev['check_choice'] ?? null, ['split', 'amount'], true)) {
                $data['check_plan'] = $prev['check_plan'];
            }
            $data['party_confirmed'] = ! empty($data['party_confirmed'])
                || (! empty($prev['party_confirmed']) && ($prev['party_id'] ?? null) === ($data['party_id'] ?? null));
            $data['has_payments'] = ! empty($data['has_payments']) || ! empty($prev['has_payments']);
            $data['document_total'] = $data['document_total'] ?? $prev['document_total'] ?? null;
            foreach (['pages', 'agreement_notes', 'party_phone', 'party_address'] as $k) {
                $data[$k] = $data[$k] ?? $prev[$k] ?? null; // cevapta AI tekrarlamazsa kaybolmasın
            }
            $data['total_ok'] = ! empty($data['total_ok']) || ! empty($prev['total_ok']);
            $data['payments_skipped'] = ! empty($data['payments_skipped']) || ! empty($prev['payments_skipped']);
            $data['from_document'] = ! empty($data['from_document']) || ! empty($prev['from_document']);
            // AI cevapta projeyi tekrarlamazsa önceki (belgeden / menüden) kalır; "0 = genel" seçimi de kaybolmaz.
            if (empty($data['project_id']) && empty($data['project_name'])) {
                $data['project_id'] = $prev['project_id'] ?? null;
                $data['project_choice_done'] = ! empty($prev['project_choice_done']);
            }
        }
        $data = AgreementDraft::applyCheckPlan($data);
        $question = AgreementDraft::question($data);
        $data['question'] = $question;
        // Proje menüsü yalnız sorular bitince (numara cevabı soruyla karışmasın).
        $data = $question === null ? ProjectChoice::prepare($data, $phone)
            : array_merge($data, ['project_suggested' => false, 'project_needed' => false]);
        $card = AgreementDraft::summary($data) . (($pl = ProjectChoice::lines($data)) ? "\n" . implode("\n", $pl) : '');
        // Kişi bilinmiyorsa kart anlamsız — yalnız soru.
        $summary = empty($data['party_id']) && trim((string) ($data['party_name'] ?? '')) === '' ? '❓ ' . $question
            : $card . ($question !== null ? "\n\n❓ " . $question : '');
        $footer = match (true) {
            $question !== null => "\n\nVazgeçmek için *iptal*.",
            ProjectChoice::menuShown($data) => '',
            default => "\n\nKaydedeyim mi? *evet* / *iptal*",
        };

        if ($pending) {
            $pending->update(['extracted' => $data, 'summary' => $summary]);
        } else {
            WhatsappPendingExpense::create(['phone' => $phone, 'extracted' => $data, 'summary' => $summary, 'status' => 'awaiting_confirmation']);
        }

        return $summary . $footer;
    }

    /**
     * Anlaşmadan teslim taslağı: kart + (varsa) soru (hangi kalem / miktar / fiyat farkı). Düzeltme turunda AI kalemi tekrarlamazsa
     * önceki satırlar korunur, söylenen miktar/fiyat aynı kaleme işlenir.
     */
    private function saveAgreementDeliveryDraft(?WhatsappPendingExpense $pending, string $phone, array $data): string
    {
        if ($pending && AgreementDeliveryDraft::handles($pending->extracted)) {
            $prev = $pending->extracted['delivery_lines'] ?? [];
            $new = $data['delivery_lines'] ?? [];
            if ($new === []) {
                $data['delivery_lines'] = $prev;
                $data['delivery_candidates'] = $data['delivery_candidates'] ?: ($pending->extracted['delivery_candidates'] ?? []);
            } elseif (count($new) < count($prev)) {
                $byId = collect($new)->keyBy('agreement_item_id');
                $data['delivery_lines'] = array_map(fn ($l) => $byId->has($l['agreement_item_id'])
                    ? array_merge($l, array_filter($byId[$l['agreement_item_id']], fn ($v) => $v !== null)) : $l, $prev);
            }
            $data['project_id'] = $data['project_id'] ?? $pending->extracted['project_id'] ?? null;
            $data['project_choice_done'] = ! empty($data['project_choice_done']) || ! empty($pending->extracted['project_choice_done']);
        }
        $data = AgreementDeliveryDraft::prepare($data);
        if (($data['delivery_lines'] ?? []) === []) {
            // Listede olmayan kalem: tahmin yok — kullanıcıya anlaşmayı sor (taslak açılmaz).
            return 'Bu teslimatı hangi anlaşmaya yazacağımı bulamadım. Kimden ne geldiğini anlaşmadaki adıyla yazar mısın? '
                . '(Anlaşma dışıysa tutarıyla: "Kuşaktan 20 m³ beton aldım 80 bin")';
        }
        $question = AgreementDeliveryDraft::question($data);
        $data['question'] = $question;
        // Projesiz anlaşmadan gelen mal: proje sorulur (anlaşmanın projesi varsa oraya, sorulmaz). Sorular bitince.
        $data = $question === null && AgreementDeliveryDraft::withoutProject($data) ? ProjectChoice::prepare($data, $phone)
            : array_merge($data, ['project_suggested' => false, 'project_needed' => false]);
        $summary = ! empty(array_filter(array_column($data['delivery_lines'], 'agreement_item_id')))
            ? AgreementDeliveryDraft::summary($data) . (($pl = ProjectChoice::lines($data)) && ProjectChoice::menuShown($data) ? "\n" . implode("\n", $pl) : '')
                . ($question !== null ? "\n\n❓ " . $question : '')
            : '❓ ' . $question;
        $footer = match (true) {
            $question !== null => "\n\nVazgeçmek için *iptal*.",
            ProjectChoice::menuShown($data) => '',
            default => "\n\nKaydedeyim mi? *evet* / *iptal*",
        };

        if ($pending) {
            $pending->update(['extracted' => $data, 'summary' => $summary]);
        } else {
            WhatsappPendingExpense::create(['phone' => $phone, 'extracted' => $data, 'summary' => $summary, 'status' => 'awaiting_confirmation']);
        }

        return $summary . $footer;
    }

    /** Personel ayarı / maaş taslağı: özet + teyit (eksikse soru). */
    private function saveEmployeeDraft(?WhatsappPendingExpense $pending, string $phone, array $data): string
    {
        $question = EmployeeDraft::blocker($data);
        $summary = $question !== null && ! EmployeeDraft::party($data) && empty($data['party_name'])
            ? '❓ ' . $question
            : EmployeeDraft::summary($data) . (($pl = ProjectChoice::lines($data)) ? "\n" . implode("\n", $pl) : '')
                . ($question !== null ? "\n❓ " . $question : '');
        $footer = $question !== null
            ? "\n\nCevabını yaz, vazgeçmek için *iptal*."
            : (ProjectChoice::menuShown($data) ? '' : "\n\nKaydedeyim mi? *evet*");

        if ($pending) {
            $pending->update(['extracted' => $data, 'summary' => $summary]);
        } else {
            WhatsappPendingExpense::create(['phone' => $phone, 'extracted' => $data, 'summary' => $summary, 'status' => 'awaiting_confirmation']);
        }

        return $summary . $footer;
    }

    /** Stoklu satış taslağı: soru türleri doğrudan cevaplanır; kayıt türleri özet + teyit (eksik varsa soru). */
    private function saveTradeDraft(?WhatsappPendingExpense $pending, string $phone, array $data): string
    {
        if (in_array($this->kind($data), TradeDraft::ANSWER_KINDS, true)) {

            return TradeDraft::answer($data);
        }

        $question = TradeDraft::blocker($data) ?? ProjectChoice::blocker($data);
        // Sayım / ayar: eksik varsa (ürün, adet) yalnız soru — özet ürünsüz kurulamaz.
        $summary = $question !== null && in_array($this->kind($data), [ExpenseExtractor::KIND_STOCK_COUNT, ExpenseExtractor::KIND_STOCK_SETTING], true)
            ? '❓ ' . $question
            : TradeDraft::summary($data) . (($pl = ProjectChoice::lines($data)) ? "\n" . implode("\n", $pl) : '')
                . ($question !== null && ProjectChoice::blocker($data) === null ? "\n❓ " . $question : '');
        if ($question === null && (float) ($data['amount'] ?? 0) >= self::WORDS_FROM) {
            $summary .= "\n💬 Yazıyla: *" . Money::words((float) $data['amount']) . ' lira*';
        }
        // Aynı mesajdaki ikinci iş görünsün (buildSummary'deki gibi) — kullanıcı unutulduğunu sanıp tekrar yazmasın.
        if (! empty($data['next_items']) && ! str_starts_with($summary, '❓')) {
            $summary .= "\n➡️ Sırada: " . ExpenseExtractor::nextItemsLabel($data['next_items']) . ' (bunu onaylayınca sorarım)';
        }
        $footer = $question !== null
            ? "\n\nCevabını yaz, vazgeçmek için *iptal*."
            : (ChatAssistant::enabled()
                ? (ProjectChoice::menuShown($data) ? '' : "\n\nKaydedeyim mi? *evet*")
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
        // Personelin maaş hatırlatması = kartın maaş günü (tek doğru yer kart; hatırlatma ondan kurulur).
        if (! empty($data['event_date']) && ($employee = EmployeeDraft::salaryReminderParty($data))) {
            $out = $this->saveDraft($pending, $phone, array_filter([
                'kind' => ExpenseExtractor::KIND_EMPLOYEE_SETTING,
                'party_id' => $employee->id,
                'salary_day' => \Illuminate\Support\Carbon::parse($data['event_date'])->day,
                'salary_amount' => (float) ($data['amount'] ?? 0) > 0 ? (float) $data['amount'] : null,
                'confidence' => 'high',
            ], fn ($v) => $v !== null));

            return is_array($out) ? implode("\n\n", $out) : $out;
        }
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

        // Sade kart (kullanıcı 20.10: "cevaplar çok uzun"): tutar + açıklama + durum tek satırda; boş alan ("Cari: —",
        // "Kategori: —") ve bugünün tarihi yazılmaz. Proje / şube menüsü genel mekanizmada (ProjectChoice::lines, özetin sonuna).
        $party = $d['party_id'] ? Party::find($d['party_id']) : null;
        $partyText = $party?->name ?? (! empty($d['party_name']) ? $d['party_name'] . ' (yeni)' : null);
        $category = $d['category_id'] ? ExpenseCategory::find($d['category_id']) : null;
        $categoryText = $category?->name ?? (! empty($d['category_name']) ? $d['category_name'] . ' (yeni)' : null);

        $lines = [];
        $lines[] = '📝 *Gider*: ' . Money::format((float) ($d['amount'] ?? 0)) . ' ₺'
            . (! empty($d['description']) ? ' — ' . $d['description'] : '')
            . ' · ' . ($this->isPaid($d) ? 'ödendi' : 'ödenmedi (borç)')
            . (($d['confidence'] ?? 'high') === 'low' ? "\n⚠️ Tutardan emin değilim, doğrular mısın?" : '');
        if (($d['paid'] ?? null) === null) {
            $lines[] = '• Ödemediysen *ödenmedi* yaz.';
        }
        if (($date = self::dateLine($d)) !== '') {
            $lines[] = $date;
        }
        if ($categoryText !== null) {
            $lines[] = '• Kategori: ' . $categoryText;
        }
        if ($partyText !== null) {
            $lines[] = '• Cari: ' . $partyText;
        }

        if (! empty($d['due_date'])) {
            $lines[] = '• Ödeme tarihi: ' . \Illuminate\Support\Carbon::parse($d['due_date'])->locale('tr')->translatedFormat('j F Y');
        }

        // Stoklu firmada firmadan ürünsüz alış: ne alındığı sorulur (engellemez) — stoktakiler stoğa girsin (Akkuş 18.10 Bizim Toptan).
        if (! empty($d['supplier_name'])) {
            $lines[] = '• Firma: ' . $d['supplier_name'] . ' (peşin ödendiği için cari açılmaz)';
            if (empty($d['is_service'])) {
                $lines[] = self::WHAT_BOUGHT;
            }
        }


        // Sözleşmeli cariye gider: işin maliyeti zaten sözleşmede → çift maliyet riski.
        if ($party && $this->activeContracts($party)->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '⚠️ ' . $party->name . ' ile sözleşmen var. Bu, sözleşmedeki işin parasıysa *iptal* yaz ve '
                . 'ödeme olarak gönder (ör. "… ödedim"), yoksa maliyet iki kez sayılır. Sözleşme dışı ek işse devam et.';
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

        $staff = self::isStaff($d, $party);
        $label = $party ? $name . ($staff && ! $party->is_employee ? ' 👷 (personel olarak işaretlenecek)' : '')
            : $name . ($staff ? ' 👷 (yeni personel)' : ' (yeni cari)');
        if (DraftGaps::needsSaleOrPurchase($d)) {
            return '📦 ' . $label . ': ' . Money::format($amount) . ' ₺' . (! empty($d['description']) ? ', ' . $d['description'] : '')
                . " — *kim kime verdi?*\n• Sen mi ona götürdün (*sattım* → " . $name . ' sana borçlanır), yoksa ondan mı aldın (*aldım* → sen ona borçlanırsın)?';
        }
        $before = $party ? PartyStatement::build($party)['balance'] : 0.0;
        $used = AdvanceOffset::used($d);
        // Ekstre konvansiyonu: bakiye > 0 → cari bize borçlu, < 0 → biz cariye borçluyuz.
        $after = match ($kind) {
            ExpenseExtractor::KIND_PAYMENT => $before + $amount - DraftGaps::unrecordedDebt($d),
            ExpenseExtractor::KIND_SALE => ! empty($d['paid']) ? $before + $used : $before + $amount,
            ExpenseExtractor::KIND_COLLECTION => $before - $amount,
            ExpenseExtractor::KIND_DEBT_NOTE => ($d['debt_side'] ?? null) === 'payable' ? $before - $amount : $before + $amount,
            ExpenseExtractor::KIND_PURCHASE => ! empty($d['paid']) ? $before - $used : $before - $amount,
        };

        $lines = [];
        // Açıklama başlık satırında ("— 200 m² fayans"); yalnız kişi adı / "iş" / "ödeme" gibi boş sözse yazılmaz.
        $desc = trim((string) ($d['description'] ?? ''));
        $inline = $desc !== '' && in_array($kind, [ExpenseExtractor::KIND_PAYMENT, ExpenseExtractor::KIND_PURCHASE, ExpenseExtractor::KIND_SALE,
            ExpenseExtractor::KIND_COLLECTION], true) && ($kind !== ExpenseExtractor::KIND_SALE || empty($d['items']));
        $headDesc = $inline && ! self::saidInMeaning($desc, $name . ' iş işi işçilik ödeme ödemesi alış alışı satış tahsilat avans avansı verilen')
            ? ' — ' . $desc : '';
        if ($kind === ExpenseExtractor::KIND_PAYMENT) {
            $type = ! empty($d['advance']) ? match (true) {
                ($d['payment_purpose'] ?? null) === 'loan' => ' · borç verdin (geri alacaksın)',
                $staff => ' · maaş avansı (maaşından düşülür)',
                default => ' · önceden ödeme (iş/mal sonra)',
            } : '';
            $lines[] = '💸 *Sen* → ' . $label . ': ' . Money::format($amount) . ' ₺ '
                . (! empty($d['debt_repayment']) ? '*borcunu ödedin*' : 'ödedin') . $headDesc . $type;
            if (($missing = DraftGaps::unrecordedDebt($d)) > 0) {
                $lines[] = 'ℹ️ Bu borç kayıtlı değildi' . ($missing < $amount ? ' (' . Money::format($amount - $missing) . ' ₺ kayıtlıydı)' : '')
                    . ' — ekstreye eski borç ' . Money::format($missing) . ' ₺ + ödeme olarak yazarım.';
            }
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
                $lines[] = '(ör. "sıva yaptırdım", "iş yaptıracağım, önceden verdim", "borç verdim, geri alacağım")';
                if (! empty($d['party_check'])) { // erken dönüşte isim sorusu kaybolmasın
                    $lines[] = '❓ "' . $d['party_said'] . '" yazdın, *' . $name . '* ile eşleştirdim. *Aynı kişi mi?* Değilse *yeni* yaz.';
                }

                return implode("\n", $lines);
            }
            // Niyet (önceden ödeme / borç / maaş avansı) başlık satırının sonunda — yanlışsa kullanıcı düzeltsin.
        } elseif ($kind === ExpenseExtractor::KIND_SALE) {
            // Günlük dil + tek satır (kullanıcı 20.10: "avans demesine gerek yok"; "yeni para yaz" satırı gereksiz —
            // ayrıca para aldıysa kendisi yazar, AdvanceOffset::DECLINE anlar).
            $rest = $amount - $used;
            $lines[] = '🧾 *Satış* → ' . $label . ': ' . Money::format($amount) . ' ₺' . $headDesc
                . match (true) {
                    $used >= $amount - 0.009 => ' · önceden aldığın paradan düşüldü',
                    $used > 0 => ' · ' . Money::format($used) . ' ₺ önceden aldığın paradan, kalan ' . Money::format($rest) . ' ₺ '
                        . (! empty($d['paid']) ? 'alındı' : 'sana borç'),
                    ! empty($d['paid']) => ' (parası alındı)',
                    default => ' (sana borçlandı)',
                };
            foreach ($d['items'] ?? [] as $item) {
                $lines[] = '   • ' . ($item['description'] ?: '—') . ': ' . Money::format((float) $item['amount']) . ' ₺';
            }
        } elseif ($kind === ExpenseExtractor::KIND_PURCHASE && DraftGaps::needsPaidAnswer($d)) {
            return '🛒 *Alış* ← ' . $label . ': ' . Money::format($amount) . ' ₺'
                . (! empty($d['description']) ? "\n• Açıklama: " . $d['description'] : '')
                . self::dateLine($d, "\n")
                . "\n❓ *Ödedin mi?* *ödedim* (peşin) ya da *veresiye* (sonra ödeyeceğim) yaz."
                . (! empty($d['party_check']) ? "\n❓ \"" . $d['party_said'] . '" yazdın, *' . $name . '* ile eşleştirdim. *Aynı kişi mi?* Değilse *yeni* yaz.' : '');
        } elseif ($kind === ExpenseExtractor::KIND_PURCHASE) {
            $rest = $amount - $used;
            $lines[] = '🛒 *Alış* ← ' . $label . ': ' . Money::format($amount) . ' ₺' . $headDesc
                . match (true) {
                    $used >= $amount - 0.009 => ' · önceki ödemeden düşüldü',
                    $used > 0 => ' · ' . Money::format($used) . ' ₺ önceki ödemeden, kalan ' . Money::format($rest) . ' ₺ '
                        . (! empty($d['paid']) ? 'ödendi' : 'ona borç'),
                    ! empty($d['paid']) => ' (ödendi)',
                    default => ' (ona borçlandın)',
                };
            // Hizmet (nakliye, işçilik, tamir) mal değil — "Ne aldın?" sorulmaz (Yeni Yapı 19.10 22:37 "Nakliyeciye 20 bin ödedim").
            if (TradeDraft::enabled() && empty($d['items']) && empty($d['is_service']) && ! TradeDraft::mentionedProduct((string) ($d['description'] ?? ''))) {
                $lines[] = self::WHAT_BOUGHT; // ne alındı? (engellemez) — stoktakiler stoğa girer
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
            $lines[] = '💰 *Peşin para* ← ' . $label . ': ' . Money::format($amount) . ' ₺ aldın' . $headDesc . ' · mal/iş sonra verilecek';
        } else {
            $lines[] = '💰 ' . $label . ' → *sana*: ' . Money::format($amount) . ' ₺ ödedi' . $headDesc;
        }

        if (! empty($d['description']) && ($kind !== ExpenseExtractor::KIND_SALE || empty($d['items']))
            && ! ($kind === ExpenseExtractor::KIND_DEBT_NOTE && $this->isTrivialDebtText($d['description']))
            && ! $inline) {
            $lines[] = '• Açıklama: ' . $d['description'];
        }
        if (($date = self::dateLine($d)) !== '') {
            $lines[] = $date;
        }
        if (! empty($d['due_date']) && in_array($kind, [ExpenseExtractor::KIND_SALE, ExpenseExtractor::KIND_DEBT_NOTE, ExpenseExtractor::KIND_PURCHASE], true)) {
            $lines[] = '• Ödeme tarihi: ' . \Illuminate\Support\Carbon::parse($d['due_date'])->locale('tr')->translatedFormat('j F Y');
        }
        if (! empty($d['payment_type']) && $d['payment_type'] !== 'other' && ! $this->paymentContract($d)) {
            $lines[] = '• Şekli: ' . (self::PAYMENT_TYPE_LABELS[$d['payment_type']] ?? $d['payment_type']);
        }
        if (! ProjectChoice::applies($d) && ($project = ! empty($d['project_id']) ? Project::find($d['project_id']) : null)) {
            $lines[] = '• ' . \App\Support\Labels::project() . ': ' . $project->name; // ödeme/tahsilatta yalnız bilgi
        }
        if (! empty($d['paid_from_habit'])) {
            $lines[] = ! empty($d['paid'])
                ? 'ℹ️ Önceki alışlar gibi *peşin* yazdım; ödemediysen *veresiye* yaz.'
                : 'ℹ️ Önceki alışlar gibi *veresiye* yazdım; ödediysen *ödedim* yaz.';
        }
        if (($fee = (float) ($d['bank_fee'] ?? 0)) > 0) {
            $lines[] = config('modules.expenses')
                ? '🏦 Banka masrafı ' . Money::format($fee) . ' ₺ → gider olarak yazarım (Banka masrafı)'
                : 'ℹ️ Dekontta ' . Money::format($fee) . ' ₺ banka masrafı var, gider takibi açık olmadığı için kaydetmedim.';
        }
        $lines[] = '• Bakiye: ' . $this->balancePhrase($before) . ' → ' . $this->balancePhrase($after);
        if (! empty($d['document_name'])) {
            $lines[] = '• Dekonttaki ad: ' . $d['document_name'] . ' (başkasıysa *o değil* yaz)';
        }
        if (! empty($d['party_check'])) {
            $lines[] = '❓ "' . $d['party_said'] . '" yazdın, *' . $name . '* ile eşleştirdim. *Aynı kişi mi?* Değilse *yeni* yaz.';
        }

        return implode("\n", $lines);
    }

    /** Tarih satırı yalnız bugün değilse ("• Tarih: 18 Ekim"); bugünse boş — her kartta bugünün tarihi gereksiz uzunluk. */
    private static function dateLine(array $d, string $prefix = ''): string
    {
        $date = $d['date'] ?? null;
        if ($date === null || $date === now('Europe/Istanbul')->toDateString()) {
            return '';
        }
        $c = \Illuminate\Support\Carbon::parse($date)->locale('tr');

        return $prefix . '• Tarih: ' . $c->translatedFormat($c->year === now('Europe/Istanbul')->year ? 'j F' : 'j F Y');
    }

    /** Açıklamanın anlamlı kelimelerinin hepsi verilen metinde geçiyorsa (yeni bilgi yok) true — tekrar yazılmaz. */
    private static function saidInMeaning(string $description, string $meaning): bool
    {
        if (trim($meaning) === '') {
            return false;
        }
        $norm = fn (string $t) => mb_strtolower(strtr($t, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
        $meaning = $norm($meaning);
        $words = array_filter(preg_split('/[\s\p{P}]+/u', $norm($description), -1, PREG_SPLIT_NO_EMPTY) ?: [], fn ($w) => mb_strlen($w) >= 3);
        foreach ($words as $w) {
            // Türkçe ek: "fayans" ↔ "fayansı" — kelimenin ilk 5 harfi yeter.
            if (! str_contains($meaning, mb_substr($w, 0, 5))) {
                return false;
            }
        }

        return $words !== [];
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
        $party = $this->queriedParty($d);
        if (! $party) {
            $name = $d['party_name'] ?? null;

            // Yalnız ad yazıldı ("Özkan varlı cari") ama böyle cari yok → ne istediğini sor (Yıldız 20.10, kullanıcı kuralı).
            return $name
                ? '"' . $name . '" adında bir cari bulamadım. Ne yapmamı istersin? Kayıt yazacaksan tutarı ve ne için olduğunu yaz.'
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
        $party = $this->queriedParty($d);
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

    /**
     * Soru cevabı (bakiye / ekstre) için cari: AI kimliği vermeyip yalnız adı yazdıysa ("Yakup taş durum" → party_name "YAKUP TAŞ")
     * adı birebir tutan TEK cari. Yalnız okuma — kayıtta kullanılmaz.
     */
    private function queriedParty(array $d): ?Party
    {
        return $this->existingParty($d) ?? DraftGaps::exactParty($d['party_name'] ?? null);
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
        if ($url === '' || (! Str::startsWith($type, 'image/') && $type !== 'application/pdf')) {
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

    /** İşlem geçmişindeki kişi: hub'daki numara adı + numara ("Emre (0532 059 11 24)"); ad yoksa yalnız numara. */
    private static function activityActor(string $phone): string
    {
        $number = Phone::display($phone);
        try {
            $name = \App\Models\HubPhone::where('phone', Phone::normalize($phone))->value('name');
        } catch (\Throwable) {
            $name = null; // merkez tablo yoksa (tek kurulum) yalnız numara
        }

        return $name ? "{$name} ({$number})" : $number;
    }
}

/** Düzeltmenin doğru hâli kaydedilemedi — silme dahil her şey geri alınır. */
final class UndoAborted extends \RuntimeException {}
