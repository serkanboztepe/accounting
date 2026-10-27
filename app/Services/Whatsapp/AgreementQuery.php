<?php

namespace App\Services\Whatsapp;

use App\Models\Agreement;
use App\Models\AgreementItem;
use App\Models\Party;
use App\Models\Project;
use App\Models\StockMovement;
use App\Support\AgreementBalances;
use App\Support\Money;
use App\Support\PartyBalances;

/**
 * Anlaşma sorusu (21.10): "fayansta ne kaldı" → ilgili kalemler; "Ali Usta'yla ne durumdayız" → carinin anlaşmaları + gelen / ödenen /
 * borç (27.10, AgreementBalances — panelle aynı) + bakiye; "Kamuran'dan A Blok için neler aldık" (party_id + project_id, 27.10) → o
 * projeye gelen kalemler; "anlaşmalarım" → açık anlaşma listesi. Hangi kalem / kişi / proje: AI seçer; rakamlar KODDAN. Kayıt yok.
 */
class AgreementQuery
{
    private const MAX_ITEMS = 4;

    private const MAX_LIST = 10;

    public static function handles(array $d): bool
    {
        return ($d['kind'] ?? null) === ExpenseExtractor::KIND_AGREEMENT_QUERY;
    }

    public static function answer(array $d): string
    {
        if (! empty($d['query_item_ids'])) {
            $items = AgreementItem::with(['agreement.party', 'product.unit', 'movements'])->whereIn('id', $d['query_item_ids'])
                ->whereNull('closed_at')->get();
            if ($items->isNotEmpty()) {
                return $items->groupBy('agreement_id')->map(fn ($rows) => '🤝 ' . $rows->first()->agreement->title
                    . ' (' . $rows->first()->agreement->party?->name . ")\n" . $rows->map(fn (AgreementItem $i) => self::itemLine($i))->implode("\n"))
                    ->implode("\n\n");
            }
        }

        if (! empty($d['party_id']) && ($party = Party::find($d['party_id']))) {
            if (! empty($d['project_id']) && ($project = Project::find($d['project_id']))) {
                return self::projectAnswer($party, $project);
            }
            $partyBalance = (float) TurnTrace::statement('AgreementQuery::answer', $party)['balance'];
            $balance = PartyBalances::line('Bakiye', $partyBalance);
            // Açık anlaşmalar + bitmiş ama hesabı kapanmamışlar (arşivdekiler hariç — panelle aynı).
            $rows = collect(AgreementBalances::forParty($party, $partyBalance)['rows'])
                ->reject(fn (array $r) => $r['archived'])->sortBy(fn (array $r) => $r['agreement']->agreement_date)->values();
            if ($rows->isEmpty()) {
                return $party->name . " ile açık anlaşma yok.\n" . $balance;
            }

            return $rows->map(fn (array $r) => '🤝 ' . $party->name . ' — ' . $r['agreement']->title . "\n"
                . $r['agreement']->items->reject(fn (AgreementItem $i) => $i->isClosed())->take(self::MAX_ITEMS)
                    ->map(fn (AgreementItem $i) => self::itemLine($i))->push(self::moneyLine($r))->implode("\n"))
                ->implode("\n\n") . "\n" . $balance;
        }

        $open = Agreement::with('party')->where('status', Agreement::STATUS_OPEN)->orderBy('agreement_date')->limit(self::MAX_LIST + 1)->get()
            ->each(fn (Agreement $a) => $a->loadForCard());
        if ($open->isEmpty()) {
            return 'Açık anlaşma yok.';
        }

        return "🤝 Açık anlaşmalar:\n" . $open->take(self::MAX_LIST)->map(fn (Agreement $a) => '• ' . $a->title . ' — ' . $a->party?->name
            . ($a->agreedTotal() > 0 ? ' · kalan ' . Money::format($a->remainingTotal()) . ' ₺' : ' · fiyat listesi'))->implode("\n")
            . ($open->count() > self::MAX_LIST ? "\n…ve diğerleri (panelde Anlaşmalar)" : '');
    }

