<?php

namespace App\Support;

use App\Models\Agreement;
use App\Models\AgreementItem;
use App\Models\Check;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Sözleşmeler → Anlaşmalar (plan 20.10 "sözleşmeler kalkacak", kullanıcı 26.10: önce test firmasında Muhasebe verisiyle dene).
 * Etkin firmada çalışır; veri kaybı olmasın diye her şey karşılığına taşınır:
 *  - Sözleşme → anlaşma (cari, proje, başlık, tarih; elle girilmiş sözleşme toplamı → bütçe).
 *  - Kalem: miktar + fiyat → miktarlı; miktarsız fiyatlı → fiyat listesi; miktarsız tutarlı / "anahtar teslim" → götürü;
 *    kalemsiz sözleşme ya da tek kalemi yer tutucu (fiyat ≤ 1) → sözleşme toplamıyla götürü. Kalem adlarından ürün kartı.
 *  - Teslimat → anlaşmadan gelen alış (tarih, proje, tutar kuruşuna kadar aynı); kalemsiz teslimat kendi kalemini açar.
 *  - Ödendi ödemesi → cari ödemesi (tarih, tutar, şekil); bağlı çek o ödemeye bağlanır. Ödenmemiş (planlı) ödeme → not.
 *  - Fatura → karşılığı yok: numara/tarih/tutar anlaşma notuna yazılır.
 *  - Sonra sözleşme satırları silinir (olay tetiklemeden — çek silinmesin).
 * Cari bakiyesi bilerek değişebilir: sözleşmede borç TOPLAMLA yazılır, anlaşmada teslimatla (model farkı).
 */
final class ContractConversion
{
    /** @return array{agreements:int, items:int, deliveries:int, payments:int, checks:int, invoices:int, notes:list<string>, rows:list<array>} */
    public static function run(): array
    {
        $report = ['agreements' => 0, 'items' => 0, 'deliveries' => 0, 'payments' => 0, 'checks' => 0, 'invoices' => 0, 'notes' => [], 'rows' => []];

        DB::transaction(function () use (&$report) {
            $contracts = Contract::withoutGlobalScopes()->with(['party', 'items', 'deliveries', 'payments', 'invoices'])->orderBy('id')->get();
            foreach ($contracts as $contract) {
                self::convert($contract, $report);
            }
            // Olay tetiklemeden sil: ContractPayment silinirken bağlı çekleri de siliyordu (çekler artık ödemeye bağlı).
            foreach (['invoice_deliveries', 'invoices', 'contract_deliveries', 'contract_payments', 'contract_items', 'contracts'] as $table) {
                DB::table($table)->delete();
            }
        });

        return $report;
    }

