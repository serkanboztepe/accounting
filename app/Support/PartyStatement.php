<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\ContractPayment;
use App\Models\Party;

class PartyStatement
{
    /**
     * Cari ekstresi — kronolojik borç/alacak satırları + yürüyen bakiye.
     *
     * Konvansiyon (cari kartı, şirket defterinden):
     *   Alım sözleşmesi  → Alacak (biz borçluyuz)   | Alım ödemesi   → Borç
     *   Satış sözleşmesi → Borç   (cari borçlu)      | Satış tahsilatı → Alacak
     *   Manuel satır     → kullanıcının seçtiği yön
     *
     * Bakiye = ΣBorç − ΣAlacak.
     *   > 0 → cari bize borçlu (net alacağımız)
     *   < 0 → biz cariye borçluyuz (net borcumuz)
     */
    public static function build(Party $party): array
    {
        $rows = [];

        $contracts = Contract::withoutGlobalScope('purchase')
            ->where('party_id', $party->id)
            ->with('payments')
            ->get();

        foreach ($contracts as $contract) {
            $isSale = $contract->isSale();
            $total  = (float) $contract->reportableTotal();

            // Sözleşme = yükümlülük satırı
            $rows[] = self::row(
                $contract->contract_date ?? $contract->created_at,
                $isSale ? 'Satış Sözleşmesi' : 'Sözleşme',
                $contract->title,
                $isSale ? $total : 0.0,   // satış → borç
                $isSale ? 0.0 : $total,   // alım  → alacak
                false,
            );

            // Ödemeler / tahsilatlar = kapama satırları (yükümlülüğün tersi kolonu)
            foreach ($contract->payments as $payment) {
                $amount = (float) $payment->amount;
                $label  = ContractPayment::PAYMENT_TYPES[$payment->payment_type] ?? $payment->payment_type;

                $rows[] = self::row(
                    $payment->payment_date ?? $payment->created_at,
                    $isSale ? 'Tahsilat' : 'Ödeme',
                    trim($label . ($payment->notes ? ' — ' . $payment->notes : '')),
                    $isSale ? 0.0 : $amount,   // alım ödemesi → borç
                    $isSale ? $amount : 0.0,   // satış tahsilatı → alacak
                    false,
                );
            }
        }

        // Manuel satırlar (açılış/düzeltme/veresiye) — maliyet raporuna GİRMEZ
        foreach ($party->ledgerEntries as $entry) {
            $amount = (float) $entry->amount;
            $isDebit = $entry->direction === \App\Models\PartyLedgerEntry::DIRECTION_DEBIT;

            $rows[] = self::row(
                $entry->entry_date,
                'Manuel',
                $entry->description,
                $isDebit ? $amount : 0.0,
                $isDebit ? 0.0 : $amount,
                true,
            );
        }

        usort($rows, fn ($a, $b) => ($a['ts']) <=> ($b['ts']));

        $balance = 0.0;
        $totalBorc = 0.0;
        $totalAlacak = 0.0;
        foreach ($rows as &$row) {
            $balance     += $row['borc'] - $row['alacak'];
            $totalBorc   += $row['borc'];
            $totalAlacak += $row['alacak'];
            $row['balance'] = $balance;
        }
        unset($row);

        return [
            'rows'         => $rows,
            'total_borc'   => $totalBorc,
            'total_alacak' => $totalAlacak,
            'balance'      => $balance,
        ];
    }

    protected static function row($date, string $label, ?string $desc, float $borc, float $alacak, bool $isManual): array
    {
        return [
            'ts'        => $date ? $date->timestamp : 0,
            'date'      => $date ? $date->format('d.m.Y') : '-',
            'label'     => $label,
            'desc'      => $desc ?: '—',
            'borc'      => $borc,
            'alacak'    => $alacak,
            'is_manual' => $isManual,
        ];
    }
}
