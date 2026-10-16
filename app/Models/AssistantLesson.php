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

    protected $fillable = ['conversation_report_id', 'hub_firm_id', 'firm_name', 'problem', 'cause', 'rule', 'example_message',
        'expected', 'status', 'decided_at'];

    protected $casts = ['decided_at' => 'datetime'];

    public function firm()
    {
        return $this->belongsTo(HubFirm::class, 'hub_firm_id');
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