    private static function convert(Contract $c, array &$report): void
    {
        if (! $c->party) {
            $report['notes'][] = "#{$c->id} {$c->title}: carisi yok — atlandı";

            return;
        }
        $direction = $c->isSale() ? Agreement::DIRECTION_SALE : Agreement::DIRECTION_PURCHASE;
        $date = ($c->contract_date ?? $c->start_date ?? $c->created_at)->toDateString();
        $total = (float) $c->total_amount;

        // Kalemler → anlaşma satırları (+ sözleşme kalemi → anlaşma kalemi eşlemesi için sıra).
        $lines = [];
        $map = [];
        $items = $c->items->values();
        $placeholder = $items->count() === 1 && $items[0]->quantity === null && (float) $items[0]->unit_price <= 1 && $total > 0;
        foreach ($items as $i) {
            $line = self::line($c, $i, $placeholder ? $total : null);
            if ($line === null) {
                $report['notes'][] = "#{$c->id} {$c->title}: kalem \"{$i->description}\" fiyatsız/tutarsız — atlandı";

                continue;
            }
            $map[$i->id] = count($lines);
            $lines[] = $line;
        }
        if ($lines === []) {
            $lines[] = ['product_id' => self::product($c->title, $c, null), 'amount' => $total > 0 ? $total : 0.01];
            $report['notes'][] = "#{$c->id} {$c->title}: kalemsiz — sözleşme toplamıyla ({$total}) götürü kalem";
        }

        $notes = array_filter([$c->notes, 'Sözleşmeden aktarıldı (#' . $c->id . ', ' . (Contract::TYPES[$c->contract_type] ?? $c->contract_type) . ').']);
        $agreement = AgreementDesk::create($c->party, [
            'direction' => $direction, 'agreement_date' => $date, 'project_id' => $c->project_id, 'title' => $c->title,
            'budget' => $total > 0 && abs($total - self::linesTotal($lines)) > 0.01 ? $total : null,
            'notes' => implode("\n", $notes), 'lines' => $lines,
        ]);
        $agreementItems = $agreement->items()->orderBy('id')->get()->values();
        $report['agreements']++;
        $report['items'] += $agreementItems->count();

        // Teslimatlar → anlaşmadan gelen alış (satışta satış), tutar aynen.
        foreach ($c->deliveries->sortBy('delivery_date') as $d) {
            $item = isset($map[$d->contract_item_id]) ? $agreementItems[$map[$d->contract_item_id]] : null;
            if (! $item) {
                $item = self::extraItem($agreement, $c, $d);
                $report['notes'][] = "#{$c->id} {$c->title}: kalemsiz teslimat ({$d->amount}) — ayrı kalem açıldı";
            }
            $amount = (float) $d->amount;
            $qty = (float) $d->quantity > 0 ? (float) $d->quantity
                : ($item->is_lump && (float) $item->unit_price > 0 ? max(round($amount / (float) $item->unit_price, 4), 0.0001) : 1.0);
            $doc = [
                'mode' => 'party', 'party_id' => $c->party_id, 'project_id' => $d->project_id ?? $c->project_id, 'keep_project' => true,
                'lines' => [['product_id' => $item->product_id, 'quantity' => $qty, 'unit_price' => $d->unit_price, 'amount' => $amount,
                    'agreement_item_id' => $item->id]],
                'notes' => trim('Anlaşma: ' . $c->title . ($d->notes ? ' — ' . $d->notes : '')),
            ];
            $direction === Agreement::DIRECTION_SALE
                ? TradeDesk::saveSale([...$doc, 'sale_date' => $d->delivery_date->toDateString()])
                : TradeDesk::savePurchase([...$doc, 'purchase_date' => $d->delivery_date->toDateString()]);
            $report['deliveries']++;
        }

        // Ödemeler → cari ödemesi / tahsilatı; çek ona bağlanır.
        $planned = [];
        foreach ($c->payments->sortBy('payment_date') as $p) {
            if ($p->status !== 'paid') {
                $planned[] = Money::format((float) $p->amount) . ' ₺ (' . ($p->due_date ?? $p->payment_date)?->format('d.m.Y') . ', ' . $p->payment_type . ')';

                continue;
            }
            $entry = PartyLedgerEntry::create([
                'party_id' => $c->party_id, 'project_id' => $c->project_id, 'entry_date' => $p->payment_date->toDateString(),
                'type' => $direction === Agreement::DIRECTION_SALE ? PartyLedgerEntry::TYPE_COLLECTION : PartyLedgerEntry::TYPE_PAYMENT,
                'payment_type' => $p->payment_type, 'amount' => $p->amount, 'due_date' => $p->due_date,
                'description' => 'Ödeme — ' . $c->title . ($p->notes ? ' (' . $p->notes . ')' : ''),
            ]);
            $report['checks'] += Check::where('contract_payment_id', $p->id)->update(['party_ledger_entry_id' => $entry->id, 'contract_payment_id' => null]);
            $report['payments']++;
        }

        // Faturalar ve planlı ödemeler: karşılığı yok → anlaşma notu (bilgi kaybolmasın).
        $extra = [];
        if ($c->invoices->isNotEmpty()) {
            $extra[] = 'Faturalar: ' . $c->invoices->map(fn ($inv) => trim(($inv->invoice_number ? 'no ' . $inv->invoice_number . ' ' : '')
                . $inv->invoice_date?->format('d.m.Y') . ' ' . Money::format((float) $inv->total_amount) . ' ₺'))->implode('; ');
            $report['invoices'] += $c->invoices->count();
        }
        if ($planned !== []) {
            $extra[] = 'Planlı (ödenmemiş) ödemeler: ' . implode('; ', $planned);
            $report['notes'][] = "#{$c->id} {$c->title}: " . count($planned) . ' ödenmemiş ödeme nota yazıldı';
        }
        if ($extra !== []) {
            $agreement->update(['notes' => trim($agreement->notes . "\n" . implode("\n", $extra))]);
        }
        AgreementDesk::refreshStatus($agreement->fresh());

        $report['rows'][] = ['contract' => $c->id, 'title' => $c->title, 'party' => $c->party->name, 'total' => $total,
            'delivered' => round((float) $c->deliveries->sum('amount'), 2), 'paid' => round((float) $c->payments->where('status', 'paid')->sum('amount'), 2),
            'agreement' => $agreement->id, 'agreement_delivered' => round((float) $agreement->items()->get()->sum(fn (AgreementItem $i) => $i->deliveredAmount()), 2)];
    }

