<?php

namespace App\Services\Whatsapp;

use App\Models\Agreement;
use App\Models\AgreementItem;
use App\Models\Party;
use App\Support\Money;
use App\Support\PartyBalances;

/**
 * Anlaşma sorusu (21.10): "fayansta ne kaldı" → ilgili kalemler; "Ali Usta'yla ne durumdayız" → carinin açık anlaşmaları + bakiye;
 * "anlaşmalarım" → açık anlaşma listesi. Hangi kalem / kişi: AI seçer (query_item_ids / party_id); rakamlar KODDAN. Kayıt yok.
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
            $agreements = Agreement::where('party_id', $party->id)->where('status', Agreement::STATUS_OPEN)->orderBy('agreement_date')->get()
                ->each(fn (Agreement $a) => $a->loadForCard());
            $balance = PartyBalances::line('Bakiye', (float) TurnTrace::statement('AgreementQuery::answer', $party)['balance']);
            if ($agreements->isEmpty()) {
                return $party->name . " ile açık anlaşma yok.\n" . $balance;
            }

            return $agreements->map(fn (Agreement $a) => '🤝 ' . $party->name . ' — ' . $a->title . "\n"
                . $a->items->reject(fn (AgreementItem $i) => $i->isClosed())->take(self::MAX_ITEMS)->map(fn (AgreementItem $i) => self::itemLine($i))->implode("\n"))
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
