<?php

namespace App\Support;

use App\Models\Check;
use App\Models\PartyLedgerEntry;
use Illuminate\Support\Facades\DB;

/**
 * Çeki sil (17.10 kullanıcı kararı: karşılıksız / iptal durumu YOK — çek silinir, cariye etkisi kalmaz). Çek verildiği/alındığı
 * gün ödeme/tahsilat sayıldığı için bağlı para satırı da geri alınır, bakiye çek hiç yokmuş gibi olur:
 *  - sözleşmeye bağlı → sözleşme ödemesi silinir (çek onunla gider; sözleşmenin kalan ödemesi artar);
 *  - ödemeye/tahsilata bağlı → satır tek çekliyse silinir, çok çekliyse yalnız bu çekin tutarı düşülür;
 *  - bağsız → yalnız çek.
 */
final class CheckRemoval
{
    public static function describe(Check $check): string
    {
        $who = $check->party?->name ?? 'Cari';
        $amount = Money::format((float) $check->amount) . ' ₺';

        return match (true) {
            (bool) $check->contractPayment => "{$who} · {$amount} çek ve bağlı SÖZLEŞME ÖDEMESİ silinecek; sözleşmenin kalan ödemesi artar.",
            (bool) $check->ledgerEntry => "{$who} · {$amount} çek ve bağlı " . ($check->ledgerEntry->type === PartyLedgerEntry::TYPE_COLLECTION ? 'tahsilat' : 'ödeme')
                . " kaydı geri alınacak; {$who} bakiyesi çek hiç yokmuş gibi olur.",
            default => "{$who} · {$amount} çek silinecek.",
        };
    }

    public static function remove(Check $check): void
    {
        DB::transaction(function () use ($check) {
            if ($check->bounce_entry_id) {
                PartyLedgerEntry::whereKey($check->bounce_entry_id)->delete();
            }
            if ($payment = $check->contractPayment) {
                $payment->delete(); // ContractPayment silinirken çekleri de siler

                return;
            }
            $entry = $check->ledgerEntry;
            $check->delete();
            if ($entry) {
                $rest = round((float) $entry->amount - (float) $check->amount, 2);
                $entry->checks()->exists() && $rest > 0.009
                    ? $entry->update(['amount' => Money::store($rest)])
                    : $entry->delete();
            }
        });
    }
}
