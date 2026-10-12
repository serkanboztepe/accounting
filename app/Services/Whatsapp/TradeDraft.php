<?php

namespace App\Services\Whatsapp;

use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Support\Money;
use App\Support\MonthlyResult;
use App\Support\PartyBalances;
use App\Support\PartyStatement;
use App\Support\StockUsage;
use App\Support\TradeDesk;
use Illuminate\Support\Carbon;

/**
 * WhatsApp — Stoklu satış paketi (Satışlar/Alışlar tezgahı açık firma). Kaydın NEREYE gideceğine AI değil
 * bu sınıf karar verir (kullanıcı: "kaydetme kaymasın"):
 *   · katalogdaki MAL alındı → alış belgesi (stoğa girer); katalogda yoksa → gider (gider açıksa)
 *   · satış → satış belgesi (peşin carisiz / cariye), basit cari satışına ya da gidere ASLA düşmez
 *   · paket kapalı firmada hiçbir şey yapmaz (route() veriyi aynen döndürür)
 * Kayıt panelle aynı yoldan: TradeDesk::saveSale / savePurchase, Product::countTo.
 */
class TradeDraft
{
    /** Taslak yazmayan (doğrudan cevaplanan) türler. */
    public const ANSWER_KINDS = [ExpenseExtractor::KIND_STOCK_QUERY, ExpenseExtractor::KIND_MONTH_RESULT];

    public static function enabled(): bool
    {
        return ExpenseExtractor::tradeEnabled();
    }

    public static function handles(array $d): bool
    {
        return self::enabled() && in_array($d['kind'] ?? null, ExpenseExtractor::TRADE_KINDS, true);
    }

    /**
     * Modüle göre hedefi düzelt + kalemleri katalogla doğrula. Paket kapalıysa dokunmaz.
     */
    public static function route(array $d): array
    {
        if (! self::enabled()) {
            return $d;
        }

        $kind = $d['kind'] ?? ExpenseExtractor::KIND_EXPENSE;

        // Gider sanılan katalog malı ("20 çuval un aldım") → alış belgesi (stoğa girsin).
        if (in_array($kind, [ExpenseExtractor::KIND_EXPENSE, ExpenseExtractor::KIND_PURCHASE], true)
            && config('modules.purchases') && config('modules.stock') && empty($d['settles_expense_id'])) {
            $product = self::productFromItems($d['items'] ?? []) ?? self::matchProduct((string) ($d['description'] ?? ''));
            if ($product && $product->isProduct()) {
                $d['kind'] = $kind = ExpenseExtractor::KIND_TRADE_PURCHASE;
                $d['items'] = ! empty($d['items']) ? $d['items'] : [[
                    'description' => $product->name, 'product_id' => $product->id, 'quantity' => null,
                    'unit_price' => null, 'amount' => (float) ($d['amount'] ?? 0) ?: null,
                ]];
                $d['category_id'] = $d['category_name'] = null;
                $d['project_id'] = $d['project_name'] = null;
            }
        }

        // Alış ama katalogda mal yok (mazot, kira) → gider (gider takibi açıksa); kapalıysa ürünsüz alış.
        if ($kind === ExpenseExtractor::KIND_TRADE_PURCHASE && ! self::hasCatalogItem($d['items'] ?? []) && config('modules.expenses')) {
            $d['kind'] = ExpenseExtractor::KIND_EXPENSE;
            $d['items'] = [];

            return $d;
        }

        // Basit cari satış / "alacak" sanılan mal satışı → satış belgesi.
        if (config('modules.sales_desk') && in_array($kind, [ExpenseExtractor::KIND_SALE, ExpenseExtractor::KIND_DEBT_NOTE], true)
            && ($kind === ExpenseExtractor::KIND_SALE || ($d['debt_side'] ?? null) === 'receivable')
            && (self::hasCatalogItem($d['items'] ?? []) || self::matchProduct((string) ($d['description'] ?? '')))) {
            $d['kind'] = $kind = ExpenseExtractor::KIND_TRADE_SALE;
            $d['paid'] = $d['paid'] ?? false;
        }

        if ($kind === ExpenseExtractor::KIND_TRADE_SALE) {
            return self::normalizeSale($d);
        }
        if ($kind === ExpenseExtractor::KIND_TRADE_PURCHASE) {
            return self::normalizePurchase($d);
        }
        if (in_array($kind, [ExpenseExtractor::KIND_STOCK_COUNT, ExpenseExtractor::KIND_STOCK_SETTING, ExpenseExtractor::KIND_STOCK_QUERY], true)) {
            $product = self::validProduct($d['product_id'] ?? null) ?? self::productFromItems($d['items'] ?? [])
                ?? self::matchProduct((string) ($d['description'] ?? ''));
            $d['product_id'] = $product?->id;
            if (! $product && $kind !== ExpenseExtractor::KIND_STOCK_QUERY) {
                $d['question'] = 'Hangi ürün? Ürün kartındaki adıyla yazar mısın?';
            }
        }

        return $d;
    }

