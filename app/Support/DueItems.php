<?php

namespace App\Support;

use App\Models\ContractPayment;
use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Ödeme tarihi gelen işler (ödeme günü hatırlatması + takvim). ÖDENDİYSE LİSTELENMEZ:
 *   - Gider (ödenmemiş/kısmi): carisi varsa ve ekstrede borcumuz kalmadıysa atlanır (paneldeki
 *     "Vadeli ödemeler" kutusuyla aynı kural — ekstreyle çelişmesin).
 *   - Sözleşme ödemesi (ödenmemiş, çek dışı — çekin kendi hatırlatması var).
 *   - Cari satış (alacak) / alış (borç) satırı: carinin GÜNCEL bakiyesi o yönde değilse atlanır;
 *     kısmen ödendiyse güncel bakiye gösterilir.
 */
class DueItems
{
    /**
     * @return Collection<int, array{date:string, kind:string, title:string, amount:float, party_id:?int, note:?string}>
     */
    public static function between(CarbonInterface $from, CarbonInterface $to): Collection
    {
        [$from, $to] = [$from->toDateString(), $to->toDateString()];
        $items = collect();
        $balances = [];
        $balance = function (Party $party) use (&$balances): float {
            return $balances[$party->id] ??= PartyStatement::build($party)['balance'];
        };

        if (config('modules.expenses')) {
            foreach (Expense::query()->with(['party', 'category'])->whereIn('payment_status', ['unpaid', 'partial'])
                ->whereBetween('due_date', [$from, $to])->get() as $e) {
                if ($e->party && $balance($e->party) > -0.01) {
                    continue; // cariye borcumuz kalmamış
                }
                $what = $e->description ?: ($e->category?->name ?? 'Gider');
                $items->push(self::item($e->due_date, 'pay', 'Ödeme: ' . ($e->party ? $e->party->name . ' — ' : '') . $what,
                    (float) $e->amount, $e->party_id));
            }
        }

        if (config('modules.contracts')) {
            foreach (ContractPayment::query()->with('contract.party')->where('status', 'unpaid')->where('payment_type', '!=', 'check')
                ->whereBetween('due_date', [$from, $to])->get() as $p) {
                $items->push(self::item($p->due_date, 'pay', 'Sözleşme ödemesi: ' . ($p->contract?->party?->name ?? '')
                    . ' — ' . ($p->contract?->title ?? 'sözleşme'), (float) $p->amount, $p->contract?->party_id));
            }
        }

        foreach (PartyLedgerEntry::query()->with('party')->whereIn('type', [PartyLedgerEntry::TYPE_SALE, PartyLedgerEntry::TYPE_PURCHASE])
            ->whereBetween('due_date', [$from, $to])->get() as $l) {
            if (! $l->party) {
                continue;
            }
            $current = $balance($l->party);
            $receivable = $l->type === PartyLedgerEntry::TYPE_SALE;
            if ($receivable ? $current < 0.01 : $current > -0.01) {
                continue; // ödenmiş / kapanmış
            }
            $items->push(self::item($l->due_date, $receivable ? 'collect' : 'pay',
                ($receivable ? 'Tahsilat: ' : 'Ödeme: ') . $l->party->name . ($l->description && ! str_starts_with($l->description, 'Alacak kaydı') && ! str_starts_with($l->description, 'Borç kaydı') ? ' — ' . $l->description : ''),
                (float) $l->amount, $l->party_id,
                // Kısmen ödendiyse vadedeki tutar ile bugünkü borç farklı — ikisi de görünsün.
                abs(abs($current) - (float) $l->amount) >= 0.01 ? PartyBalances::line($l->party->name, $current) : null));
        }

        return $items->sortBy('date')->values();
    }

    private static function item(CarbonInterface $date, string $kind, string $title, float $amount, ?int $partyId, ?string $note = null): array
    {
        return ['date' => $date->toDateString(), 'kind' => $kind, 'title' => $title, 'amount' => $amount, 'party_id' => $partyId, 'note' => $note];
    }

    /** Tek satır: "Tahsilat: Ali — Bal 45.000,00 ₺ (Ali: sana borcu 30.000,00 ₺)". */
    public static function line(array $item): string
    {
        return $item['title'] . ' ' . Money::format($item['amount']) . ' ₺' . ($item['note'] ? ' (' . rtrim($item['note'], '.') . ')' : '');
    }
}
