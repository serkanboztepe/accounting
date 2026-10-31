<?php

namespace App\Services\Whatsapp;

use App\Models\Agreement;
use App\Models\AgreementItem;
use App\Models\Party;
use App\Models\Project;
use App\Support\AgreementDesk;
use App\Support\Money;
use App\Support\PartyBalances;

/**
 * WhatsApp'tan anlaşmadan teslim (21.10): "Kuşaktan 44,5 m³ beton geldi" / "Ali Usta tesisatın yarısını bitirdi" → açık anlaşma
 * kalemine bağlı alış (satışta "teslim ettim" → satış). Hangi kalem: AI anlamdan seçer (bağlamda AÇIK ANLAŞMALAR); belirsizse
 * numaralı soru (tahmin yok). Kullanıcı kararları: tek eşleşmede sormadan "anlaşmadan" yazılır, fiyat farkında 3 seçenek,
 * götürüde yüzde. Kayıt AgreementDesk::deliver (proje başına alış/satış belgesi; panelle aynı yol).
 */
class AgreementDeliveryDraft
{
    private const CONTEXT_LIMIT = 60;

    public static function handles(array $d): bool
    {
        return ($d['kind'] ?? null) === ExpenseExtractor::KIND_AGREEMENT_DELIVERY;
    }

    /** Bağlam satırları: "  57: Kuşak Beton — Beton · Yeşilyurt — Beton — kalan 455,5 m³ × 3.800 (alış)". */
    public static function contextLines(): array
    {
        $items = AgreementItem::query()
            ->with(['agreement.party', 'agreement.project', 'product.unit', 'movements'])
            ->whereNull('closed_at')
            ->whereHas('agreement', fn ($q) => $q->where('status', Agreement::STATUS_OPEN)
                // Deneme seti (ExpenseContext::$only): yalnız denemenin carileri + konuşmada açılanlar (31.10: yeni cariyle yapılan
                // anlaşma listede yoktu, "Alime 500 tuğla gitti" kalem bulamadı) — canlıda null.
                ->when(ExpenseContext::$only !== null, fn ($w) => $w->where(fn ($p) => $p->whereIn('party_id', ExpenseContext::$only['parties'] ?? [])
                    ->when(isset(ExpenseContext::$only['_after']['parties']), fn ($n) => $n->orWhere('party_id', '>', ExpenseContext::$only['_after']['parties'])))))
            ->latest('id')->limit(self::CONTEXT_LIMIT * 2)->get()
            ->filter(fn (AgreementItem $i) => $i->isList() || $i->remainingQuantity() > 0.0001)
            ->take(self::CONTEXT_LIMIT)
            ->sortBy(fn (AgreementItem $i) => [$i->agreement->party?->name, $i->agreement_id, $i->id]);

        return $items->map(fn (AgreementItem $i) => sprintf('  %d: %s — %s — %s — %s (%s)', $i->id, $i->agreement->party?->name,
            $i->agreement->title, $i->name(), self::stateText($i), $i->agreement->isPurchase() ? 'alış' : 'satış'))->values()->all();
    }

    public static function sanitize(array $input): array
    {
        $lines = [];
        foreach ((array) ($input['delivery_lines'] ?? []) as $row) {
            $num = fn ($v) => is_numeric($v) && (float) $v > 0 ? round((float) $v, 2) : null;
            $lines[] = [
                'agreement_item_id' => ! empty($row['agreement_item_id']) ? (int) $row['agreement_item_id'] : null,
                'quantity'          => $num($row['quantity'] ?? null),
                'amount'            => $num($row['amount'] ?? null),
                'unit_price'        => $num($row['unit_price'] ?? null),
                'description'       => is_string($row['description'] ?? null) ? mb_substr(trim($row['description']), 0, 80) : null,
            ];
        }

        return [
            'delivery_lines'      => $lines,
            'delivery_candidates' => array_values(array_unique(array_map('intval', array_filter((array) ($input['delivery_candidates'] ?? []), 'is_numeric')))),
        ];
    }