    // ---------------------------------------------------------------- satış / alış

    private static function normalizeSale(array $d): array
    {
        $d = self::normalizeItems($d);
        $hasParty = ! empty($d['party_id']) || ! empty($d['party_name']);

        // "Peşin" denip isim de geçtiyse: satış carisiz, isim açıklamaya.
        if (($d['paid'] ?? null) === true && $hasParty) {
            $name = self::partyName($d);
            $d['description'] = trim(($d['description'] ?? '') . ($name ? ' (' . $name . ')' : ''));
            $d['party_id'] = $d['party_name'] = null;
            $hasParty = false;
        }
        $d['paid'] = $d['paid'] ?? ! $hasParty;

        $cash = (float) ($d['cash_amount'] ?? 0);
        $card = (float) ($d['card_amount'] ?? 0);
        if (empty($d['items']) && $cash + $card > 0) {
            $d['amount'] = $cash + $card;
        }
        $d['payment_type'] = null;

        return $d;
    }

    private static function normalizePurchase(array $d): array
    {
        $d = self::normalizeItems($d);
        $hasParty = ! empty($d['party_id']) || ! empty($d['party_name']);
        $d['paid'] = $d['paid'] ?? ! $hasParty;

        return $d;
    }

    /** Kalemler: ürün id'si doğrulanır; tutar yoksa adet × (söylenen ya da karttaki) fiyat. Toplam = kalemler. */
    private static function normalizeItems(array $d): array
    {
        $items = [];
        $missing = [];
        foreach ((array) ($d['items'] ?? []) as $item) {
            $product = self::validProduct($item['product_id'] ?? null) ?? self::matchProduct((string) ($item['description'] ?? ''));
            $qty = isset($item['quantity']) && $item['quantity'] !== null ? (float) $item['quantity'] : null;
            $price = isset($item['unit_price']) && $item['unit_price'] !== null ? (float) $item['unit_price'] : null;
            $amount = ! empty($item['amount']) ? (float) $item['amount'] : null;

            if ($product) {
                // Satışta adet söylenmediyse 1; alışta stoğa ne gireceği belli olmalı → boş kalır, sorulur.
                if (($d['kind'] ?? null) === ExpenseExtractor::KIND_TRADE_SALE) {
                    $qty ??= 1.0;
                }
                if ($amount === null && $qty !== null) {
                    $price ??= $product->default_price !== null ? (float) $product->default_price : null;
                    $amount = $price !== null ? round($qty * $price, 2) : null;
                } elseif ($amount !== null && $price === null && $qty > 0) {
                    $price = round($amount / $qty, 2);
                }
                if ($amount === null && $qty !== null) {
                    $missing[] = $product->name;
                }
            }

            $items[] = [
                'description' => $product?->name ?? trim((string) ($item['description'] ?? '')),
                'product_id' => $product?->id,
                'quantity' => $qty,
                'unit_price' => $price,
                'amount' => $amount,
            ];
        }

        $d['items'] = $items;
        if ($items !== []) {
            $sum = array_sum(array_map(fn ($i) => (float) ($i['amount'] ?? 0), $items));
            // Kalem tutarları eksikse AI'ın söylediği toplam korunur (ör. "20 çuval 17 bin").
            if ($missing === [] || (float) ($d['amount'] ?? 0) <= 0) {
                $d['amount'] = $sum;
            } elseif (count($items) === 1) {
                $d['items'][0]['amount'] = (float) $d['amount'];
                $missing = [];
            }
        }
        if ($missing !== []) {
            $d['question'] = implode(', ', $missing) . ' fiyatı ne? (tanesi ya da toplam)';
        }

        return $d;
    }

