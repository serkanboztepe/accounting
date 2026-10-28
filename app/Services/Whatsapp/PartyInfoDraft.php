<?php

namespace App\Services\Whatsapp;

use App\Models\Party;
use App\Models\Project;

/**
 * Belgedeki ek bilgi (kullanıcı 22.10: "ek bilgi cariye sen yazma, asistan yapabiliyorsa yapayım mı desin"; "2 proje var, açayım mı
 * diyebilirdi"): anlaşma kaydından sonra sözleşmedeki karşı tarafın telefonu / adresi cari kartında yoksa ve belgede geçen şantiyeler
 * sistemde yoksa TEK soru — *evet* → telefon (boşsa) + notlara "Adres: …" + projeler açılır. Başka bir mesaj soruyu kapatır (kontrolcü).
 */
final class PartyInfoDraft
{
    public const KIND = 'party_info';

    /**
     * Sorulacak bir şey varsa taslak verisi; yoksa null. Bizim firma adı geçen adres ENGELLENMEZ (22.10 Kamuran: adreste "Yen Yapı İnşaat"
     * geçiyordu ama karşı tarafın adresiydi) — hangi blok bizim, AI talimatı ayırır; kullanıcı soruyu görüp onaylar.
     */
    public static function offer(Party $party, ?string $phone, ?string $address, array $projects = []): ?array
    {
        $phone = trim((string) $phone);
        $phone = $phone !== '' && blank($party->phone) ? $phone : null;
        $address = trim((string) $address);
        $address = $address !== '' && ! str_contains(mb_strtolower((string) $party->notes), mb_strtolower($address)) ? $address : null;
        $existing = Project::pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->all();
        $projects = array_values(array_filter(array_map('trim', $projects), fn ($p) => $p !== '' && ! in_array(mb_strtolower($p), $existing, true)));
        if ($phone === null && $address === null && $projects === []) {
            return null;
        }

        return ['kind' => self::KIND, 'party_id' => $party->id, 'party_phone' => $phone, 'party_address' => $address, 'new_projects' => $projects];
    }

    /** "📇 Belgede: • Kamuran Narçin telefonu … • 2 şantiye: … — cari kartına yazayım, projeleri açayım mı?" */
    public static function summary(array $d): string
    {
        $name = Party::find($d['party_id'])?->name ?? 'kişinin';
        $lines = [];
        $card = array_filter([$d['party_phone'] ? 'telefonu ' . $d['party_phone'] : null, $d['party_address'] ? 'adresi ' . $d['party_address'] : null]);
        if ($card) {
            $lines[] = '• *' . $name . '* ' . implode(', ', $card);
        }
        $projects = $d['new_projects'] ?? [];
        if ($projects) {
            $lines[] = '• ' . (count($projects) > 1 ? count($projects) . ' şantiye: ' : 'Şantiye: ') . implode(', ', $projects);
        }
        $ask = implode(', ', array_filter([$card ? 'kişi kartına yazayım' : null, $projects ? (count($projects) > 1 ? 'projeleri' : 'projeyi') . ' açayım' : null]));

        return "📇 Belgede şunlar var:\n" . implode("\n", $lines) . "\n" . mb_strtoupper(mb_substr($ask, 0, 1)) . mb_substr($ask, 1) . ' mı? *evet* / *iptal*';
    }

    public static function commit(array $d): string
    {
        $party = Party::find($d['party_id']);
        $done = [];
        $changes = [];
        if ($party && $d['party_phone'] && blank($party->phone)) {
            $changes['phone'] = mb_substr($d['party_phone'], 0, 50);
        }
        if ($party && $d['party_address']) {
            $changes['notes'] = trim(trim((string) $party->notes) . "\nAdres: " . $d['party_address']);
        }
        if ($changes !== []) {
            $party->update($changes);
            $done[] = $party->name . ' kişi kartına yazıldı';
        }
        $opened = [];
        foreach ($d['new_projects'] ?? [] as $name) {
            $opened[] = Project::firstOrCreate(['name' => $name], ['status' => 'active'])->name;
        }
        if ($opened) {
            $done[] = (count($opened) > 1 ? 'projeler açıldı: ' : 'proje açıldı: ') . implode(', ', $opened);
        }

        return $done ? '✅ ' . implode('; ', $done) . '.' : 'Yazılacak bir şey kalmadı.';
    }
}
