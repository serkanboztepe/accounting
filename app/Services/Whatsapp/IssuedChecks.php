<?php

namespace App\Services\Whatsapp;

use App\Models\Check;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Support\Money;
use Carbon\Carbon;

/**
 * Verdiğimiz çekler (Faz F, 16.10 — Taner'in asansör çekleri): asistan çekli ödemeyi kaydedince her çek ayrı satır,
 * ödeme satırına bağlı, durum "verildi" → cari sayfasının Çekler bölümünde görünür, vadeden önce hatırlatılır
 * (CheckReminders). Borç ödeme anında düşer (alınan çekteki kuralın aynısı); vade takibi çekte.
 * Aldığımız çekler aynı yoldan (Yıldız 23.10): tahsilat satırına bağlı, durum "alındı" (portfolio).
 */
final class IssuedChecks
{
    /** @return int oluşan çek sayısı */
    public static function store(array $d, Party $party, ?int $projectId = null, bool $received = false): int
    {
        $checks = array_values(array_filter((array) ($d['checks'] ?? []), 'is_array'));
        if ($checks === []) {
            return 0;
        }
        // Çeklerin bağlanacağı satır: bu kayıtta yazılan son ödeme (ödeme ya da peşin alışın ödemesi) / tahsilat satırı.
        $entry = PartyLedgerEntry::where('party_id', $party->id)
            ->where('type', $received ? PartyLedgerEntry::TYPE_COLLECTION : PartyLedgerEntry::TYPE_PAYMENT)->latest('id')->first();
        if (! $entry) {
            return 0;
        }
        $entry->update(['payment_type' => 'check']);

        $each = round((float) $entry->amount / count($checks), 2);
        foreach ($checks as $c) {
            [$due, $note] = self::dueDate((string) ($c['due_date_text'] ?? ''));
            Check::create([
                'party_id' => $party->id,
                'project_id' => $projectId,
                'party_ledger_entry_id' => $entry->id,
                'check_number' => $c['number'] ?? null,
                'issue_date' => $d['date'] ?? now()->toDateString(),
                'due_date' => $due,
                'amount' => Money::store((float) ($c['amount'] ?? 0) > 0 ? (float) $c['amount'] : $each),
                'status' => $received ? 'portfolio' : 'issued',
                'description' => mb_substr((string) ($d['description'] ?? ''), 0, 255) ?: null,
                'notes' => trim('WhatsApp üzerinden girildi. ' . $note),
            ]);
        }

        return count($checks);
    }

    /**
     * Çekte yazan vade → tarih. Takvimde olmayan gün (31-02-2027) ayın son gününe çekilir ve not düşülür; okunamazsa null.
     *
     * @return array{0:?string,1:string}
     */
    public static function dueDate(string $text): array
    {
        if (! preg_match('/(\d{1,2})\s*[.\/-]\s*(\d{1,2})\s*[.\/-]\s*(\d{2,4})/', $text, $m)) {
            return [null, $text !== '' ? "Çekteki vade okunamadı: {$text}." : ''];
        }
        $year = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];
        $month = (int) $m[2];
        $day = (int) $m[1];
        if ($month < 1 || $month > 12) {
            return [null, "Çekteki vade okunamadı: {$text}."];
        }
        if (checkdate($month, $day, $year)) {
            return [sprintf('%04d-%02d-%02d', $year, $month, $day), ''];
        }
        $last = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        return [$last, "Çekte yazan vade {$text} takvimde yok — ayın son günü sayıldı."];
    }
}