    // ---------------------------------------------------------------- özet (teyit metni)

    public static function summary(array $d): string
    {
        return match ($d['kind']) {
            ExpenseExtractor::KIND_TRADE_SALE => self::saleSummary($d),
            ExpenseExtractor::KIND_TRADE_PURCHASE => self::purchaseSummary($d),
            ExpenseExtractor::KIND_STOCK_COUNT => self::countSummary($d),
            ExpenseExtractor::KIND_STOCK_SETTING => self::settingSummary($d),
            default => '',
        };
    }

    /** Taslak kaydedilebilir mi? Değilse kullanıcıya sorulacak soru. */
    public static function blocker(array $d): ?string
    {
        $kind = $d['kind'];
        if (in_array($kind, [ExpenseExtractor::KIND_STOCK_COUNT, ExpenseExtractor::KIND_STOCK_SETTING], true)) {
            if (empty($d['product_id'])) {
                return $d['question'] ?: 'Hangi ürün?';
            }
            if ($kind === ExpenseExtractor::KIND_STOCK_COUNT && ($d['counted_quantity'] ?? null) === null) {
                return 'Elinde kaç tane var?';
            }
            if ($kind === ExpenseExtractor::KIND_STOCK_SETTING && ($d['daily_usage'] ?? null) === null && ($d['reorder_level'] ?? null) === null) {
                return 'Günde kaç tane kullanıyorsun, ya da kaç tane kalınca haber vereyim?';
            }

            return null;
        }

        if ($kind === ExpenseExtractor::KIND_TRADE_PURCHASE && empty($d['paid']) && empty($d['party_id']) && empty($d['party_name'])) {
            return 'Kimden aldın? Adını yazar mısın? (Parasını verdiysen *peşin* yaz.)';
        }
        if ($kind === ExpenseExtractor::KIND_TRADE_SALE && empty($d['paid']) && empty($d['party_id']) && empty($d['party_name'])) {
            return 'Kime veresiye verdin? Adını yazar mısın? (Parası alındıysa *peşin* yaz.)';
        }
        foreach ($d['items'] ?? [] as $item) {
            if (! empty($item['product_id']) && $kind === ExpenseExtractor::KIND_TRADE_PURCHASE && empty($item['quantity'])) {
                return 'Kaç ' . (Product::find($item['product_id'])?->unit?->name ?? 'tane') . ' aldın? (stoğa girecek)';
            }
            if (! empty($item['product_id']) && $item['amount'] === null) {
                return $d['question'] ?: 'Fiyatı ne?';
            }
        }
        if ((float) ($d['amount'] ?? 0) <= 0) {
            return 'Tutarı ne kadar? Sadece rakamı yazman yeterli.';
        }

        return null;
    }

    private static function saleSummary(array $d): string
    {
        $lines = [];
        $party = self::party($d);
        $name = self::partyName($d);
        $lines[] = ! empty($d['paid'])
            ? '🧾 *Peşin satış — kontrol et:*'
            : '🧾 *Veresiye satış* → ' . ($party ? $name : $name . ' (yeni cari)');
        $lines = array_merge($lines, self::itemLines($d, 'out'));
        $lines[] = '• Toplam: ' . Money::format((float) $d['amount']) . ' ₺';
        if (! empty($d['paid'])) {
            $cash = (float) ($d['cash_amount'] ?? 0);
            $card = (float) ($d['card_amount'] ?? 0);
            $lines[] = '• Ödeme: ' . match (true) {
                $cash > 0 && $card > 0 => 'Nakit ' . Money::format($cash) . ' + Kart ' . Money::format($card),
                $card > 0 => 'Kart',
                default => 'Nakit',
            };
        }
        if (! empty($d['description']) && empty($d['items'])) {
            $lines[] = '• Açıklama: ' . $d['description'];
        }
        $lines[] = '• Tarih: ' . ($d['date'] ?? now()->format('Y-m-d'));
        if (! empty($d['due_date']) && empty($d['paid'])) {
            $lines[] = '• Ödeme tarihi: ' . Carbon::parse($d['due_date'])->locale('tr')->translatedFormat('j F Y');
        }
        if (empty($d['paid']) && $party) {
            $before = PartyStatement::build($party)['balance'];
            $lines[] = '• Bakiye: ' . self::balancePhrase($before) . ' → ' . self::balancePhrase($before + (float) $d['amount']);
        }

        return implode("\n", $lines);
    }

