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