    /** Sözleşme kalemi → anlaşma satırı (ürün kartıyla). $lumpTotal: tek yer tutucu kalem → sözleşme toplamıyla götürü. */
    private static function line(Contract $c, ContractItem $i, ?float $lumpTotal): ?array
    {
        $qty = $i->quantity !== null ? (float) $i->quantity : null;
        $price = (float) $i->unit_price;
        $amount = (float) $i->amount;
        $lump = $lumpTotal !== null || ($qty === null && $amount > 0) || ($qty !== null && abs($qty - 1) < 0.001 && $price > 1 && stripos((string) $i->description, 'anahtar teslim') !== false);
        $name = $lump && stripos((string) $i->description, 'anahtar teslim') !== false ? $c->title : (string) $i->description;
        $productId = self::product($name, $c, $i->unit_id);

        return match (true) {
            $lump => ['product_id' => $productId, 'amount' => $lumpTotal ?? ($amount > 0 ? $amount : round(($qty ?? 1) * $price, 2))],
            $qty !== null && $qty > 0 && $price > 0 => ['product_id' => $productId, 'quantity' => $qty, 'unit_price' => $price],
            $qty === null && $price > 0 => ['product_id' => $productId, 'unit_price' => $price],   // fiyat listesi
            default => null,
        };
    }

    private static function linesTotal(array $lines): float
    {
        return round(array_sum(array_map(fn ($l) => isset($l['quantity']) ? $l['quantity'] * $l['unit_price'] : (float) ($l['amount'] ?? 0), $lines)), 2);
    }

    /** Kalemsiz teslimat: teslimatın kendisinden kalem (miktarlıysa fiyat listesi, değilse götürü). */
    private static function extraItem(Agreement $agreement, Contract $c, $d): AgreementItem
    {
        $name = trim((string) ($d->notes ?: 'Diğer — ' . $c->title));
        $line = (float) $d->quantity > 0 && (float) $d->unit_price > 0
            ? ['product_id' => self::product($name, $c, $d->unit_id), 'unit_price' => (float) $d->unit_price]
            : ['product_id' => self::product($name, $c, $d->unit_id), 'amount' => (float) $d->amount];
        AgreementDesk::addItems($agreement, [$line]);

        return $agreement->items()->latest('id')->first();
    }

    /** Ürün kartı (adıyla; yoksa açılır). Malzeme sözleşmesi → ürün, taşeron → hizmet. */
    private static function product(string $name, Contract $c, ?int $unitId): int
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name)) ?: $c->title;
        $existing = Product::whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        return $existing?->id ?? Product::create([
            'name' => $name, 'unit_id' => $unitId, 'is_active' => true,
            'type' => $c->contract_type === Contract::TYPE_SUBCONTRACT ? Product::TYPE_SERVICE : Product::TYPE_PRODUCT,
        ])->id;
    }
}