    /**
     * Kod denetimi: kalem gerçekten açık mı, hepsi aynı carinin aynı yönde mi; cari kalemden gelir. Uymayan kalem düşer —
     * kalem kalmazsa (AI listeden olmayan id verdi) aday sorusu ya da normal kayda dönüş.
     */
    public static function prepare(array $d): array
    {
        $lines = [];
        $partyId = null;
        $direction = null;
        foreach ($d['delivery_lines'] ?? [] as $line) {
            if ($line['agreement_item_id'] === null) {
                $lines[] = $line;

                continue;
            }
            $item = self::openItem($line['agreement_item_id']);
            if (! $item || ($partyId !== null && $item->agreement->party_id !== $partyId)) {
                continue;
            }
            $partyId = $item->agreement->party_id;
            $direction = $item->agreement->direction;
            $lines[] = $line;
        }
        // Belirsiz kalem: adaylar da açık ve aynı caride olmalı.
        $candidates = array_values(array_filter($d['delivery_candidates'] ?? [], function ($id) use ($partyId) {
            $item = self::openItem($id);

            return $item && ($partyId === null || $item->agreement->party_id === $partyId);
        }));
        if ($partyId === null && $candidates !== []) {
            $first = self::openItem($candidates[0]);
            $partyId = $first->agreement->party_id;
            $direction = $first->agreement->direction;
        }
        // Miktarlı kalemde yalnız tutar söylendi ("Kamuran 500 binlik iş yaptı"): miktar = tutar ÷ fiyat, küsurat kalır (1.666,67 m²);
        // tutar aynen yazılır (kullanıcı 28.10 — önce adet ısrarı vardı).
        foreach ($lines as $k => $line) {
            $item = self::openItem($line['agreement_item_id']);
            $price = $line['unit_price'] ?? ($item ? (float) $item->unit_price : 0.0);
            if ($item && ! $item->is_lump && $line['quantity'] === null && $line['amount'] !== null && $price > 0) {
                $lines[$k]['quantity'] = round($line['amount'] / $price, 2);
                $lines[$k]['from_amount'] = true;
            }
        }
        $d['delivery_lines'] = $lines;
        $d['delivery_candidates'] = $candidates;
        $d['party_id'] = $partyId ?? ($d['party_id'] ?? null);
        // Kalem bulunmadıysa: tek yönlü firmada o yön (yalnız satış yapan tuğlacıda "5 bin tuğla gönderdim" satış, 31.10).
        $d['delivery_direction'] = $direction ?? \App\Support\Features::agreementFixedDirection() ?? ($d['delivery_direction'] ?? Agreement::DIRECTION_PURCHASE);

        return $d;
    }

    /** Sırayla: hangi kalem (numaralı) → miktar → fiyat farkı (numaralı). null = kart onaya hazır. */
    public static function question(array $d): ?string
    {
        foreach ($d['delivery_lines'] ?? [] as $line) {
            if ($line['agreement_item_id'] === null) {
                $candidates = array_map(fn ($id) => self::openItem($id), $d['delivery_candidates'] ?? []);
                $candidates = array_values(array_filter($candidates));
                if ($candidates === []) {
                    return 'Bu mal hangi anlaşmadan geldi? Anlaşmanın adını ya da kişinin / firmanın adını yazar mısın?';
                }
                // Yalnız kalem adları (kullanıcı 28.10: "10 açık anlaşma" + anlaşma adı/kalan listesi kafa karıştırdı). Aynı ad
                // birden çok kez geçerse yanına proje (yoksa anlaşma adı) — ayırt edilsin diye.
                $party = $candidates[0]->agreement->party?->name;
                $names = collect($candidates)->map(fn (AgreementItem $i) => $i->name());
                $menu = collect($candidates)->map(fn (AgreementItem $i, $k) => '*' . ($k + 1) . '* ' . $i->name()
                    . ($names->filter(fn ($n) => $n === $i->name())->count() > 1 ? ' · ' . ($i->agreement->project?->name ?? $i->agreement->title) : ''))
                    ->implode("\n");

                return ($party ? '*' . $party . '* — ' : '') . "hangi iş / mal?\n" . $menu . "\n*0* Anlaşma dışı";
            }
        }
        foreach ($d['delivery_lines'] ?? [] as $line) {
            $item = self::openItem($line['agreement_item_id']);
            if ($item && ! $item->is_lump && $line['quantity'] === null) {
                return 'Kaç ' . ($item->unitName() ?: 'adet') . ' ' . $item->name() . ' ' . self::verb($item) . '?';
            }
            if ($item && $item->is_lump && $line['amount'] === null) {
                return $item->name() . ' işinin ne kadarı yapıldı? (ör. "yarısı" ya da "300 bin")';
            }
        }
        if (($line = self::priceDiffLine($d)) !== null) {
            $item = self::openItem($line['agreement_item_id']);

            return 'Anlaşmadaki fiyat ' . Money::format((float) $item->unit_price) . ' ₺, sen ' . Money::format($line['unit_price']) . " yazdın.\n"
                . '*1* Yalnız bu teslimat ' . Money::format($line['unit_price']) . "\n"
                . '*2* Bundan sonra hepsi ' . Money::format($line['unit_price']) . " (zam)\n"
                . '*3* Anlaşma fiyatı ' . Money::format((float) $item->unit_price) . ' kalsın';
        }

        return null;
    }

