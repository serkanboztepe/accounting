<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Agreement;
use App\Models\AgreementItem;
use App\Models\PartyLedgerEntry;
use App\Models\StockMovement;

/**
 * Anlaşma özeti (cari sayfasında "Özet" → yandan açılan pencere, kullanıcı 26.10): sözleşmedeki gibi tek bakışta
 * anlaşılan · gelen · ödenen · bekleyen çek · borç · gelecek mal + teslimat ve ödeme listeleri. Rakamlar AgreementBalances'tan.
 */
final class AgreementSummary
{
    public static function build(?Agreement $agreement): array
    {
        if (! $agreement) {
            return ['agreement' => null];
        }
        $agreement->unsetRelation('items')->loadForCard();
        $agreement->load(['ledgerEntries.checks']);

        // Proje seçimi (kullanıcı 27.10: "bu anlaşmadan A Blok'a kaç m³ geldi?") — yalnız mal birden çok projeye geldiyse.
        $projectIds = $agreement->items->flatMap(fn (AgreementItem $item) => $item->movements->pluck('project_id'))->unique();
        $names = \App\Models\Project::whereIn('id', $projectIds->filter())->pluck('name', 'id');
        $projects = $projectIds->count() > 1
            ? $projectIds->mapWithKeys(fn ($id) => [self::projectKey($id) => $names[$id] ?? Labels::project() . 'siz'])->sort()->all()
            : [];

        $deliveries = $agreement->items->flatMap(fn (AgreementItem $item) => $item->movements->map(fn (StockMovement $m) => [
            'date'    => $m->movement_date,
            'name'    => $item->name(),
            'qty'     => $item->is_lump ? null : $item->quantityLabel((float) $m->quantity),
            'amount'  => ($m->reason === StockMovement::REASON_RETURN ? -1 : 1) * (float) $m->amount,
            'return'  => $m->reason === StockMovement::REASON_RETURN,
            'project' => self::projectKey($m->project_id),
            'item'    => 'i' . $item->id,
        ]))->sortByDesc('date')->values();

        // Malzeme seçimi (kullanıcı 30.10: "Seramik'te fayans + Cumhuriyet") — yalnız birden çok kalem geldiyse.
        $delivered = $agreement->items->filter(fn (AgreementItem $item) => $item->movements->isNotEmpty());
        $items = $delivered->count() > 1 ? $delivered->mapWithKeys(fn (AgreementItem $item) => ['i' . $item->id => $item->name()])->all() : [];

        // Gelen mallar toplamı her seçim için: 'proje|kalem' ('' = hepsi). Kalem seçiliyken miktar da (tek birim) yazılır.
        $totals = [];
        foreach ($agreement->items as $item) {
            foreach ($item->movements as $m) {
                $sign = $m->reason === StockMovement::REASON_RETURN ? -1 : 1;
                foreach (['', self::projectKey($m->project_id)] as $p) {
                    foreach (['', 'i' . $item->id] as $k) {
                        $cell = &$totals[$p . '|' . $k];
                        $cell['amount'] = ($cell['amount'] ?? 0) + $sign * (float) $m->amount;
                        $cell['qty'] = $k === '' || $item->is_lump ? null : ($cell['qty'] ?? 0) + $sign * (float) $m->quantity;
                        $cell['item'] = $k === '' ? null : $item;
                        unset($cell);
                    }
                }
            }
        }
        $deliveryTotals = collect($totals)->map(fn ($c) => [
            'amount' => round($c['amount'], 2),
            'qty'    => $c['qty'] === null ? null : $c['item']->quantityLabel($c['qty']),
            'agreed' => $c['item'] && ! $c['item']->is_lump && ! $c['item']->isList()
                ? 'anlaşılan ' . $c['item']->quantityLabel((float) $c['item']->quantity) . ' · kalan ' . $c['item']->quantityLabel($c['item']->remainingQuantity())
                : null,
        ])->all();

        // Kalem × proje: gelen miktar ve tutar (iade düşülür).
        $byProject = [];
        foreach ($agreement->items as $item) {
            foreach ($item->movements as $m) {
                $sign = $m->reason === StockMovement::REASON_RETURN ? -1 : 1;
                $cell = &$byProject[$item->id][self::projectKey($m->project_id)];
                $cell['qty'] = ($cell['qty'] ?? 0) + $sign * (float) $m->quantity;
                $cell['amount'] = ($cell['amount'] ?? 0) + $sign * (float) $m->amount;
                unset($cell);
            }
        }

        $ours = $agreement->isPurchase() ? PartyLedgerEntry::DIRECTION_DEBIT : PartyLedgerEntry::DIRECTION_CREDIT;
        $payments = $agreement->ledgerEntries->sortByDesc('entry_date')->map(fn (PartyLedgerEntry $e) => [
            'date'   => $e->entry_date,
            'label'  => $e->typeLabel(),
            'method' => ActivityLog::PAYMENT_TYPES[$e->payment_type] ?? null,
            'desc'   => $e->description,
            'amount' => ($e->direction === $ours ? 1 : -1) * (float) $e->amount,
            'checks' => $e->checks->map(fn ($c) => trim(($c->check_number ? 'no ' . $c->check_number . ' · ' : '')
                . 'vade ' . $c->due_date?->format('d.m.Y') . ' · ' . AgreementBalances::money((float) $c->amount) . ' ₺'))->all(),
        ])->values();

        $pending = AgreementBalances::pendingChecks($agreement);

        return [
            'agreement'     => $agreement,
            'agreed'        => $agreement->agreedTotal(),
            'delivered'     => $agreement->deliveredTotal(),
            'paid'          => AgreementBalances::paid($agreement),
            'balance'       => AgreementBalances::balance($agreement),
            'remaining'     => $agreement->remainingTotal(),
            'pendingTotal'  => (float) $pending->sum('amount'),
            'pendingNext'   => $pending->first()?->due_date,
            'pendingCount'  => $pending->count(),
            'deliveries'    => $deliveries,
            'payments'      => $payments,
            'projects'      => $projects,
            'items'         => $items,
            'byProject'     => $byProject,
            // Gelen mallar tablosunun altı: anahtar 'proje|kalem' ('' = hepsi; '|' = Gelen), iade düşülür.
            'deliveryTotals' => $deliveryTotals,
        ];
    }

    /** Toplam satırı için yazıyla (kullanıcı 27.10): 832000 → "sekiz yüz otuz iki bin lira", 1250.5 → "bin iki yüz elli lira elli kuruş". */
    public static function words(float $amount): string
    {
        $amount = round(abs($amount), 2);
        $kurus = (int) round(($amount - floor($amount)) * 100);

        return Money::words($amount) . ' lira' . ($kurus > 0 ? ' ' . Money::words($kurus) . ' kuruş' : '');
    }

    /** Alpine seçimi için proje anahtarı ('p12'; projesiz teslimat 'none'). */
    private static function projectKey(?int $projectId): string
    {
        return $projectId ? 'p' . $projectId : 'none';
    }

    /** Bakiye hücresi: alışta "55.000" / "15.000 alacaklısın", satışta "55.000 alacağın" / "15.000 borçlusun", sıfırsa "0 ✅". $lira: "55.000 ₺". */
    public static function balanceText(float $balance, bool $sale, bool $lira = false): string
    {
        if (abs($balance) < 0.01) {
            return '0 ✅';
        }
        $amount = AgreementBalances::money($balance) . ($lira ? ' ₺' : '');
        if ($sale) {
            return $amount . ($balance > 0 ? ' alacağın' : ' borçlusun');
        }

        return $balance > 0 ? $amount : $amount . ' alacaklısın';
    }
}
