<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\ContractPayment;
use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;

class PartyStatement
{
    /**
     * Cari ekstresi — kronolojik borç/alacak satırları + yürüyen bakiye.
     *
     * Konvansiyon (şirket defterinden):
     *   Alım sözleşmesi / Gider → Alacak (biz borçluyuz) | Ödeme → Borç
     *   Satış sözleşmesi        → Borç (cari borçlu)      | Tahsilat → Alacak
     *
     * Bakiye = ΣBorç − ΣAlacak.  >0 cari bize borçlu · <0 biz cariye borçluyuz.
     *
     * $filters: ['date_from' => 'Y-m-d', 'date_to' => 'Y-m-d', 'project_id' => int]
     * date_from verilirse önceki tüm hareketlerin neti "Açılış (devir)" satırı olur.
     */
    public static function build(Party $party, array $filters = []): array
    {
        $rows = [];

        // --- Sözleşmeler + ödemeleri ---
        $contracts = Contract::withoutGlobalScope('purchase')
            ->where('party_id', $party->id)
            ->with(['payments', 'project'])
            ->get();

        foreach ($contracts as $contract) {
            $isSale = $contract->isSale();
            $total  = (float) $contract->reportableTotal();

            $rows[] = self::row(
                $contract->contract_date ?? $contract->created_at,
                $isSale ? 'Satış Sözleşmesi' : 'Sözleşme',
                $contract->title,
                $isSale ? $total : 0.0,
                $isSale ? 0.0 : $total,
                $contract->project_id,
                $contract->project?->name,
            );

            foreach ($contract->payments as $payment) {
                $amount = (float) $payment->amount;
                $label  = ContractPayment::PAYMENT_TYPES[$payment->payment_type] ?? $payment->payment_type;

                $rows[] = self::row(
                    $payment->payment_date ?? $payment->created_at,
                    $isSale ? 'Tahsilat' : 'Ödeme',
                    trim($label . ($payment->notes ? ' — ' . $payment->notes : '')),
                    $isSale ? 0.0 : $amount,
                    $isSale ? $amount : 0.0,
                    $contract->project_id,
                    $contract->project?->name,
                );
            }
        }

        // --- Giderler (bu cariye ait) → Alacak (biz borçluyuz). Ödendiyse kapama satırı. ---
        $expenses = Expense::with(['project', 'category'])
            ->where('party_id', $party->id)
            ->get();

        foreach ($expenses as $expense) {
            $amount = (float) $expense->amount;
            $desc   = $expense->description ?: ($expense->category?->name ?? 'Gider');

            $rows[] = self::row(
                $expense->expense_date,
                'Gider',
                $desc,
                0.0,
                $amount,
                $expense->project_id,
                $expense->project?->name,
            );

            // Ödendiyse: aynı gideri kapatan borç satırı (net sıfır — bakiye şişmesin).
            if ($expense->payment_status === 'paid') {
                $rows[] = self::row(
                    $expense->expense_date,
                    'Gider Ödemesi',
                    $desc,
                    $amount,
                    0.0,
                    $expense->project_id,
                    $expense->project?->name,
                );
            }
        }

        // --- Çift yönlü cari hareketleri (elle) ---
        foreach ($party->ledgerEntries()->with('project')->get() as $entry) {
            $amount  = (float) $entry->amount;
            $isDebit = $entry->direction === PartyLedgerEntry::DIRECTION_DEBIT;

            $rows[] = self::row(
                $entry->entry_date,
                $entry->typeLabel(),
                $entry->description,
                $isDebit ? $amount : 0.0,
                $isDebit ? 0.0 : $amount,
                $entry->project_id,
                $entry->project?->name,
                $entry->id,
                // Satışa/iadeye bağlı satırlar buradan düzenlenmez (kaynak = Direkt Satış).
                $entry->sale_id === null && $entry->sale_return_id === null,
            );
        }

        // --- Proje filtresi (varsa) ---
        $projectId = $filters['project_id'] ?? null;
        if ($projectId) {
            $rows = array_values(array_filter($rows, fn ($r) => (int) $r['project_id'] === (int) $projectId));
        }

        usort($rows, fn ($a, $b) => $a['ts'] <=> $b['ts']);

        // --- Tarih filtresi + açılış (devir) bakiyesi ---
        $dateFrom = ! empty($filters['date_from']) ? strtotime($filters['date_from'] . ' 00:00:00') : null;
        $dateTo   = ! empty($filters['date_to']) ? strtotime($filters['date_to'] . ' 23:59:59') : null;

        $opening = 0.0;
        $visible = [];
        foreach ($rows as $r) {
            if ($dateFrom !== null && $r['ts'] < $dateFrom) {
                $opening += $r['borc'] - $r['alacak'];   // devire ekle
                continue;
            }
            if ($dateTo !== null && $r['ts'] > $dateTo) {
                continue;                                 // aralık dışı, gösterme
            }
            $visible[] = $r;
        }

        // Açılış satırı (yalnız date_from varsa ve öncesinde hareket varsa)
        $result = [];
        $balance = 0.0;
        $totalBorc = 0.0;
        $totalAlacak = 0.0;

        if ($dateFrom !== null && abs($opening) > 0.001) {
            $balance = $opening;
            $result[] = [
                'ts' => $dateFrom, 'date' => date('d.m.Y', $dateFrom),
                'label' => 'Açılış (Devir)', 'desc' => 'Önceki dönem bakiyesi',
                'borc' => 0.0, 'alacak' => 0.0,
                'project_id' => null, 'project_name' => null,
                'balance' => $balance, 'is_opening' => true,
            ];
        }

        foreach ($visible as $r) {
            $balance     += $r['borc'] - $r['alacak'];
            $totalBorc   += $r['borc'];
            $totalAlacak += $r['alacak'];
            $r['balance'] = $balance;
            $r['is_opening'] = false;
            $result[] = $r;
        }

        return [
            'rows'         => $result,
            'total_borc'   => $totalBorc,
            'total_alacak' => $totalAlacak,
            'opening'      => $opening,
            'balance'      => $balance,
        ];
    }

    protected static function row($date, string $label, ?string $desc, float $borc, float $alacak, ?int $projectId = null, ?string $projectName = null, ?int $entryId = null, bool $editable = false): array
    {
        return [
            'ts'           => $date ? $date->timestamp : 0,
            'date'         => $date ? $date->format('d.m.Y') : '-',
            'label'        => $label,
            'desc'         => $desc ?: '—',
            'borc'         => $borc,
            'alacak'       => $alacak,
            'project_id'   => $projectId,
            'project_name' => $projectName,
            // Elle girilen cari hareketi ise düzenle/sil için id + izin.
            'entry_id'     => $entryId,
            'editable'     => $editable,
        ];
    }
}
