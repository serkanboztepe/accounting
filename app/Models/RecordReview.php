<?php

namespace App\Models;

use App\Support\Phone;
use App\Tenancy\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;

/** Kayıt kontrolü (Faz 3.1, merkez): gece analizinin "yanlış anlaşılmış olabilecek kayıt" önerisi. */
class RecordReview extends Model
{
    use UsesCentralConnection;

    public const STATUSES = ['pending' => 'Bekliyor', 'asked' => 'Müşteriye soruldu', 'resolved' => 'Düzeltildi', 'correct' => 'Doğruymuş', 'dismissed' => 'Gerek yok'];

    /**
     * Bilgi sorusu (28.10, Akkuş "Ekim maaşı ödendi mi?"): şüpheli kayıt yok, yalnız bilgi istiyoruz. problem bununla başlar;
     * asistan cevap ne olursa olsun yalnız not alır — kart / geri alma önermez, kayda dokunmaz. Cevabı yönetici hub'da okur.
     */
    public const INFO_PREFIX = 'Bilgi sorusu';

    public function isInfo(): bool
    {
        return str_starts_with((string) $this->problem, self::INFO_PREFIX);
    }

    /** Kapatma sebepleri (Kapat penceresi). */
    public const CLOSED = ['resolved' => 'Düzeltildi', 'correct' => 'Doğruymuş', 'dismissed' => 'Gerek yok'];

    protected $fillable = ['conversation_report_id', 'hub_firm_id', 'firm_name', 'user_name', 'phone', 'happened_at', 'problem', 'fix',
        'question', 'status', 'asked_at', 'asked_announcement_id', 'customer_answer', 'answered_at', 'decided_at', 'decision_note'];

    protected $casts = ['asked_at' => 'datetime', 'answered_at' => 'datetime', 'decided_at' => 'datetime'];

    /** Açık: henüz bakılmamış ya da sorulmuş ama cevap gelmemiş. */
    public function scopeOpen($q)
    {
        return $q->where(fn ($w) => $w->where('status', 'pending')->orWhere(fn ($a) => $a->where('status', 'asked')->whereNull('answered_at')));
    }

    /** Cevap geldi: müşteri yazdı, yöneticinin bakması gerekiyor. */
    public function scopeAnswered($q)
    {
        return $q->where('status', 'asked')->whereNotNull('answered_at');
    }

    public function scopeClosed($q)
    {
        return $q->whereIn('status', array_keys(self::CLOSED));
    }

    public function firm()
    {
        return $this->belongsTo(HubFirm::class, 'hub_firm_id');
    }

    /**
     * Bu numaraya sorulmuş, konuşması süren kontrol (cevap bekleniyor ya da cevap son 2 saatte geldi) — asistan cevabı bu kayda
     * bağlar ("doğru" → Doğruymuş; "hayır, şöyleydi" → o kaydın düzeltme kartı, onaylanınca Düzeltildi). Firma içinde.
     */
    public static function activeFor(string $phone): ?self
    {
        try {
            return self::where('phone', Phone::normalize($phone))->where('status', 'asked')
                ->when(\App\Tenancy\Tenancy::current(), fn ($q, $firm) => $q->where('hub_firm_id', $firm->id))
                ->where('asked_at', '>=', now()->subDays(3))
                ->where(fn ($q) => $q->whereNull('answered_at')->orWhere('answered_at', '>=', now()->subHours(2)))
                ->latest('asked_at')->first();
        } catch (\Throwable) {
            return null; // yardımcı; mesaj akışı asla durmaz
        }
    }