    private static function purchaseSummary(array $d): string
    {
        $party = self::party($d);
        $name = self::partyName($d);
        $lines = [$name
            ? '🛒 *Alış* ← ' . ($party ? $name : $name . ' (yeni cari)') . (! empty($d['paid']) ? ' (parası verildi)' : ' (ona borçlanırsın)')
            : (! empty($d['paid']) ? '🛒 *Peşin alış — kontrol et:*' : '🛒 *Alış — kontrol et:*')];
        $lines = array_merge($lines, self::itemLines($d, 'in'));
        $lines[] = '• Toplam: ' . Money::format((float) $d['amount']) . ' ₺';
        $lines[] = '• Tarih: ' . ($d['date'] ?? now()->format('Y-m-d'));
        if ($party) {
            $before = PartyStatement::build($party)['balance'];
            $after = ! empty($d['paid']) ? $before : $before - (float) $d['amount'];
            $lines[] = '• Bakiye: ' . self::balancePhrase($before) . ' → ' . self::balancePhrase($after);
        }

        return implode("\n", $lines);
    }

    private static function itemLines(array $d, string $direction): array
    {
        $lines = [];
        foreach ($d['items'] ?? [] as $item) {
            $product = ! empty($item['product_id']) ? Product::with('unit')->find($item['product_id']) : null;
            $qty = $item['quantity'] !== null ? StockUsage::quantity((float) $item['quantity']) . ' ' . ($product?->unit?->name ?? 'adet') . ' ' : '';
            $line = '   • ' . $qty . ($item['description'] ?: '—')
                . ($item['amount'] !== null ? ': ' . Money::format((float) $item['amount']) . ' ₺' : '');
            if ($product?->isProduct() && $item['quantity'] !== null) {
                $now = $product->currentStock();
                $after = $direction === 'in' ? $now + (float) $item['quantity'] : $now - (float) $item['quantity'];
                $line .= ' (stok ' . StockUsage::quantity($now) . ' → ' . StockUsage::quantity($after) . ')';
            } elseif (! $product) {
                $line .= ' _(kartta yok — stok değişmez)_';
            }
            $lines[] = $line;
        }

        return $lines;
    }

    private static function countSummary(array $d): string
    {
        $product = Product::with('unit')->find($d['product_id']);
        $now = $product->currentStock();
        $counted = (float) $d['counted_quantity'];
        $diff = $counted - $now;
        $unit = $product->unit?->name ?? '';

        return "📦 *Sayım — kontrol et:*\n• " . $product->name . ': sistemde ' . StockUsage::quantity($now) . ' ' . $unit
            . ' → elinde ' . StockUsage::quantity($counted) . ' ' . $unit
            . (abs($diff) < 0.005 ? ' (zaten doğru)' : ' (' . ($diff > 0 ? '+' : '−') . StockUsage::quantity(abs($diff)) . ' düzeltme)');
    }

    private static function settingSummary(array $d): string
    {
        $product = Product::with('unit')->find($d['product_id']);
        $unit = $product->unit?->name ?? 'tane';
        $lines = ['⚙️ *' . $product->name . ' — stok ayarı:*'];
        if (($d['daily_usage'] ?? null) !== null) {
            $lines[] = '• Her sabah ' . StockUsage::quantity((float) $d['daily_usage']) . ' ' . $unit . ' stoktan düşülecek';
        }
        if (($d['reorder_level'] ?? null) !== null) {
            $lines[] = '• ' . StockUsage::quantity((float) $d['reorder_level']) . ' ' . $unit . ' kalınca sabah 08:00\'de haber vereceğim';
        }
        $lines[] = '• Şu an stok: ' . StockUsage::quantity($product->currentStock()) . ' ' . $unit;

        return implode("\n", $lines);
    }

    // ---------------------------------------------------------------- kayıt

    /** @return array{0:string,1:?int} [cevap, dokunulan cari id] */
    public static function commit(array $d): array
    {
        return match ($d['kind']) {
            ExpenseExtractor::KIND_TRADE_SALE => self::commitSale($d),
            ExpenseExtractor::KIND_TRADE_PURCHASE => self::commitPurchase($d),
            ExpenseExtractor::KIND_STOCK_COUNT => self::commitCount($d),
            ExpenseExtractor::KIND_STOCK_SETTING => self::commitSetting($d),
        };
    }

