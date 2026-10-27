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
     * $filters: ['date_from' => 'Y-m-d', 'date_to' => 'Y-m-d', 'project_id' => int, 'agreement' => int|'other']
     * date_from verilirse önceki tüm hareketlerin neti "Açılış (devir)" satırı olur.
     * detailed (27.10): alış / satış / iade belgesi satırının altında kalemleri (`lines`) — ürünsüz belge, ödeme, gider olduğu gibi.
     * agreement (27.10): o anlaşmanın belgeleri + ona bağlı ödemeler; 'other' = anlaşma dışı her şey. Bakiye anlaşma tablosuyla
     * aynı çıkar (AgreementBalances) — belge tutarının anlaşmaya düşen kısmı teslimat hareketlerinden okunur.
     */
    public static function build(Party $party, array $filters = []): array
    {
        $rows = [];
        // WhatsApp teşhis kaydı açıkken bakiyeyi oluşturan kaynak kayıtlar (hesap aynı; bkz. TurnTrace::statement).
        $trace = \App\Services\Whatsapp\TurnTrace::collecting();

        // --- Sözleşmeler + ödemeleri ---
        $contracts = Contract::withoutGlobalScope('purchase')
            ->where('party_id', $party->id)
            ->with(['payments', 'project'])
            ->get();

        foreach ($contracts as $contract) {
            $isSale = $contract->isSale();
            $total  = (float) $contract->reportableTotal();
            if ($trace) {
                \App\Services\Whatsapp\TurnTrace::collect('contracts', $contract->id, ['sale' => $isSale, 'total' => $total, 'project_id' => $contract->project_id]);
            }

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
                if ($trace) {
                    \App\Services\Whatsapp\TurnTrace::collect('contract_payments', $payment->id, ['amount' => $amount, 'type' => $payment->payment_type]);
                }

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
            if ($trace) {
                \App\Services\Whatsapp\TurnTrace::collect('expenses', $expense->id, ['amount' => $amount, 'status' => $expense->payment_status,
                    'date' => $expense->expense_date?->toDateString()]);
            }

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
                    'Ödeme',
                    $desc,
                    $amount,
                    0.0,
                    $expense->project_id,
                    $expense->project?->name,
                );
            }
        }

        // --- Çift yönlü cari hareketleri (elle) ---
        $ledgerEntries = $party->ledgerEntries()->with(['project', 'checks', 'agreement'])->get();
        // İadesi olan satışlar (iade satırları sale_id taşır) → o satış artık düzenlenemez.
        $returnedSaleIds = $ledgerEntries
            ->where('type', PartyLedgerEntry::TYPE_SALE_RETURN)
            ->pluck('sale_id')->filter()->unique()->all();

        foreach ($ledgerEntries as $entry) {
            $amount  = (float) $entry->amount;
            $isDebit = $entry->direction === PartyLedgerEntry::DIRECTION_DEBIT;
            if ($trace) {
                \App\Services\Whatsapp\TurnTrace::collect('party_ledger_entries', $entry->id, ['type' => $entry->type, 'direction' => $entry->direction,
                    'amount' => $amount, 'date' => $entry->entry_date?->toDateString(), 'description' => $entry->description]);
            }
            $isSaleRow = $entry->type === PartyLedgerEntry::TYPE_SALE;

            // Çekli ödeme/tahsilat: çeklerin no ve vadesi açıklamanın yanında (Faz 2.3).
            $checkNote = $entry->checks->isNotEmpty()
                ? 'çek: ' . $entry->checks->map(fn ($c) => trim(($c->check_number ? 'no ' . $c->check_number . ' ' : '') . 'vade ' . $c->due_date?->format('d.m.Y')))->implode(', ')
                : null;

            $row = self::row(
                $entry->entry_date,
                $party->is_employee ? self::employeeLabel($entry) : $entry->typeLabel(),
                trim(implode(' — ', array_filter([$entry->description, $checkNote]))) ?: null,
                $isDebit ? $amount : 0.0,
                $isDebit ? 0.0 : $amount,
                $entry->project_id,
                $entry->project?->name,
                $entry->id,
                // Satışa/iadeye/alış belgesine bağlı satırlar buradan düzenlenmez (kaynak = belge).
                $entry->sale_id === null && $entry->sale_return_id === null && $entry->purchase_id === null && $entry->purchase_return_id === null,
                // Yalnız satış (borç) satırına Düzenle / Aç bağla — iade satırına değil.
                $isSaleRow ? $entry->sale_id : null,
                // İadesi varsa düzenleme kilitli.
                $isSaleRow && in_array($entry->sale_id, $returnedSaleIds, true),
                // İade (satis_iade) satırına Geri Al bağla.
                $entry->type === PartyLedgerEntry::TYPE_SALE_RETURN ? $entry->sale_return_id : null,
                // Alış belgesinden (Alışlar) gelen satır → belgeyi aç.
                $entry->purchase_id,
            );
            // Ödeme / tahsilat hangi anlaşma için (cari sayfasında etiket ya da "Bağla").
            $row['ledger_type'] = $entry->type;
            $row['agreement_title'] = $entry->agreement?->title;
            $row['agreement_id'] = $entry->agreement_id;
            $row['document'] = collect(self::DOCUMENT_COLUMNS)->filter(fn ($c) => $entry->{$c})->map(fn ($c) => [$c, (int) $entry->{$c}])->first();
            $rows[] = $row;
        }

        // --- Anlaşma filtresi (varsa) ---
        if (filled($filters['agreement'] ?? null)) {
            $rows = self::filterByAgreement($rows, $filters['agreement']);
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

        if (! empty($filters['detailed'])) {
            $result = self::attachLines($result, $filters['agreement'] ?? null);
        }

        return [
            'rows'         => $result,
            'total_borc'   => $totalBorc,
            'total_alacak' => $totalAlacak,
            'opening'      => $opening,
            'balance'      => $balance,
        ];
    }

    /**
     * Personel carisinde etiketler (27.10): hak ediş "Alış / Hizmet" değil Maaş / Yevmiye; avans, kesinti ve maaş ödemesi kendi adıyla.
     * Yalnız görünen ad — kayıt tipi ve rakam aynı.
     */
    private static function employeeLabel(PartyLedgerEntry $entry): string
    {
        $desc = mb_strtolower((string) $entry->description);

        return match ($entry->type) {
            PartyLedgerEntry::TYPE_PURCHASE => str_starts_with($desc, 'yevmiye') ? 'Yevmiye' : 'Maaş',
            PartyLedgerEntry::TYPE_PURCHASE_RETURN => 'Kesinti',
            PartyLedgerEntry::TYPE_PAYMENT => str_contains($desc, 'avans') ? 'Avans' : 'Maaş ödemesi',
            default => $entry->typeLabel(),
        };
    }

    /** Kalemleri gösterilen belge satırları (ödeme/tahsilat belgeye bağlı olsa da kalem göstermez — peşin ödeme satırı). */
    private const DOCUMENT_TYPES = [PartyLedgerEntry::TYPE_PURCHASE, PartyLedgerEntry::TYPE_SALE,
        PartyLedgerEntry::TYPE_PURCHASE_RETURN, PartyLedgerEntry::TYPE_SALE_RETURN];

    /**
     * Kalemli ekstre (kullanıcı 27.10: "alım satırının içinde ne var göremiyoruz"): belge satırına `lines` =
     * ["Beton C30 · 30 m³ × 2.600 = 78.000", …]. Ürün satırı olmayan (basit / ürünsüz) belgede boş — satır olduğu gibi kalır.
     * Anlaşma süzgecinde yalnız o anlaşmanın (ya da 'other' → anlaşmasız) kalemleri.
     */
    private static function attachLines(array $rows, int|string|null $agreement): array
    {
        $ids = [];
        foreach ($rows as $r) {
            if (($r['document'] ?? null) && in_array($r['ledger_type'] ?? null, self::DOCUMENT_TYPES, true)) {
                $ids[$r['document'][0]][] = $r['document'][1];
            }
        }
        if ($ids === []) {
            return $rows;
        }
        $lines = [];
        foreach ($ids as $col => $docIds) {
            $query = \App\Models\StockMovement::with(['product.unit', 'agreementItem'])->whereIn($col, array_unique($docIds))->orderBy('id');
            // Alış / satış belgesinin kalemleri: kendi hareketleri (iade hareketi aynı belge id'sini taşıyabilir).
            match ($col) {
                'purchase_id' => $query->where('reason', \App\Models\StockMovement::REASON_PURCHASE)->whereNull('purchase_return_id'),
                'sale_id'     => $query->where('reason', \App\Models\StockMovement::REASON_SALE)->whereNull('sale_return_id'),
                default       => null,
            };
            foreach ($query->get() as $m) {
                $mine = $m->agreementItem?->agreement_id;
                if ($agreement !== null && $agreement !== '' && ($agreement === 'other' ? $mine !== null : (int) $mine !== (int) $agreement)) {
                    continue;
                }
                $lines[$col][(int) $m->{$col}][] = self::lineText($m);
            }
        }
        $manual = [
            'purchase_id' => \App\Models\Purchase::whereIn('id', $ids['purchase_id'] ?? [])->where('manual_amount', '>', 0)->pluck('manual_amount', 'id'),
            'sale_id'     => \App\Models\Sale::whereIn('id', $ids['sale_id'] ?? [])->where('manual_amount', '>', 0)->pluck('manual_amount', 'id'),
        ];

        foreach ($rows as &$r) {
            $doc = $r['document'] ?? null;
            if (! $doc || ! in_array($r['ledger_type'] ?? null, self::DOCUMENT_TYPES, true) || empty($lines[$doc[0]][$doc[1]])) {
                continue;
            }
            $r['lines'] = $lines[$doc[0]][$doc[1]];
            if (($extra = $manual[$doc[0]][$doc[1]] ?? null) && ($agreement === null || $agreement === '' || $agreement === 'other')) {
                $r['lines'][] = 'Ürünsüz tutar = ' . Money::format((float) $extra) . ' ₺';
            }
        }
        unset($r);

        return $rows;
    }

    /** "Beton C30 · 30 m³ × 2.600,00 ₺ = 78.000,00 ₺" · "Nakliye = 4.000,00 ₺" (1 adet, birimsiz) · tutarsız: "Tuğla · 5.000 adet". */
    private static function lineText(\App\Models\StockMovement $m): string
    {
        $qty = (float) $m->quantity;
        $unit = $m->product?->unit?->name;
        $plain = abs($qty - 1) < 0.0001 && ! $unit;
        $text = $m->product?->name ?? 'Ürün';
        if (! $plain) {
            $text .= ' · ' . trim(StockUsage::quantity($qty) . ' ' . $unit)
                . ($m->unit_price !== null && $m->amount !== null ? ' × ' . Money::format((float) $m->unit_price) . ' ₺' : '');
        }

        return $text . ($m->amount !== null ? ' = ' . Money::format((float) $m->amount) . ' ₺' : '');
    }

    /** Belge türü sütunları — teslimat hareketi (stock_movements) belgeye bu sütunla bağlı. */
    private const DOCUMENT_COLUMNS = ['purchase_id', 'sale_id', 'purchase_return_id', 'sale_return_id'];

    /**
     * Satırın anlaşmalara düşen tutarları [agreement_id => tutar]: ödeme/tahsilatta agreement_id; belgede o belgenin anlaşma
     * kalemli hareketleri. Kalan (satır tutarı − parçalar) anlaşma dışıdır ("Diğer").
     */
    private static function filterByAgreement(array $rows, int|string $agreement): array
    {
        $docIds = [];
        foreach ($rows as $r) {
            if ($r['document'] ?? null) {
                $docIds[$r['document'][0]][] = $r['document'][1];
            }
        }
        $docParts = [];
        foreach ($docIds as $col => $ids) {
            \App\Models\StockMovement::whereIn('stock_movements.' . $col, array_unique($ids))
                ->join('agreement_items', 'agreement_items.id', '=', 'stock_movements.agreement_item_id')
                ->groupBy('stock_movements.' . $col, 'agreement_items.agreement_id')
                ->selectRaw('stock_movements.' . $col . ' as doc_id, agreement_items.agreement_id as agreement_id, SUM(stock_movements.amount) as total')
                ->get()
                ->each(function ($m) use (&$docParts, $col) {
                    $docParts[$col][(int) $m->doc_id][(int) $m->agreement_id] = abs((float) $m->total);
                });
        }

        $out = [];
        foreach ($rows as $r) {
            $parts = [];
            if (AgreementBalances::directionFor((string) ($r['ledger_type'] ?? ''))) {
                $parts = ($r['agreement_id'] ?? null) ? [(int) $r['agreement_id'] => $r['borc'] + $r['alacak']] : [];
            } elseif ($r['document'] ?? null) {
                $parts = $docParts[$r['document'][0]][$r['document'][1]] ?? [];
            }
            $amount = $agreement === 'other'
                ? round($r['borc'] + $r['alacak'] - array_sum($parts), 2)
                : round($parts[(int) $agreement] ?? 0.0, 2);
            if ($amount < 0.01) {
                continue;
            }
            $r['borc'] = $r['borc'] > 0 ? $amount : 0.0;
            $r['alacak'] = $r['borc'] > 0 ? 0.0 : $amount;
            $out[] = $r;
        }

        return $out;
    }

    protected static function row($date, string $label, ?string $desc, float $borc, float $alacak, ?int $projectId = null, ?string $projectName = null, ?int $entryId = null, bool $editable = false, ?int $saleId = null, bool $saleHasReturns = false, ?int $saleReturnId = null, ?int $purchaseId = null): array
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
            // Direkt satış satırıysa satış id'si (ekstreden Düzenle / Aç için).
            'sale_id'      => $saleId,
            // İadesi olan satış → Düzenle kilitli (önce iade geri alınmalı).
            'sale_has_returns' => $saleHasReturns,
            // İade (satis_iade) satırıysa SaleReturn id'si (Geri Al için).
            'sale_return_id'   => $saleReturnId,
            // Alış belgesi satırıysa Purchase id'si (Aç için).
            'purchase_id'      => $purchaseId,
        ];
    }
}
