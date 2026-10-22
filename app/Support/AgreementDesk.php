<?php

namespace App\Support;

use App\Models\Agreement;
use App\Models\AgreementItem;
use App\Models\Check;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use App\Models\Project;
use App\Models\StockMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anlaşmaların iş mantığı (panel; ileride WhatsApp da buradan). Kurallar (kullanıcı 21.10):
 * - Anlaşma borç yazmaz. Peşinat/kapora cariye normal ödeme/tahsilat olarak yazılır (anlaşmaya bağ yok).
 * - Tür kalemden anlaşılır: miktar varsa miktarlı · yalnız tutar götürü · fiyat var miktar yok fiyat listesi.
 * - Teslimat = normal alış/satış belgesi (TradeDesk); kalemi anlaşma kalemine bağlı. Proje başına bir belge.
 * - Zam: eski kalem gelen miktarla kapanır, kalan yeni fiyatla yeni kalem olur (geçmiş teslimat değişmez).
 */
class AgreementDesk
{
    /**
     * @param  array{direction:string, agreement_date?:string, project_id?:int|null, title?:string|null, budget?:mixed,
     *               notes?:string|null, lines:array, advance?:bool, advance_amount?:mixed, advance_payment_type?:string|null,
     *               check_number?:string|null, bank_name?:string|null, check_due_date?:string|null}  $data
     */
    public static function create(Party $party, array $data): Agreement
    {
        $lines = self::normalizeLines($data['lines'] ?? []);
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'En az bir kalem yaz: ne için anlaştınız?']);
        }
        $advance = ! empty($data['advance']) ? Money::parse($data['advance_amount'] ?? null) : 0.0;
        if (! empty($data['advance']) && $advance <= 0) {
            throw ValidationException::withMessages(['advance_amount' => 'Peşinat tutarını yaz.']);
        }

        return DB::transaction(function () use ($party, $data, $lines, $advance) {
            $direction = $data['direction'] === Agreement::DIRECTION_SALE ? Agreement::DIRECTION_SALE : Agreement::DIRECTION_PURCHASE;
            $projectId = $data['project_id'] ?? null;
            $budget = Money::parse($data['budget'] ?? null);

            $agreement = Agreement::create([
                'party_id'       => $party->id,
                'direction'      => $direction,
                'project_id'     => $projectId,
                'title'          => filled($data['title'] ?? null) ? trim($data['title']) : self::autoTitle($lines, $projectId),
                'agreement_date' => $data['agreement_date'] ?? now()->toDateString(),
                'budget'         => $budget > 0 ? $budget : null,
                'status'         => Agreement::STATUS_OPEN,
                'notes'          => $data['notes'] ?? null,
            ]);
            self::insertItems($agreement, $lines);

            if ($advance > 0) {
                self::recordAdvance($agreement, $advance, $data);
            }

            return $agreement->fresh();
        });
    }

    /** Var olan anlaşmaya kalem ekle (fayansa klozet de eklendi). */
    public static function addItems(Agreement $agreement, array $rawLines): void
    {
        $lines = self::normalizeLines($rawLines);
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Eklenecek kalemi yaz.']);
        }
        DB::transaction(function () use ($agreement, $lines) {
            self::insertItems($agreement, $lines);
            if ($agreement->status === Agreement::STATUS_DONE) {
                $agreement->update(['status' => Agreement::STATUS_OPEN]);
            }
        });
    }

    /**
     * Teslim aldım / Teslim ettim. $lines: [['agreement_item_id'=>, 'quantity'=>, 'amount'=> (götürü), 'unit_price'=>], ...]
     * $projectChoice: 'agreement' (her kalem kendi anlaşmasının projesine) · proje id · '' / null (Genel).
     * $applyPriceToRest: teslimde fiyat anlaşmadan farklıysa kalanların fiyatı da değişir.
     * $paid (yalnız alış): Alım Yaptım'da "Peşin" — alış + ödeme birlikte (bakiye değişmez). Satışta tahsilatı pencere yazar.
     *
     * @return array{documents: Collection, over: string[]}  belgeler + anlaşmadan fazla gelen kalemler (uyarı)
     */
    public static function deliver(Party $party, string $direction, array $lines, string $date, mixed $projectChoice = 'agreement',
        bool $applyPriceToRest = false, ?string $notes = null, bool $paid = false, ?string $paymentType = null): array
    {
        $prepared = [];
        foreach ($lines as $line) {
            $item = AgreementItem::with('agreement', 'product')->find($line['agreement_item_id'] ?? null);
            if (! $item || $item->agreement->party_id !== $party->id || $item->agreement->direction !== $direction || $item->isClosed()) {
                continue;
            }
            $price = blank($line['unit_price'] ?? null) ? (float) $item->unit_price : Money::parse($line['unit_price']);
            if ($item->is_lump) {
                $amount = Money::parse($line['amount'] ?? null);
                if ($amount <= 0) {
                    continue;
                }
                $total = (float) $item->unit_price;
                $qty = $total > 0 ? round($amount / $total, 2) : 1.0;
                $prepared[] = ['item' => $item, 'quantity' => max($qty, 0.01), 'unit_price' => null, 'amount' => $amount, 'new_price' => null];

                continue;
            }
            $qty = (float) str_replace(',', '.', (string) ($line['quantity'] ?? 0));
            if ($qty <= 0) {
                continue;
            }
            $changed = $item->unit_price !== null && abs($price - (float) $item->unit_price) > 0.001;
            $prepared[] = ['item' => $item, 'quantity' => $qty, 'unit_price' => $price, 'amount' => round($qty * $price, 2),
                'new_price' => $changed && $applyPriceToRest ? $price : null];
        }
        if ($prepared === []) {
            throw ValidationException::withMessages(['lines' => 'Gelen kalemi işaretle ve miktarını yaz.']);
        }

        return DB::transaction(function () use ($party, $direction, $prepared, $date, $projectChoice, $notes, $paid, $paymentType) {
            // Proje başına bir belge (maliyet teslimatın projesine yazılır).
            $groups = [];
            foreach ($prepared as $p) {
                $projectId = $projectChoice === 'agreement' ? $p['item']->agreement->project_id : ($projectChoice ?: null);
                $groups[(string) $projectId][] = $p;
            }

            $documents = collect();
            foreach ($groups as $projectId => $group) {
                $titles = collect($group)->map(fn ($p) => $p['item']->agreement->title)->unique()->implode(', ');
                $docLines = array_map(fn ($p) => [
                    'product_id'        => $p['item']->product_id,
                    'quantity'          => $p['quantity'],
                    'unit_price'        => $p['unit_price'],
                    'amount'            => $p['amount'],
                    'agreement_item_id' => $p['item']->id,
                ], $group);
                $common = [
                    'mode'       => 'party',
                    'party_id'   => $party->id,
                    'project_id' => $projectId !== '' ? (int) $projectId : null,
                    'lines'      => $docLines,
                    'notes'      => trim('Anlaşma: ' . $titles . ($notes ? ' — ' . $notes : '')),
                ];
                $documents->push($direction === Agreement::DIRECTION_SALE
                    ? TradeDesk::saveSale([...$common, 'sale_date' => $date])
                    : TradeDesk::savePurchase([...$common, 'purchase_date' => $date, 'keep_project' => true,
                        'mode' => $paid ? 'cash' : 'party', 'payment_type' => $paymentType]));
            }

            $over = [];
            foreach ($prepared as $p) {
                $item = $p['item']->fresh(['product.unit']);
                if ($p['new_price'] !== null) {
                    self::changePrice($item, $p['new_price']);
                }
                $rest = $item->remainingQuantity();
                if ($rest !== null && $rest < 0 && ! $item->is_lump) {
                    $over[] = $item->name() . ': anlaşmadan ' . $item->quantityLabel(abs($rest)) . ' fazla';
                } elseif ($item->is_lump && $rest !== null && $rest < 0) {
                    $over[] = $item->name() . ': götürü tutarı aşıldı';
                }
            }
            foreach (collect($prepared)->pluck('item.agreement_id')->unique() as $agreementId) {
                self::refreshStatus(Agreement::find($agreementId));
            }

            return ['documents' => $documents, 'over' => $over];
        });
    }

    /**
     * Fiyatı değiştir (zam): teslimat yoksa kalem fiyatı değişir; varsa eski kalem gelen miktarla kapanır,
     * kalan miktar yeni fiyatla yeni kalem olur. Götürüde toplam tutar değişir (gelen tutar aynı kalır).
     */
    public static function changePrice(AgreementItem $item, float $newPrice): void
    {
        if ($newPrice <= 0) {
            throw ValidationException::withMessages(['unit_price' => 'Yeni fiyatı yaz.']);
        }
        DB::transaction(function () use ($item, $newPrice) {
            $delivered = $item->deliveredQuantity();
            if ($item->is_lump || $delivered <= 0) {
                $item->update(['unit_price' => $newPrice, 'amount' => $item->isList() ? null : round((float) $item->quantity * $newPrice, 2)]);

                return;
            }
            $rest = $item->isList() ? null : round((float) $item->quantity - $delivered, 2);
            $item->update([
                'quantity'  => $item->isList() ? null : $delivered,
                'amount'    => $item->isList() ? null : round($delivered * (float) $item->unit_price, 2),
                'closed_at' => now(),
            ]);
            if ($rest === null || $rest > 0) {
                AgreementItem::create([
                    'agreement_id' => $item->agreement_id,
                    'product_id'   => $item->product_id,
                    'quantity'     => $rest,
                    'unit_price'   => $newPrice,
                    'amount'       => $rest === null ? null : round($rest * $newPrice, 2),
                    'sort'         => $item->sort,
                ]);
            }
        });
    }

    /** Teslimatı olmayan kalemi sil. */
    public static function deleteItem(AgreementItem $item): void
    {
        if ($item->movements()->exists()) {
            throw ValidationException::withMessages(['item' => 'Bu kaleme teslimat girilmiş, silinemez. Fiyatı değiştirebilir ya da anlaşmayı kapatabilirsin.']);
        }
        $agreement = $item->agreement;
        $item->delete();
        self::refreshStatus($agreement);
    }

    /** Anlaşmayı kapat (kalan borç doğurmaz) / yeniden aç. */
    public static function close(Agreement $agreement): void
    {
        $agreement->update(['status' => Agreement::STATUS_CLOSED]);
    }

    public static function reopen(Agreement $agreement): void
    {
        $agreement->update(['status' => Agreement::STATUS_OPEN]);
        self::refreshStatus($agreement);
    }

    /** Teslimatı olmayan anlaşma silinir; olan silinmez (önce teslimat belgeleri silinmeli). */
    public static function delete(Agreement $agreement): void
    {
        if ($agreement->hasDeliveries()) {
            throw ValidationException::withMessages(['agreement' => 'Bu anlaşmaya teslimat girilmiş, silinemez. Bitmediyse "Anlaşmayı kapat" de.']);
        }
        $agreement->delete();
    }

    /** Hepsi geldiyse "bitti", eksik varsa "açık" (elle kapatılmışa dokunmaz). Fiyat listesi kendiliğinden bitmez. */
    public static function refreshStatus(?Agreement $agreement): void
    {
        if (! $agreement || $agreement->status === Agreement::STATUS_CLOSED) {
            return;
        }
        $agreement->unsetRelation('items')->loadForCard();
        $open = $agreement->items->reject(fn (AgreementItem $i) => $i->isClosed());
        $done = $open->isNotEmpty()
            && $open->every(fn (AgreementItem $i) => ! $i->isList() && $i->remainingQuantity() <= 0.0001);
        $status = $done ? Agreement::STATUS_DONE : Agreement::STATUS_OPEN;
        if ($agreement->status !== $status) {
            $agreement->update(['status' => $status]);
        }
    }

    /** Bu carinin (ya da tek anlaşmanın) teslim bekleyen kalemleri — en eski anlaşma önce. */
    public static function openItems(Party $party, string $direction, ?int $agreementId = null): Collection
    {
        return AgreementItem::query()
            ->with(['agreement.project', 'product.unit', 'movements'])
            ->whereNull('closed_at')
            ->whereHas('agreement', fn ($q) => $q->where('party_id', $party->id)->where('direction', $direction)
                ->where('status', '!=', Agreement::STATUS_CLOSED)
                ->when($agreementId, fn ($q) => $q->whereKey($agreementId)))
            ->get()
            ->filter(fn (AgreementItem $i) => $i->isList() || $i->remainingQuantity() > 0.0001)
            ->sortBy(fn (AgreementItem $i) => [$i->agreement->agreement_date->timestamp, $i->agreement_id, $i->sort, $i->id])
            ->values();
    }

    /**
     * Form satırı → kalem. Kural: miktar varsa miktarlı (fiyat yoksa tutar ÷ miktar); miktar yok + tutar → götürü;
     * miktar yok + yalnız fiyat → fiyat listesi. Ürün kartı şart (yazılan ad için kart TradeLines'tan açılır).
     *
     * @return array<int, array{product_id:int, quantity:?float, unit_price:?float, amount:?float, is_lump:bool}>
     */
    public static function normalizeLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            if (! $productId) {
                continue;
            }
            $qty = blank($line['quantity'] ?? null) ? null : (float) str_replace(',', '.', (string) $line['quantity']);
            $price = Money::parse($line['unit_price'] ?? null);
            $amount = Money::parse($line['amount'] ?? null);
            $name = Product::find($productId)?->name ?? 'Kalem';

            if (! empty($line['free'])) { // bedelsiz kalem (sözleşmede "klozet kapağı 14 adet" fiyatsız): gelecek ama tutarı yok
                $out[] = ['product_id' => $productId, 'quantity' => $qty !== null && $qty > 0 ? $qty : 1.0, 'unit_price' => 0.0, 'amount' => 0.0, 'is_lump' => false];
            } elseif ($qty !== null && $qty > 0) {
                $price = $price > 0 ? $price : ($amount > 0 ? round($amount / $qty, 2) : 0.0);
                if ($price <= 0) {
                    throw ValidationException::withMessages(['lines' => $name . ': birim fiyat ya da tutar yaz.']);
                }
                $out[] = ['product_id' => $productId, 'quantity' => $qty, 'unit_price' => $price, 'amount' => round($qty * $price, 2), 'is_lump' => false];
            } elseif ($amount > 0) { // miktarsız tutar = götürü (karttan gelen fiyat dursa da)
                $out[] = ['product_id' => $productId, 'quantity' => 1.0, 'unit_price' => $amount, 'amount' => $amount, 'is_lump' => true];
            } elseif ($price > 0) {
                $out[] = ['product_id' => $productId, 'quantity' => null, 'unit_price' => $price, 'amount' => null, 'is_lump' => false];
            } else {
                throw ValidationException::withMessages(['lines' => $name . ': miktar ve fiyat, ya da yalnız tutar (götürü) yaz.']);
            }
        }

        return $out;
    }

    /**
     * Anlaşmayla birlikte yazılan ödemeler (WhatsApp sözleşme fotoğrafı: nakit + çekler + senetler). Her biri cariye normal ödeme
     * (satışta tahsilat); çek Çekler'e vadesiyle (verdiğim → hatırlatma), senet açıklamada vadesiyle. Devir/takas nakit sayılır (kullanıcı 21.10).
     *
     * @param  list<array{method:string, amount:float, paid_date?:?string, due_date?:?string, check_number?:?string}>  $payments
     */
    public static function recordPayments(Agreement $agreement, array $payments): void
    {
        DB::transaction(function () use ($agreement, $payments) {
            foreach ($payments as $p) {
                $amount = round((float) ($p['amount'] ?? 0), 2);
                if ($amount <= 0) {
                    continue;
                }
                $method = $p['method'] ?? 'cash';
                $due = $p['due_date'] ?? null;
                self::recordAdvance($agreement, $amount, [
                    'advance_payment_type' => $method,
                    'check_number'         => $p['check_number'] ?? null,
                    'check_due_date'       => $due,
                    'entry_date'           => $p['paid_date'] ?? null,
                    'note'                 => $p['note'] ?? ($method === 'promissory_note' && $due
                        ? 'Senet, vade ' . \Carbon\Carbon::parse($due)->format('d.m.Y') : null),
                ]);
            }
        });
    }

    /** "Beton · Yeşilyurt" / "Fayans, Klozet · Yeşilyurt" / "Fayans +2". */
    public static function autoTitle(array $lines, ?int $projectId): string
    {
        $names = collect($lines)->map(fn ($l) => Product::find($l['product_id'])?->name)->filter()->unique()->values();
        $title = $names->count() > 2 ? $names->first() . ' +' . ($names->count() - 1) : $names->implode(', ');
        $project = $projectId ? Project::find($projectId)?->name : null;

        return str($title ?: 'Anlaşma')->limit(80, '') . ($project ? ' · ' . $project : '');
    }

    private static function insertItems(Agreement $agreement, array $lines): void
    {
        $sort = (int) $agreement->items()->max('sort');
        foreach ($lines as $line) {
            AgreementItem::create([...$line, 'agreement_id' => $agreement->id, 'sort' => ++$sort]);
        }
    }

    /** Peşinat (alış: biz ödedik) / kapora (satış: biz aldık) → cariye normal ödeme/tahsilat; çekse çek de yazılır. */
    private static function recordAdvance(Agreement $agreement, float $amount, array $data): void
    {
        $isPurchase = $agreement->isPurchase();
        $paymentType = $data['advance_payment_type'] ?? 'cash';
        $entry = PartyLedgerEntry::create([
            'party_id'     => $agreement->party_id,
            'entry_date'   => $data['entry_date'] ?? $agreement->agreement_date,
            'type'         => $isPurchase ? PartyLedgerEntry::TYPE_PAYMENT : PartyLedgerEntry::TYPE_COLLECTION,
            'amount'       => $amount,
            'payment_type' => $paymentType,
            'description'  => ($isPurchase ? 'Peşinat — ' : 'Kapora — ') . $agreement->title . (! empty($data['note']) ? ' (' . $data['note'] . ')' : ''),
        ]);
        if ($paymentType === 'check') {
            Check::create([
                'party_id'              => $agreement->party_id,
                'project_id'            => $agreement->project_id,
                'party_ledger_entry_id' => $entry->id,
                'check_number'          => $data['check_number'] ?? null,
                'bank_name'             => $data['bank_name'] ?? null,
                'issue_date'            => $data['entry_date'] ?? $agreement->agreement_date,
                'due_date'              => $data['check_due_date'] ?? $agreement->agreement_date,
                'amount'                => $amount,
                'status'                => $isPurchase ? 'issued' : 'portfolio', // verdiğim çek vade hatırlatmasına girer
                'description'           => $entry->description,
            ]);
        }
    }
}