    private static function commitSale(array $d): array
    {
        $party = empty($d['paid']) ? self::resolveParty($d) : null;
        [$lines, $manual] = self::splitLines($d);
        $cash = (float) ($d['cash_amount'] ?? 0);
        $card = (float) ($d['card_amount'] ?? 0);

        $sale = TradeDesk::saveSale([
            'mode' => $party ? 'party' : 'cash',
            'party_id' => $party?->id,
            'sale_date' => $d['date'] ?? now()->toDateString(),
            'lines' => $lines,
            'manual_amount' => $manual > 0 ? $manual : null,
            'payment' => $cash > 0 && $card > 0 ? 'mixed' : ($card > 0 ? 'card' : 'cash'),
            'cash_part' => $cash,
            'notes' => trim(($d['description'] ?? '') . ' (WhatsApp)'),
        ]);
        if ($party && ! empty($d['due_date'])) {
            $sale->ledgerEntry?->update(['due_date' => $d['due_date']]);
        }

        $text = '✅ Satış kaydedildi: ' . Money::format((float) $sale->total_amount) . ' ₺ · '
            . ($party ? $party->name : 'Peşin (' . $sale->paymentLabel() . ')');
        $text .= self::stockAfterLines($lines);
        if ($party) {
            $text .= "\n" . PartyBalances::line($party->name, PartyStatement::build($party)['balance']);
        }

        return [$text, $party?->id];
    }

    private static function commitPurchase(array $d): array
    {
        $party = (! empty($d['party_id']) || ! empty($d['party_name'])) ? self::resolveParty($d) : null;
        [$lines, $manual] = self::splitLines($d);

        $purchase = TradeDesk::savePurchase([
            'mode' => ! empty($d['paid']) ? 'cash' : 'party',
            'party_id' => $party?->id,
            'purchase_date' => $d['date'] ?? now()->toDateString(),
            'lines' => $lines,
            'manual_amount' => $manual > 0 ? $manual : null,
            'payment_type' => in_array($d['payment_type'] ?? null, ['bank_transfer', 'eft'], true) ? 'bank_transfer' : 'cash',
            'notes' => trim(($d['description'] ?? '') . ' (WhatsApp)'),
        ]);

        $text = '✅ Alış kaydedildi: ' . Money::format((float) $purchase->total_amount) . ' ₺'
            . ($party ? ' · ' . $party->name : '') . (! empty($d['paid']) ? ' (ödendi)' : '');
        $text .= self::stockAfterLines($lines);
        if ($party) {
            $text .= "\n" . PartyBalances::line($party->name, PartyStatement::build($party)['balance']);
        }

        return [$text, $party?->id];
    }

    private static function commitCount(array $d): array
    {
        $product = Product::with('unit')->findOrFail($d['product_id']);
        $product->countTo((float) $d['counted_quantity'], $d['date'] ?? null);

        return ['✅ Sayım kaydedildi: ' . $product->name . ' ' . StockUsage::quantity($product->currentStock()) . ' ' . ($product->unit?->name ?? ''), null];
    }

    private static function commitSetting(array $d): array
    {
        $product = Product::findOrFail($d['product_id']);
        $update = [];
        if (($d['daily_usage'] ?? null) !== null) {
            $update['auto_usage'] = (float) $d['daily_usage'] > 0;
            $update['daily_usage'] = (float) $d['daily_usage'] > 0 ? $d['daily_usage'] : null;
        }
        if (($d['reorder_level'] ?? null) !== null) {
            $update['reorder_level'] = $d['reorder_level'];
        }
        $product->update($update);

        return ['✅ Tamam, ' . $product->name . ' için ayar kaydedildi. Panelde ürün kartından da değiştirebilirsin.', null];
    }

    /** Katalogdaki kalemler → belge kalemi; katalogda olmayanlar → ürünsüz tutar. */
    private static function splitLines(array $d): array
    {
        $lines = [];
        $manual = (float) ($d['amount'] ?? 0);
        foreach ($d['items'] ?? [] as $item) {
            if (! empty($item['product_id']) && (float) ($item['quantity'] ?? 0) > 0) {
                $lines[] = [
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'amount' => $item['amount'],
                ];
                $manual -= (float) ($item['amount'] ?? 0);
            }
        }

        return [$lines, round(max(0.0, $manual), 2)];
    }