    /** "Gelen 4.455.950 · Ödenen 6.200.000 → 1.744.050 alacaklısın" (+ bekleyen çek). Bakiye = gelen − ödenen (AgreementBalances). */
    private static function moneyLine(array $row): string
    {
        $a = $row['agreement'];
        $sale = ! $a->isPurchase();
        $balance = (float) $row['balance'];
        $line = ($sale ? 'Teslim edilen ' : 'Gelen ') . AgreementBalances::money($a->deliveredTotal()) . ' · '
            . ($sale ? 'Tahsil edilen ' : 'Ödenen ') . AgreementBalances::money($row['paid']) . ' → ' . match (true) {
                abs($balance) < 0.01 => 'hesap kapalı ✅',
                $balance > 0 => AgreementBalances::money($balance) . ($sale ? ' alacağın' : ' borcun'),
                default => AgreementBalances::money($balance) . ($sale ? ' borçlusun' : ' alacaklısın'),
            };
        $pending = AgreementBalances::pendingChecks($a);
        if ($pending->isNotEmpty()) {
            $line .= "\nBekleyen çek: " . $pending->count() . ' adet ' . AgreementBalances::money((float) $pending->sum('amount'))
                . ' (ilk vade ' . $pending->first()->due_date?->format('d.m') . ')';
        }

        return $line;
    }

    /**
     * "Kamuran'dan A Blok için neler aldık": carinin anlaşma kalemlerinden o projeye gelenler (miktar · tutar, iade düşülür) +
     * anlaşma dışı alışların toplamı. Ödeme projeye bağlı olmadığı için burada borç yok.
     */
    private static function projectAnswer(Party $party, Project $project): string
    {
        $agreements = Agreement::where('party_id', $party->id)->orderBy('agreement_date')->get()->each(fn (Agreement $a) => $a->loadForCard());
        $blocks = [];
        $total = 0.0;
        foreach ($agreements as $a) {
            $lines = [];
            foreach ($a->items as $item) {
                $moves = $item->movements->where('project_id', $project->id);
                if ($moves->isEmpty()) {
                    continue;
                }
                $sign = fn (StockMovement $m) => $m->reason === StockMovement::REASON_RETURN ? -1 : 1;
                $qty = $moves->sum(fn (StockMovement $m) => $sign($m) * (float) $m->quantity);
                $amount = round($moves->sum(fn (StockMovement $m) => $sign($m) * (float) $m->amount), 2);
                $total += $amount;
                $lines[] = '• ' . $item->name() . ': ' . ($item->is_lump ? '' : $item->quantityLabel($qty) . ' · ') . Money::format($amount) . ' ₺';
            }
            if ($lines !== []) {
                $blocks[] = '🤝 ' . $a->title . "\n" . implode("\n", $lines);
            }
        }
        $outside = round((float) StockMovement::where('party_id', $party->id)->where('project_id', $project->id)
            ->whereNull('agreement_item_id')->whereNotNull('purchase_id')->where('reason', StockMovement::REASON_PURCHASE)->sum('amount'), 2);
        $came = $agreements->first()?->isPurchase() === false ? 'teslim edilmemiş' : 'mal gelmemiş';
        if ($blocks === [] && $outside < 0.01) {
            return $party->name . ' → ' . $project->name . ': anlaşmalardan ' . $came . '.';
        }

        return '📍 ' . $party->name . ' · ' . $project->name . "\n" . implode("\n", $blocks)
            . ($outside >= 0.01 ? "\nAnlaşma dışı alış: " . Money::format($outside) . ' ₺' : '')
            . "\nToplam " . Money::format($total + $outside) . ' ₺';
    }

    /** "Fayans: 1.000 m²'den 250 geldi, kalan 750 m²" / "Tesisat: %50 yapıldı, kalan 500.000 ₺" / "Tuğla: 5.000 adet geldi (8,50 ₺/adet)". */
    private static function itemLine(AgreementItem $i): string
    {
        $came = $i->agreement->isPurchase() ? 'geldi' : 'teslim edildi';

        return $i->name() . ': ' . match (true) {
            $i->is_lump  => '%' . (int) round($i->deliveredQuantity() * 100) . ' yapıldı (' . Money::format($i->deliveredAmount()) . '), kalan '
                . Money::format($i->remainingAmount()) . ' ₺',
            $i->isList() => $i->quantityLabel($i->deliveredQuantity()) . ' ' . $came . ' (' . Money::format((float) $i->unit_price) . ' ₺'
                . ($i->unitName() ? '/' . $i->unitName() : '') . ')',
            default      => $i->quantityLabel((float) $i->quantity) . "'den " . $i->quantityLabel($i->deliveredQuantity()) . ' ' . $came
                . ', kalan ' . $i->quantityLabel(max(0, (float) $i->remainingQuantity())),
        };
    }
}
