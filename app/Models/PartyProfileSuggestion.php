<?php

namespace App\Models;

use App\Tenancy\Tenancy;
use App\Tenancy\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;

/** Cari tanımı önerisi (Faz 3.2, merkez). Onaylanınca firmanın asistan hafızasına "not" olarak yazılır. */
class PartyProfileSuggestion extends Model
{
    use UsesCentralConnection;

    public const STATUSES = ['pending' => 'Onay bekliyor', 'approved' => 'Hafızada', 'rejected' => 'Gerek yok'];

    protected $fillable = ['hub_firm_id', 'firm_name', 'party_id', 'party_name', 'profile', 'status', 'decided_at'];

    protected $casts = ['decided_at' => 'datetime'];

    public function firm()
    {
        return $this->belongsTo(HubFirm::class, 'hub_firm_id');
    }

    /** Onay: firmanın asistan hafızasına (tüm numaralar) cariye bağlı not; aynı cari için eski tanım geçmişe alınır. */
    public function approve(?string $text = null): void
    {
        $profile = trim($text ?? $this->profile);
        $write = function () use ($profile) {
            AssistantMemory::where('party_id', $this->party_id)->where('kind', 'note')->where('subject', 'like', 'Cari tanımı%')
                ->where('status', 'active')->update(['status' => 'replaced']);
            AssistantMemory::create(['kind' => 'note', 'subject' => mb_substr('Cari tanımı · ' . $this->party_name, 0, 120),
                'fact' => mb_substr(str_starts_with(mb_strtolower($profile), mb_strtolower($this->party_name)) ? $profile : $this->party_name . ': ' . $profile, 0, 500), 'party_id' => $this->party_id, 'source' => 'hub', 'status' => 'active']);
        };
        $this->firm && Tenancy::enabled() ? Tenancy::run($this->firm, $write) : $write();
        $this->update(['profile' => $profile, 'status' => 'approved', 'decided_at' => now()]);
    }
}
