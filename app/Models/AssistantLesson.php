<?php

namespace App\Models;

use App\Tenancy\Tenancy;
use App\Tenancy\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/** Asistanın dersi (Faz 3, merkez). Gece analizi önerir (pending), yönetici hub'da onaylar (approved). */
class AssistantLesson extends Model
{
    use UsesCentralConnection;

    public const STATUSES = ['pending' => 'Onay bekliyor', 'approved' => 'Uygulanıyor', 'rejected' => 'Reddedildi', 'retired' => 'Kaldırıldı'];

    protected $fillable = ['conversation_report_id', 'hub_firm_id', 'source_firm_id', 'firm_name', 'problem', 'cause', 'rule',
        'example_message', 'expected', 'customer_question', 'asked_phone', 'asked_at', 'asked_announcement_id', 'customer_answer',
        'answered_at', 'status', 'decided_at'];

    protected $casts = ['decided_at' => 'datetime', 'asked_at' => 'datetime', 'answered_at' => 'datetime'];

    public function firm()
    {
        return $this->belongsTo(HubFirm::class, 'hub_firm_id');
    }

    /** Dersin çıktığı firma (genel derste de bilinir — "Müşteriye sor" buna gider). */
    public function sourceFirm()
    {
        return $this->belongsTo(HubFirm::class, 'source_firm_id');
    }

    /**
     * Müşteri bu numaradan yazdı: ona sorulmuş ve henüz cevaplanmamış soru varsa bu mesaj cevaptır.
     * 24 saat dışında sorulup sonraki mesajda iletilen soruda, iletildiği mesaj cevap sayılmaz.
     */
    public static function captureAnswer(string $phone, string $body): void
    {
        if (trim($body) === '') {
            return;
        }
        try {
            $lesson = self::where('asked_phone', \App\Support\Phone::normalize($phone))->whereNull('answered_at')
                ->where('asked_at', '>=', now()->subDays(7))->latest('asked_at')->first();
            if (! $lesson) {
                return;
            }
            if ($lesson->asked_announcement_id) {
                $a = HubAnnouncement::find($lesson->asked_announcement_id);
                if (! $a || $a->status !== 'sent' || ! $a->sent_at || $a->sent_at->gt(now()->subSecond())) {
                    return; // soru henüz iletilmedi (bu mesajın cevabına eklenecek)
                }
            }
            $lesson->update(['customer_answer' => mb_substr($body, 0, 2000), 'answered_at' => now()]);
        } catch (\Throwable) {
            // ders tablosu yoksa mesaj akışı bozulmasın
        }
    }

    /** Asistan talimatına girecek onaylı kurallar: genel + bu firmanınki (10 dk önbellek). @return list<string> */
    public static function rulesForCurrentFirm(): array
    {
        $firmId = Tenancy::current()?->id;

        return Cache::remember('assistant-lessons:' . ($firmId ?? 'none'), 600, function () use ($firmId) {
            try {
                if (! Schema::connection((new self)->getConnectionName())->hasTable('assistant_lessons')) {
                    return [];
                }

                return self::where('status', 'approved')
                    ->where(fn ($q) => $q->whereNull('hub_firm_id')->when($firmId, fn ($w) => $w->orWhere('hub_firm_id', $firmId)))
                    ->orderBy('id')->limit(40)->pluck('rule')->all();
            } catch (\Throwable) {
                return [];
            }
        });
    }

    public static function forgetCache(): void
    {
        Cache::forget('assistant-lessons:none');
        foreach (HubFirm::pluck('id') as $id) {
            Cache::forget('assistant-lessons:' . $id);
        }
    }
}