    /** Numaralı cevap (AI'sız): aday seçimi ya da fiyat seçimi. null = bu soruya cevap değil; ['cancel' => metin] = anlaşma dışı. */
    public static function choose(array $d, int $n): ?array
    {
        foreach ($d['delivery_lines'] ?? [] as $k => $line) {
            if ($line['agreement_item_id'] === null) {
                if ($n === 0) {
                    return ['cancel' => 'Tamam, anlaşmadan düşmedim. Anlaşma dışı alış olarak yazmak için tutarıyla yaz (ör. "Kuşaktan 20 m³ beton aldım 80 bin").'];
                }
                $id = $d['delivery_candidates'][$n - 1] ?? null;
                if ($id === null) {
                    return null;
                }
                $d['delivery_lines'][$k]['agreement_item_id'] = (int) $id;
                $d['delivery_candidates'] = [];

                return self::prepare($d);
            }
        }
        if (($line = self::priceDiffLine($d)) !== null && in_array($n, [1, 2, 3], true)) {
            $k = array_search($line, $d['delivery_lines'], true);
            $d['delivery_lines'][$k]['price_choice'] = $n;
            if ($n === 3) {
                $d['delivery_lines'][$k]['unit_price'] = null;
            }

            return $d;
        }

        return null;
    }

    /**
     * "Kaç m³ geldi?" / "ne kadarı yapıldı?" sorusuna yalnız sayı ("44", "44,5", "300 bin"): eksik miktar ya da götürü tutar.
     * $money: cevap parayla yazıldı ("500 bin", "500.000 tl") — miktarlı kalemde tutar sayılır, miktar fiyattan hesaplanır.
     */
    public static function fillNumber(array $d, float $value, bool $money = false): ?array
    {
        if ($value <= 0 || self::awaitsNumber($d)) {
            return null;
        }
        foreach ($d['delivery_lines'] ?? [] as $k => $line) {
            $item = self::openItem($line['agreement_item_id']);
            if ($item && ! $item->is_lump && $line['quantity'] === null) {
                if ($money) {
                    $d['delivery_lines'][$k]['amount'] = $value;

                    return self::prepare($d);
                }
                $d['delivery_lines'][$k]['quantity'] = $value;

                return $d;
            }
            if ($item && $item->is_lump && $line['amount'] === null) {
                $d['delivery_lines'][$k]['amount'] = $value;

                return $d;
            }
        }

        return null;
    }

    /** Menü gösterildi mi (numaralı cevap bekleniyor). */
    /** Teslim edilen ilk kalemin anlaşmasının projesi (menüde "(anlaşmadaki)" işareti). */
    public static function agreementProjectId(array $d): ?int
    {
        foreach ($d['delivery_lines'] ?? [] as $line) {
            $item = $line['agreement_item_id'] ? self::openItem($line['agreement_item_id']) : null;
            if ($item && $item->agreement->project_id) {
                return (int) $item->agreement->project_id;
            }
        }

        return null;
    }

    /** Teslim edilen kalemlerden biri projesiz anlaşmada mı — o zaman proje sorulur (ProjectChoice). */
    public static function withoutProject(array $d): bool
    {
        foreach ($d['delivery_lines'] ?? [] as $line) {
            $item = $line['agreement_item_id'] ? self::openItem($line['agreement_item_id']) : null;
            if ($item && $item->agreement->project_id === null) {
                return true;
            }
        }

        return false;
    }

