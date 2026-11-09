<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WhatsappPendingExpense extends Model
{
    /**
     * Ajan asistanın önerisi (plan v5 §9-A). AYRI durum değerleri: eski yolun "açık taslak" sorgularının hepsi
     * status = 'awaiting_confirmation' arar → ajan önerisini hiçbiri görmez (öneri eski yolda düz gidere düşmesin,
     * "evet" yanlış kaydı onaylamasın). Eski yolun sorgularına dokunulmadı; koruma AgentProposalIsolationTest'te.
     * Öneri onaylanınca işlemler eski biçimde çocuk taslaklara çevrilip mevcut kayıt yoluyla yazılır.
     */
    public const AGENT_OPEN = 'agent_open';

    public const AGENT_CONFIRMED = 'agent_confirmed';

    public const AGENT_CANCELLED = 'agent_cancelled';

    public const AGENT_SUPERSEDED = 'agent_superseded';

    public const AGENT_STATUSES = [self::AGENT_OPEN, self::AGENT_CONFIRMED, self::AGENT_CANCELLED, self::AGENT_SUPERSEDED];

    protected $fillable = [
        'phone',
        'extracted',
        'summary',
        'media_url',
        'status',
        'follow_ups',
        'followed_up_at',
    ];

    protected $casts = [
        'extracted' => 'array',
        'followed_up_at' => 'datetime',
    ];

    /** Ajanın bu numarada açık (onay/cevap bekleyen) önerisi. */
    public function scopeAgentOpen(Builder $query, string $phone): Builder
    {
        return $query->where('phone', $phone)->where('status', self::AGENT_OPEN);
    }

    /** Eski yolun açık taslağı (ajan önerileri hariç — durum değeri zaten ayırır; adı niyeti belli etsin diye). */
    public function scopeLegacyOpen(Builder $query, string $phone): Builder
    {
        return $query->where('phone', $phone)->where('status', 'awaiting_confirmation');
    }

    public function isAgentProposal(): bool
    {
        return in_array($this->status, self::AGENT_STATUSES, true);
    }
}