    /** Asistanın talimatına giren bağlam bloğu. */
    public function promptBlock(): string
    {
        if ($this->isInfo()) {
            return "\n\nBİLGİ SORUSU (bu kişiye biz sorduk; şimdiki mesaj büyük ihtimalle bu soruya cevap):\n"
                . '  Sorduğumuz: ' . mb_substr(str_replace("\n", ' ', (string) $this->question), 0, 500) . "\n"
                . "  - Cevap NE OLURSA OLSUN (\"evet\", \"hayır\", \"ödendi\", \"ödenmedi\", açıklama) → `review_answer` verdict=correct.\n"
                . "    KART ÖNERME, `undo_last` YAPMA, hiçbir kayda dokunma — cevabı yönetici okuyup kendisi işleyecek.\n"
                . '  - Soruyla ilgisiz yeni bir işlemse bu bloğu yok say.';
        }

        return "\n\nKAYIT KONTROLÜ (bu kişiye biz sorduk; şimdiki mesaj büyük ihtimalle bu soruya cevap):\n"
            . '  Sorduğumuz: ' . mb_substr(str_replace("\n", ' ', (string) $this->question), 0, 400) . "\n"
            . '  Şüpheli kayıt: ' . mb_substr((string) $this->problem, 0, 300) . "\n"
            . ($this->fix ? '  Doğrusu olabilir (TAHMİN, kullanıcı söylemeden uygulama): ' . mb_substr((string) $this->fix, 0, 300) . "\n" : '')
            . "  - Sorduğumuzda bir işlem ÖNERDİYSEK (\"kurayım mı?\", \"sileyim mi?\", \"ekleyeyim mi?\") ve kullanıcı kabul ediyorsa (\"evet\", \"olur\")\n"
            . "    → önerdiğimiz işlemi `propose_entry` / `undo_last` ile KART olarak öner (kullanıcı kartı onaylar); birden çok işlem önerdiysek\n"
            . "    ilkini öner, kalanları `next_items`. Kabul etmiyorsa (\"hayır\", \"gerek yok\") → `review_answer` verdict=declined (kayıt yok).\n"
            . "  - Önerimiz yoksa ve kullanıcı kaydın DOĞRU olduğunu söylüyorsa (\"evet doğru\", \"öyle\", \"doğru yazılmış\") → `review_answer` verdict=correct.\n"
            . "  - Yanlış deyip doğrusunu söylüyorsa (\"hayır, 16.261'di\", \"o alış değil gider\") → O KAYDI `undo_last` + find_* + `replacement`\n"
            . "    ile düzelt (silinmesi gerekiyorsa replacement=null); yeni bir işlem anlatıyorsa `propose_entry`. Doğrusu belli değilse kısaca sor.\n"
            . '  - Soruyla ilgisiz yeni bir işlemse bu bloğu yok say.';
    }

    /** Müşteri cevabıyla kapanış (asistan): doğruymuş / düzeltildi. */
    public function closeBy(string $status, string $note): void
    {
        try {
            if ($this->fresh()?->status === 'asked') {
                $this->update(['status' => $status, 'decision_note' => mb_substr($note, 0, 1000), 'decided_at' => now()]);
            }
        } catch (\Throwable) {
        }
    }

    /** Sorulmuş ve cevaplanmamış kontrol varsa bu mesaj cevaptır (3 gün içinde). Asıl akışı bozmaz. */
    public static function captureAnswer(string $phone, string $body): void
    {
        if (trim($body) === '') {
            return;
        }
        try {
            $review = self::where('phone', Phone::normalize($phone))->where('status', 'asked')->whereNull('answered_at')
                ->where('asked_at', '>=', now()->subDays(3))->latest('asked_at')->first();
            if (! $review) {
                return;
            }
            if ($review->asked_announcement_id) {
                $a = HubAnnouncement::find($review->asked_announcement_id);
                if (! $a || $a->status !== 'sent' || ! $a->sent_at || $a->sent_at->gt(now()->subSecond())) {
                    return; // soru henüz iletilmedi (bu mesajın cevabına eklenecek)
                }
            }
            $review->update(['customer_answer' => mb_substr($body, 0, 2000), 'answered_at' => now()]);
        } catch (\Throwable) {
            // cevap kaydı yardımcı; mesaj akışı asla durmaz
        }
    }
}
