<?php

namespace App\Models;

use App\Support\Phone;
use App\Tenancy\Tenancy;
use App\Tenancy\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;

/** Hub: numaraya bırakılan tek seferlik duyuru (bkz. migration create_hub_announcements_table). */
class HubAnnouncement extends Model
{
    use UsesCentralConnection;

    protected $fillable = ['hub_firm_id', 'phone', 'body', 'status', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(fn (HubAnnouncement $a) => $a->phone = Phone::normalize($a->phone));
    }

    /**
     * Bu numaranın (ve şu anki firmanın) bekleyen duyurusu. Durumlar: pending → (şablonla haber verildiyse
     * notified) → sent | cancelled. "notified" da bekleyen sayılır: metin henüz gitmedi.
     */
    public static function pendingFor(string $phone): ?self
    {
        return static::query()
            ->where('phone', Phone::normalize($phone))
            ->whereIn('status', ['pending', 'notified'])
            ->when(Tenancy::current(), fn ($q, $firm) => $q->where('hub_firm_id', $firm->id))
            ->oldest('id')
            ->first();
    }

    /** "Yenilikleri göster" — bekleyen yoksa en son gönderilen duyuru. */
    public static function latestSentFor(string $phone): ?self
    {
        return static::query()
            ->where('phone', Phone::normalize($phone))
            ->where('status', 'sent')
            ->when(Tenancy::current(), fn ($q, $firm) => $q->where('hub_firm_id', $firm->id))
            ->latest('sent_at')
            ->first();
    }
}