    public static function awaitsNumber(array $d): bool
    {
        $q = self::question($d);

        return $q !== null && str_contains($q, '*1*');
    }

    /**
     * Kısa kart:
     *   📥 *Kuşak Beton* — anlaşmadan
     *   • Beton 44,5 m³ × 3.800 = 169.100 · Yeşilyurt — kalan 255,5 m³
     *   Sonra: borcun 30.900 ₺
     */
    public static function summary(array $d): string
    {
        $party = ! empty($d['party_id']) ? Party::find($d['party_id']) : null;
        $isSale = ($d['delivery_direction'] ?? null) === Agreement::DIRECTION_SALE;
        $lines = [($isSale ? '📤 *' : '📥 *') . ($party?->name ?? '?') . '* — anlaşmadan'];
        $total = 0.0;
        foreach ($d['delivery_lines'] ?? [] as $line) {
            $item = self::openItem($line['agreement_item_id']);
            if (! $item) {
                continue;
            }
            [$text, $amount] = self::lineText($item, $line, $d);
            $lines[] = '• ' . $text;
            $total += $amount;
        }
        if (count($lines) > 2) {
            $lines[] = 'Toplam ' . Money::format($total) . ' ₺';
        }
        if (! empty($d['paid'])) {
            $lines[] = $isSale ? '💰 Parası alındı (peşin) — bakiye değişmez' : '💸 Peşin ödendi — bakiye değişmez';
        } elseif ($party) {
            $before = (float) TurnTrace::statement('AgreementDeliveryDraft::summary', $party)['balance'];
            $after = $isSale ? $before + $total : $before - $total;
            $lines[] = PartyBalances::line('Sonra', $after);
        }

        return implode("\n", $lines);
    }

