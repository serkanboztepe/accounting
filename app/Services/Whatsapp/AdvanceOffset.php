<?php

namespace App\Services\Whatsapp;

use App\Models\Party;
use App\Models\PartyLedgerEntry;

/**
 * Önceden avans alınmış/verilmiş cariye teslim: satış (alış) avanstan düşülür, para ikinci kez yazılmaz.
 * Erkan, Ayten (12-13 Ekim): "peynir vereceğim, parasını gönderdi" → ertesi gün "gönderdim, hepsini aldım"
 * sıfırdan peşin satış sanıldı → aynı 6.000 iki kez satış + iki kez tahsilat.
 *
 * Taslakta `advance_used` = avanstan düşülen tutar. `paid` yalnız KALAN kısım için anlam taşır.
 * Kullanıcı "yeni para" derse `advance_declined` → bu taslakta bir daha uygulanmaz.
 */
final class AdvanceOffset
{
    private const DECLINE = '/\b(yeni para|ayr[ıi] ?para|ayr[ıi]ca (para|ald[ıi]m|[öo]dedim)|avanstan (de[ğg]il|d[üu][şs]me)|avans de[ğg]il)/iu';

    /** Carinin henüz mal/iş karşılığı verilmemiş avansı (yalnız "Avans" açıklamalı para hareketleri; borç verme hariç). */
    public static function open(Party $party, string $kind): float
    {
        $balance = TurnTrace::statement('AdvanceOffset::open', $party)['balance']; // > 0: cari bize borçlu, < 0: biz cariye borçluyuz
        [$type, $owed] = $kind === ExpenseExtractor::KIND_SALE
            ? [PartyLedgerEntry::TYPE_COLLECTION, -$balance] // müşteri avansı → mal borcu bizde
            : [PartyLedgerEntry::TYPE_PAYMENT, $balance];    // ustaya avans → iş borcu onda
        if ($owed <= 0.01) {
            return 0.0;
        }
        $advances = (float) TurnTrace::read('AdvanceOffset::open.advances', fn () => PartyLedgerEntry::where('party_id', $party->id)->where('type', $type)
            ->advance()->sum('amount'), ['party_id' => $party->id, 'type' => $type]);

        return round(min($owed, $advances), 2);
    }

    public static function apply(array $d, string $body): array
    {
        $d['advance_used'] = null;
        $kind = $d['kind'] ?? null;
        if (! in_array($kind, [ExpenseExtractor::KIND_SALE, ExpenseExtractor::KIND_PURCHASE], true)) {
            return $d;
        }
        if (preg_match(self::DECLINE, $body)) {
            $d['advance_declined'] = true;
        }
        $amount = (float) ($d['amount'] ?? 0);
        $party = DraftGaps::party($d);
        if (! empty($d['advance_declined']) || $amount <= 0 || ! $party) {
            return $d;
        }
        $open = self::open($party, $kind);
        if ($open > 0) {
            $d['advance_used'] = min($amount, $open);
        }

        return $d;
    }

    public static function used(array $d): float
    {
        return (float) ($d['advance_used'] ?? 0);
    }
}
