<?php

namespace App\Support;

use App\Models\Party;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;

/**
 * Sınırlı bağlam: telefon başına yalnız SON KONUŞULAN CARİ (30 dk) ve — hatırlatma gönderildiyse —
 * o hatırlatmanın metni. "Ali'nin borcu ne?" → "ondan 20 bin aldım"; hatırlatma → "aldım" /
 * "yarın tekrar hatırlat" isimsiz mesajları doğru yere bağlansın.
 * Bilerek dar: mesaj geçmişi, proje, kategori taşınmaz (önceki taslağın projesi/carisi alakasız
 * mesaja sızıyordu — "cam balkon" → Kuşak Beton). Teyitte cari adı her zaman görünür.
 */
class ConversationContext
{
    public const TTL_MINUTES = 30;

    public static function remember(string $phone, ?int $partyId, ?string $reminderText = null, ?int $ttlMinutes = null): void
    {
        // Carisiz hatırlatma (aynı anda giden "kuaför") önceki carili hatırlatmanın kişisini silmesin.
        if ($partyId === null && $reminderText !== null) {
            $partyId = Cache::get(self::key($phone))['party_id'] ?? null;
        }

        Cache::put(self::key($phone), ['party_id' => $partyId, 'reminder' => $reminderText],
            now()->addMinutes($ttlMinutes ?? self::TTL_MINUTES));
    }

    /**
     * AI'a verilecek bağlam. Cari varsa adı + güncel bakiye ("hepsini ödedim" tutarı için).
     *
     * @return array{party_id:?int,party_name:?string,balance:?float,balance_note:?string,reminder:?string}|null
     */
    public static function get(string $phone): ?array
    {
        $stored = Cache::get(self::key($phone));
        if (! is_array($stored)) {
            return null;
        }

        $party = ! empty($stored['party_id']) ? Party::find($stored['party_id']) : null;
        $reminder = $stored['reminder'] ?? null;
        if (! $party && ! $reminder) {
            return null;
        }

        $balance = $party ? PartyStatement::build($party)['balance'] : null;

        return [
            'party_id' => $party?->id,
            'party_name' => $party?->name,
            'balance' => $balance,
            'balance_note' => $party ? PartyBalances::line($party->name, $balance) : null,
            'reminder' => $reminder,
        ];
    }

    private static function key(string $phone): string
    {
        return Tenancy::key('wa-ctx:' . Phone::normalize($phone));
    }
}