    /** @return array{0:string, 1:\Illuminate\Support\Collection} kayıt metni + belgeler */
    public static function commit(array $d): array
    {
        $party = Party::findOrFail($d['party_id']);
        $direction = ($d['delivery_direction'] ?? null) === Agreement::DIRECTION_SALE ? Agreement::DIRECTION_SALE : Agreement::DIRECTION_PURCHASE;
        $lines = [];
        $applyToRest = false;
        foreach ($d['delivery_lines'] ?? [] as $line) {
            $lines[] = [
                'agreement_item_id' => $line['agreement_item_id'],
                'quantity'          => $line['quantity'],
                'amount'            => $line['amount'],
                'unit_price'        => $line['unit_price'],
                'total'             => ! empty($line['from_amount']) ? $line['amount'] : null,
            ];
            $applyToRest = $applyToRest || ($line['price_choice'] ?? null) === 2;
        }
        // Seçilen proje · menüden "0 = Genel" · yoksa her kalem kendi anlaşmasının projesine.
        $project = match (true) {
            ! empty($d['project_id']) && Project::whereKey($d['project_id'])->exists() => (int) $d['project_id'],
            ! empty($d['project_name']) => Project::firstOrCreate(['name' => trim($d['project_name'])], ['status' => 'active'])->id,
            ! empty($d['project_choice_done']) => null,
            default => 'agreement',
        };
        $paid = ! empty($d['paid']);
        $result = AgreementDesk::deliver($party, $direction, $lines, $d['date'] ?? now()->toDateString(), $project, $applyToRest,
            null, $paid && $direction === Agreement::DIRECTION_PURCHASE, $d['payment_type'] ?? null);
        // Satışta parası alındıysa: tahsilat (alışta peşin, TradeDesk'te alış + ödeme olarak yazılır).
        if ($paid && $direction === Agreement::DIRECTION_SALE) {
            foreach ($result['documents'] as $doc) {
                \App\Models\PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => $doc->sale_date, 'amount' => $doc->total_amount,
                    'agreement_id' => \App\Support\AgreementBalances::agreementOfDocument('sale_id', $doc->id),
                    'type' => \App\Models\PartyLedgerEntry::TYPE_COLLECTION, 'payment_type' => $d['payment_type'] ?? 'cash',
                    'description' => 'Peşin tahsilat — Satış #' . $doc->id]);
            }
        }

        $total = $result['documents']->sum(fn ($doc) => (float) $doc->total_amount);
        $rest = collect($d['delivery_lines'])->map(function ($line) {
            $item = AgreementItem::with(['product.unit', 'movements'])->find($line['agreement_item_id']);

            return $item && ! $item->isList() ? $item->name() . ' kalan ' . ($item->is_lump
                ? Money::format($item->remainingAmount()) . ' ₺'
                : $item->quantityLabel(max(0, (float) $item->remainingQuantity()))) : null;
        })->filter()->implode(' · ');

        $text = '✅ Kaydedildi: ' . Money::format($total) . ' ₺ ' . ($direction === Agreement::DIRECTION_SALE ? 'satış' : 'alış') . ' — anlaşmadan düşüldü.'
            . ($rest !== '' ? "\n" . $rest : '')
            . ($result['over'] ? "\n⚠ " . implode("\n⚠ ", $result['over']) : '')
            . "\n" . PartyBalances::line($party->name, (float) TurnTrace::statement('AgreementDeliveryDraft::commit', $party->fresh())['balance']);

        return [$text, $result['documents']];
    }

    private static function lineText(AgreementItem $item, array $line, array $d): array
    {
        $project = ! empty($d['project_id']) ? Project::find($d['project_id'])?->name : $item->agreement->project?->name;
        $where = $project ? ' · ' . $project : '';
        if ($item->is_lump) {
            // Götürüde "%N" (toplamın yüzde kaçı geldi) yazılmaz — teslimin kendisi sanılıyordu (25.10 kullanım incelemesi).
            $amount = (float) $line['amount'];

            return [$item->name() . ' · ' . Money::format($amount) . ' ₺' . $where, $amount];
        }
        $own = $line['unit_price'] !== null && ($line['price_choice'] ?? 1) !== 3 && abs($line['unit_price'] - (float) $item->unit_price) > 0.001;
        $price = $own ? $line['unit_price'] : (float) $item->unit_price;
        $qty = (float) $line['quantity'];
        // Anlaşma fiyatıyla: kayıttaki hesabın aynısı (kuruşsuz fiyat, son teslim kalanı alır — AgreementItem::amountFor).
        $amount = match (true) {
            ! empty($line['from_amount']) => (float) $line['amount'],
            $own => round($qty * $price, 2),
            default => $item->amountFor($qty),
        };
        $rest = $item->isList() ? null : (float) $item->quantity - $item->deliveredQuantity() - $qty;

        return [$item->name() . ' ' . $item->quantityLabel($qty) . ' × ' . Money::format($price) . ' = ' . Money::format($amount) . $where
            . ($rest !== null ? ' — kalan ' . ($rest < 0 ? '⚠ ' . $item->quantityLabel(abs($rest)) . ' fazla' : $item->quantityLabel($rest)) : ''), $amount];
    }

    /** Fiyatı anlaşmadan farklı yazılmış ve henüz seçim yapılmamış satır. */
    private static function priceDiffLine(array $d): ?array
    {
        foreach ($d['delivery_lines'] ?? [] as $line) {
            $item = self::openItem($line['agreement_item_id']);
            if ($item && ! $item->is_lump && $line['unit_price'] !== null && ! isset($line['price_choice'])
                && abs($line['unit_price'] - (float) $item->unit_price) > 0.001) {
                return $line;
            }
        }

        return null;
    }

    private static function stateText(AgreementItem $i): string
    {
        return match (true) {
            $i->is_lump  => 'götürü ' . Money::format((float) $i->unit_price) . ', yapılan %' . (int) round($i->deliveredQuantity() * 100),
            $i->isList() => 'fiyat listesi ' . Money::format((float) $i->unit_price) . ($i->unitName() ? '/' . $i->unitName() : ''),
            default      => 'kalan ' . $i->quantityLabel((float) $i->remainingQuantity()) . ' × ' . Money::format((float) $i->unit_price),
        };
    }

    private static function verb(AgreementItem $i): string
    {
        return $i->agreement->isPurchase() ? 'geldi' : 'teslim edildi';
    }

    private static function openItem(?int $id): ?AgreementItem
    {
        if (! $id) {
            return null;
        }
        $item = AgreementItem::with(['agreement.party', 'agreement.project', 'product.unit', 'movements'])->find($id);

        return $item && $item->closed_at === null && $item->agreement && $item->agreement->status !== Agreement::STATUS_CLOSED ? $item : null;
    }
}
