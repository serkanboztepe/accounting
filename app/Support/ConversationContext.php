<?php

namespace App\Support;

use App\Models\Party;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;

/**
 * Sınırlı bağlam: telefon başına yalnız SON KONUŞULAN CARİ (30 dk). "Ali'nin borcu ne?" → "ondan
 * 20 bin aldım" / "5 bin daha verdi" / "hepsini ödedim" isimsiz mesajları o cariye bağlansın.
 * Bilerek dar: mesaj geçmişi, proje, kategori taşınmaz (önceki taslağın projesi/carisi alakasız
 * mesaja sızıyordu — "cam balkon" → Kuşak Beton). Teyitte cari adı her zaman görünür.
 */
class ConversationContext
{
    public const TTL_MINUTES = 30;

    public static function remember(string $phone, int $partyId): void
    {
        Cache::put(self::key($phone), ['party_id' => $partyId], now()->addMinutes(self::TTL_MINUTES));
    }

    /**
     * AI'a verilecek bağlam: cari + güncel bakiye ("hepsini ödedim" tutarı için). Cari silindiyse null.
     *
     * @return array{party_id:int,party_name:string,balance:float,balance_note:string}|null
     */
    public static function get(string $phone): ?array
    {
        $partyId = Cache::get(self::key($phone))['party_id'] ?? null;
        $party = $partyId ? Party::find($partyId) : null;
        if (! $party) {
            return null;
        }

        $balance = PartyStatement::build($party)['balance'];

        return [
            'party_id' => $party->id,
            'party_name' => $party->name,
            'balance' => $balance,
            'balance_note' => PartyBalances::line($party->name, $balance),
        ];
    }

    private static function key(string $phone): string
    {
        return Tenancy::key('wa-ctx:' . Phone::normalize($phone));
    }
}