    private static function stockAfterLines(array $lines): string
    {
        $out = '';
        foreach ($lines as $line) {
            $product = Product::with('unit')->find($line['product_id']);
            if ($product?->isProduct()) {
                $out .= "\n📦 " . $product->name . ' stok: ' . StockUsage::quantity($product->currentStock()) . ' ' . ($product->unit?->name ?? '');
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- sorular (kayıt yok)

    public static function answer(array $d): string
    {
        if ($d['kind'] === ExpenseExtractor::KIND_MONTH_RESULT) {
            $month = ! empty($d['month']) ? Carbon::parse($d['month'] . '-01') : now();

            return MonthlyResult::whatsappText(MonthlyResult::forMonth($month));
        }

        return self::stockAnswer($d['product_id'] ?? null);
    }

    public static function stockAnswer(?int $productId): string
    {
        if ($productId && ($product = Product::with('unit')->find($productId))) {
            $stock = $product->currentStock();
            $days = $product->daysLeft($stock);

            return '📦 ' . $product->name . ': ' . StockUsage::quantity($stock) . ' ' . ($product->unit?->name ?? '')
                . ($days !== null ? ' (~' . $days . ' gün yeter)' : '')
                . ($product->isLowStock($stock) ? ' ⚠️ azaldı' : '');
        }

        $rows = Product::active()->where('type', Product::TYPE_PRODUCT)->with('unit')->orderBy('name')->get()
            ->map(fn (Product $p) => ['p' => $p, 'stock' => $p->currentStock()])
            ->sortBy(fn ($r) => $r['p']->isLowStock($r['stock']) ? 0 : 1)
            ->take(20);
        if ($rows->isEmpty()) {
            return 'Stoklu ürün kartı yok. Panelde *Ürünler*den ekleyebilirsin.';
        }

        return "📦 *Stok:*\n" . $rows->map(fn ($r) => '• ' . $r['p']->name . ': ' . StockUsage::quantity($r['stock']) . ' ' . ($r['p']->unit?->name ?? '')
            . ($r['p']->isLowStock($r['stock']) ? ' ⚠️' : ''))->implode("\n");
    }

    // ---------------------------------------------------------------- yardımcılar

    public static function matchProduct(string $text): ?Product
    {
        $text = self::lower($text);
        if ($text === '') {
            return null;
        }
        $hits = Product::active()->get()->filter(function (Product $p) use ($text) {
            $name = self::lower($p->name);

            return $name !== '' && preg_match('/(^|[^\p{L}])' . preg_quote($name, '/') . '/u', $text);
        });

        // Tek ve net eşleşme; birden çoksa en uzun adı (ör. "tam buğday unu" > "un").
        return $hits->sortByDesc(fn (Product $p) => mb_strlen($p->name))->first();
    }

    private static function validProduct(mixed $id): ?Product
    {
        return $id ? Product::active()->find((int) $id) : null;
    }

    private static function productFromItems(array $items): ?Product
    {
        foreach ($items as $item) {
            if ($p = self::validProduct($item['product_id'] ?? null)) {
                return $p;
            }
        }

        return null;
    }

    private static function hasCatalogItem(array $items): bool
    {
        foreach ($items as $item) {
            if (self::validProduct($item['product_id'] ?? null) || self::matchProduct((string) ($item['description'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    private static function party(array $d): ?Party
    {
        return ! empty($d['party_id']) ? Party::find($d['party_id']) : null;
    }

    private static function partyName(array $d): ?string
    {
        return self::party($d)?->name ?? ($d['party_name'] ?? null);
    }

    private static function resolveParty(array $d): ?Party
    {
        return self::party($d) ?? (! empty($d['party_name']) ? Party::firstOrCreate(['name' => $d['party_name']]) : null);
    }

    private static function balancePhrase(float $balance): string
    {
        return match (true) {
            abs($balance) < 0.01 => 'borç yok',
            $balance > 0 => 'sana ' . Money::format($balance) . ' ₺ borçlu',
            default => 'sen ' . Money::format(abs($balance)) . ' ₺ borçlusun',
        };
    }

    private static function lower(string $s): string
    {
        return trim(mb_strtolower(strtr($s, ['İ' => 'i', 'I' => 'ı'])));
    }
}
