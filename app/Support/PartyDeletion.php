<?php

namespace App\Support;

use App\Models\Check;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Quote;
use App\Models\Sale;

/**
 * Cari silinmeden önce ne olacağını söyle. Veritabanı kuralları: cari hareketleri ve direkt satışlar
 * BİRLİKTE silinir; giderler carisiz kalır; çek / sözleşme / teklif varsa silinemez (önceden
 * veritabanı hatası veriyordu). Ferhat Bey vakası: "Sil" yalnız "Emin misiniz?" diyordu, 20.000 ₺'lik
 * tahsilat cariyle birlikte uyarısız gitti.
 */
class PartyDeletion
{
    /** @return list<string> silmeyi engelleyenler ("2 çek") — boşsa silinebilir */
    public static function blockers(Party $party): array
    {
        return array_values(array_filter([
            self::count(Check::where('party_id', $party->id)->count(), 'çek'),
            self::count(Contract::withoutGlobalScopes()->where('party_id', $party->id)->count(), 'sözleşme'),
            class_exists(Quote::class) ? self::count(Quote::where('party_id', $party->id)->count(), 'teklif') : null,
        ]));
    }

    public static function description(Party $party): string
    {
        if ($blockers = self::blockers($party)) {
            return 'Bu cari silinemez: ' . implode(', ', $blockers) . ' bağlı. Önce onları silmen ya da başka cariye taşıman gerekir.';
        }

        $deleted = array_values(array_filter([
            self::count(PartyLedgerEntry::where('party_id', $party->id)->count(), 'cari hareketi'),
            self::count(Sale::where('party_id', $party->id)->count(), 'satış'),
        ]));
        $expenses = Expense::where('party_id', $party->id)->count();

        if ($deleted === [] && $expenses === 0) {
            return 'Bu carinin hiç hareketi yok, güvenle silinebilir.';
        }

        $balance = PartyStatement::build($party)['balance'];
        $text = $deleted
            ? '⚠️ Bu cariyle birlikte ' . implode(' ve ', $deleted) . ' de SİLİNECEK (' . rtrim(PartyBalances::line($party->name, $balance), '.') . '). Geri alınamaz.'
            : '';
        if ($expenses) {
            $text .= ($text ? ' ' : '') . "{$expenses} gider silinmez, carisiz kalır.";
        }

        return $text;
    }

    private static function count(int $n, string $label): ?string
    {
        return $n > 0 ? "{$n} {$label}" : null;
    }
}
