<?php

namespace App\Services\Whatsapp;

use App\Models\Party;

/**
 * Belgedeki cari bilgisi (kullanıcı 22.10: "ek bilgi cariye sen yazma, asistan yapabiliyorsa yapayım mı desin"): anlaşma kaydından
 * sonra sözleşmedeki karşı tarafın telefonu / adresi cari kartında yoksa sorulur — *evet* → telefon (boşsa) + notlara "Adres: …".
 * Başka bir mesaj gelirse soru sessizce kapanır (kontrolcü).
 */
final class PartyInfoDraft
{
    public const KIND = 'party_info';

    /** Sorulacak bir şey varsa taslak verisi; yoksa null (telefon zaten var, adres notlarda). */
    public static function offer(Party $party, ?string $phone, ?string $address): ?array
    {
        $phone = trim((string) $phone) !== '' && blank($party->phone) ? trim((string) $phone) : null;
        $address = trim((string) $address);
        $address = $address !== '' && ! str_contains(mb_strtolower((string) $party->notes), mb_strtolower($address)) ? $address : null;
        if ($phone === null && $address === null) {
            return null;
        }

        return ['kind' => self::KIND, 'party_id' => $party->id, 'party_phone' => $phone, 'party_address' => $address];
    }

    /** "📇 Belgede Kamuran Narçin'in telefonu 0543 960 92 17, adresi Ergenekon Mah. 136 Sok. var — cari kartına yazayım mı?" */
    public static function summary(array $d): string
    {
        $name = Party::find($d['party_id'])?->name ?? 'carinin';
        $parts = array_filter([
            $d['party_phone'] ? 'telefonu ' . $d['party_phone'] : null,
            $d['party_address'] ? 'adresi ' . $d['party_address'] : null,
        ]);

        return '📇 Belgede *' . $name . '* ' . implode(', ', $parts) . ' var — cari kartına yazayım mı? *evet* / *iptal*';
    }

    public static function commit(array $d): string
    {
        $party = Party::find($d['party_id']);
        if (! $party) {
            return 'Cari bulunamadı, bir şey yazmadım.';
        }
        $changes = [];
        if ($d['party_phone'] && blank($party->phone)) {
            $changes['phone'] = mb_substr($d['party_phone'], 0, 50);
        }
        if ($d['party_address']) {
            $changes['notes'] = trim(trim((string) $party->notes) . "\nAdres: " . $d['party_address']);
        }
        if ($changes !== []) {
            $party->update($changes);
        }

        return '✅ ' . $party->name . ' cari kartına yazıldı.';
    }
}
