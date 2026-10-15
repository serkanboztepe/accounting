<?php

namespace App\Services\Whatsapp;

use App\Models\Expense;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Reminder;
use App\Models\Sale;
use App\Models\Unit;
use App\Support\Money;
use App\Support\MonthlyResult;
use App\Support\PartyBalances;
use App\Support\PartyStatement;
use App\Support\StockUsage;
use App\Support\TradeDesk;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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

        // Alış ama katalogda mal yok: adetle sayılan mal ("20 çuval un") → yeni ürün kartı önerilir (onayla);
        // adetsiz masraf (mazot, kira) → gider (gider takibi açıksa); kapalıysa ürünsüz alış.
        if ($kind === ExpenseExtractor::KIND_TRADE_PURCHASE && ! self::hasCatalogItem($d['items'] ?? [])
            && ! self::hasNewProductItem($d['items'] ?? []) && config('modules.expenses')) {
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
        if ($kind === ExpenseExtractor::KIND_PRODUCT_ADD) {
            return self::normalizeProductAdd($d);
        }
        if (in_array($kind, [ExpenseExtractor::KIND_STOCK_COUNT, ExpenseExtractor::KIND_STOCK_SETTING, ExpenseExtractor::KIND_STOCK_QUERY], true)) {
            $product = self::validProduct($d['product_id'] ?? null) ?? self::productFromItems($d['items'] ?? [])
                ?? self::matchProduct((string) ($d['product_name'] ?? ''))
                ?? self::matchProduct((string) ($d['description'] ?? ''));
            $d['product_id'] = $product?->id;
            $d['new_product'] = null;
            // Kartta olmayan ürünün sayımı / ayarı ("elimde 15 çuval un var") → onaylanınca kart açılır.
            if (! $product && $kind !== ExpenseExtractor::KIND_STOCK_QUERY && ! empty($d['product_name'])) {
                $d['new_product'] = ['name' => self::title($d['product_name']), 'unit' => $d['unit'] ?? null];
            } elseif (! $product && $kind !== ExpenseExtractor::KIND_STOCK_QUERY) {
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

    /**
     * Alış: kimden söylenmediyse o malın son alındığı yer ("unlar geldi" → Boztepe), özette "son aldığın yer"
     * diye görünür. Ödeme söylenmediyse cariden (veresiye) — özette yazar, "peşin ödedim" ile düzelir.
     * Hiç alınmamış malda kimden de söylenmediyse sorulur.
     */
    private static function normalizePurchase(array $d): array
    {
        $hasParty = ! empty($d['party_id']) || ! empty($d['party_name']);
        $d['party_auto'] = false;
        if (! $hasParty && ($d['paid'] ?? null) !== true) {
            $product = self::productFromItems($d['items'] ?? []) ?? self::firstMatchedItem($d['items'] ?? []);
            if ($supplierId = $product?->lastSupplierId()) {
                $d['party_id'] = $supplierId;
                $d['party_auto'] = $hasParty = true;
            }
        }
        $d['paid_assumed'] = ($d['paid'] ?? null) === null && $hasParty;
        $d['paid'] = $d['paid'] ?? ($hasParty ? false : null);

        return self::normalizeItems($d);
    }

    /** "Un, maya, tuz kullanıyorum": kartta olmayanlar açılacak; hepsi zaten varsa stok listesi gösterilir. */
    private static function normalizeProductAdd(array $d): array
    {
        $new = [];
        foreach ((array) ($d['items'] ?? []) as $item) {
            $name = trim((string) ($item['description'] ?? ''));
            if ($name === '' || self::matchProduct($name) || isset($new[self::lower($name)])) {
                continue;
            }
            $new[self::lower($name)] = [
                'description' => self::title($name),
                'unit' => $item['unit'] ?? null,
                'unit_price' => isset($item['unit_price']) && $item['unit_price'] > 0 ? (float) $item['unit_price'] : null,
                'product_id' => null, 'quantity' => null, 'amount' => null,
            ];
        }
        if ($new === []) {
            return ['kind' => ExpenseExtractor::KIND_STOCK_QUERY, 'product_id' => null] + $d;
        }
        $d['items'] = array_values($new);
        $d['amount'] = 0.0;

        return $d;
    }

    /**
     * Kalemler: ürün id'si doğrulanır; tutar yoksa adet × fiyat. Satışta fiyat = karttaki satış fiyatı;
     * ALIŞTA = son alış fiyatı (o tedarikçiden → karttaki alış fiyatı → herhangi biri) — satış fiyatı ASLA.
     * Alışta söylenen fiyat öncekinden farklıysa özette "fiyat değişti" (prev_price). Toplam = kalemler.
     */
    private static function normalizeItems(array $d): array
    {
        $items = [];
        $missing = [];
        $isPurchase = ($d['kind'] ?? null) === ExpenseExtractor::KIND_TRADE_PURCHASE;
        $partyId = ! empty($d['party_id']) ? (int) $d['party_id'] : null;
        foreach ((array) ($d['items'] ?? []) as $item) {
            $product = self::validProduct($item['product_id'] ?? null) ?? self::matchProduct((string) ($item['description'] ?? ''));
            $qty = isset($item['quantity']) && $item['quantity'] !== null ? (float) $item['quantity'] : null;
            $price = isset($item['unit_price']) && $item['unit_price'] !== null ? (float) $item['unit_price'] : null;
            $amount = ! empty($item['amount']) ? (float) $item['amount'] : null;
            $prev = null;
            // Alışta kartta olmayan, adetle sayılan mal: onaylanınca ürün kartı açılır.
            $new = ! $product && $isPurchase && $qty > 0 && trim((string) ($item['description'] ?? '')) !== '';

            if ($product || $new) {
                // Satışta adet söylenmediyse 1; alışta stoğa ne gireceği belli olmalı → boş kalır, sorulur.
                if (($d['kind'] ?? null) === ExpenseExtractor::KIND_TRADE_SALE) {
                    $qty ??= 1.0;
                }
                $known = match (true) {
                    $new => null,
                    $isPurchase => $product->buyPrice($partyId),
                    default => $product->default_price !== null ? (float) $product->default_price : null,
                };
                // Fiyat ya da tutar söylendiyse (ikisi birden de olabilir) öncekiyle karşılaştır: "fiyat değişti".
                $said = $price !== null || $amount !== null;
                if ($amount === null && $qty !== null) {
                    $price ??= $known;
                    $amount = $price !== null ? round($qty * $price, 2) : null;
                } elseif ($amount !== null && $price === null && $qty > 0) {
                    $price = round($amount / $qty, 2);
                }
                $prev = $isPurchase && $said ? $known : null;
                if ($amount === null && $qty !== null) {
                    $missing[] = $product?->name ?? self::title((string) $item['description']);
                }
            }

            $items[] = [
                'description' => $product?->name ?? ($new ? self::title((string) $item['description']) : trim((string) ($item['description'] ?? ''))),
                'product_id' => $product?->id,
                'quantity' => $qty,
                'unit_price' => $price,
                'amount' => $amount,
                'unit' => $item['unit'] ?? null,
                'new' => $new,
                'prev_price' => $prev !== null && $price !== null && abs($prev - $price) >= 0.005 ? $prev : null,
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
            ExpenseExtractor::KIND_PRODUCT_ADD => self::productAddSummary($d),
            default => '',
        };
    }

    /** Alışta kartta olmayan mal var mı ("gider" cevabıyla gidere çevrilebilir). */
    public static function canBecomeExpense(array $d): bool
    {
        return ($d['kind'] ?? null) === ExpenseExtractor::KIND_TRADE_PURCHASE && config('modules.expenses')
            && self::hasNewProductItem($d['items'] ?? []);
    }

    /** "gider" cevabı: yeni ürün açma, aynı alışı gider olarak yaz (cari ve ödeme bilgisi korunur). */
    public static function toExpense(array $d): array
    {
        $names = collect($d['items'] ?? [])->map(fn ($i) => trim(($i['quantity'] !== null ? StockUsage::quantity((float) $i['quantity']) . ' ' . ($i['unit'] ?? '') . ' ' : '') . $i['description']))->implode(', ');
        $d['kind'] = ExpenseExtractor::KIND_EXPENSE;
        $d['description'] = $names ?: ($d['description'] ?? '');
        $d['items'] = [];
        $d['is_new_entry'] = false;
        if (! empty($d['party_auto'])) {
            $d['party_id'] = null;
        }
        if (! empty($d['paid_assumed'])) {
            $d['paid'] = null;
        }
        unset($d['party_auto'], $d['paid_assumed']);

        return $d;
    }

    /** Taslak kaydedilebilir mi? Değilse kullanıcıya sorulacak soru. */
    public static function blocker(array $d): ?string
    {
        $kind = $d['kind'];
        if ($kind === ExpenseExtractor::KIND_PRODUCT_ADD) {
            return null;
        }
        if (in_array($kind, [ExpenseExtractor::KIND_STOCK_COUNT, ExpenseExtractor::KIND_STOCK_SETTING], true)) {
            if (empty($d['product_id']) && empty($d['new_product'])) {
                return $d['question'] ?: 'Hangi ürün?';
            }
            if ($kind === ExpenseExtractor::KIND_STOCK_COUNT && ($d['counted_quantity'] ?? null) === null) {
                return 'Elinde kaç tane var?';
            }
            if ($kind === ExpenseExtractor::KIND_STOCK_SETTING && ($d['daily_usage'] ?? null) === null
                && ($d['reorder_level'] ?? null) === null && ($d['purchase_price'] ?? null) === null) {
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
            if ((! empty($item['product_id']) || ! empty($item['new'])) && $item['amount'] === null) {
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
        if (($extra = (float) ($d['extra_expense']['amount'] ?? 0)) > 0) {
            $lines[] = '📝 *+ Gider:* ' . Money::format($extra) . ' ₺ — ' . ($d['extra_expense']['description'] ?? 'gider') . ' (ayrı kayıt)';
            $lines[] = 'İkisini birden kaydederim.';
        }
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
            ? '🛒 *Alış* ← ' . ($party ? $name : $name . ' (yeni cari)') . (! empty($d['party_auto']) ? ' (son aldığın yer)' : '')
                . (! empty($d['paid']) ? ' · parası verildi' : ' · ona borçlanırsın')
            : (! empty($d['paid']) ? '🛒 *Peşin alış — kontrol et:*' : '🛒 *Alış — kontrol et:*')];
        $lines = array_merge($lines, self::itemLines($d, 'in'));
        $lines[] = '• Toplam: ' . Money::format((float) $d['amount']) . ' ₺';
        if (! empty($d['paid_assumed'])) {
            $lines[] = '• Ödeme söylemedin → veresiye yazılır. Parasını verdiysen *peşin ödedim* yaz.';
        }
        if (! empty($d['party_auto'])) {
            $lines[] = '• Başka yerden aldıysan adını yaz.';
        }
        if (self::canBecomeExpense($d)) {
            $lines[] = '🆕 Kartta olmayan ürün onaylayınca açılır ve stoğa girer. Stok takibi istemiyorsan *gider* yaz.';
        }
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
            $qty = $item['quantity'] !== null ? StockUsage::quantity((float) $item['quantity']) . ' ' . ($product?->unit?->name ?? $item['unit'] ?? 'adet') . ' ' : '';
            $line = '   • ' . $qty . ($item['description'] ?: '—')
                . ($item['amount'] !== null ? ': ' . Money::format((float) $item['amount']) . ' ₺' : '');
            if ($product?->isProduct() && $item['quantity'] !== null) {
                $now = $product->currentStock();
                $after = $direction === 'in' ? $now + (float) $item['quantity'] : $now - (float) $item['quantity'];
                $line .= ' (stok ' . StockUsage::quantity($now) . ' → ' . StockUsage::quantity($after) . ')';
            } elseif (! empty($item['new'])) {
                $line .= ' (🆕 yeni ürün' . (! empty($item['unit']) ? ', birim ' . $item['unit'] : '') . ' · stok 0 → ' . StockUsage::quantity((float) $item['quantity']) . ')';
            } elseif (! $product) {
                $line .= ' _(kartta yok — stok değişmez)_';
            }
            $lines[] = $line;
            if (($item['prev_price'] ?? null) !== null && $item['unit_price'] !== null) {
                $prev = (float) $item['prev_price'];
                $now = (float) $item['unit_price'];
                $lines[] = ($now > $prev * 3 || $now < $prev / 3)
                    ? '     ⚠️ Birim fiyat ' . Money::format($now) . ' ₺ — son alış ' . Money::format($prev) . ' ₺ idi. Doğru mu? (tanesi mi, toplam mı?)'
                    : '     Fiyat değişti: ' . Money::format($prev) . ' → ' . Money::format($now) . ' ₺ (bundan sonra bu)';
            }
        }

        return $lines;
    }

    private static function countSummary(array $d): string
    {
        if (! empty($d['new_product'])) {
            return "📦 *Yeni ürün + stok — kontrol et:*\n• " . $d['new_product']['name'] . ': ' . StockUsage::quantity((float) $d['counted_quantity'])
                . ' ' . ($d['new_product']['unit'] ?? '') . "\n🆕 Ürün kartı açılacak (birim: " . ($d['new_product']['unit'] ?? 'adet') . ').';
        }
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
        $product = ! empty($d['product_id']) ? Product::with('unit')->find($d['product_id']) : null;
        $name = $product?->name ?? $d['new_product']['name'];
        $unit = $product?->unit?->name ?? $d['new_product']['unit'] ?? 'tane';
        $lines = ['⚙️ *' . $name . ' — ayar:*'];
        if (! $product) {
            $lines[] = '🆕 Ürün kartı açılacak (birim: ' . $unit . ')';
        }
        if (($d['purchase_price'] ?? null) !== null) {
            $old = $product?->buyPrice();
            $lines[] = '• Alış fiyatı: ' . ($old !== null && abs($old - (float) $d['purchase_price']) >= 0.005 ? Money::format($old) . ' → ' : '')
                . Money::format((float) $d['purchase_price']) . ' ₺ (fiyat yazmadığın alışlarda bu kullanılır)';
        }
        if (($d['daily_usage'] ?? null) !== null) {
            $lines[] = '• Her sabah ' . StockUsage::quantity((float) $d['daily_usage']) . ' ' . $unit . ' stoktan düşülecek';
        }
        if (($d['reorder_level'] ?? null) !== null) {
            $lines[] = '• ' . StockUsage::quantity((float) $d['reorder_level']) . ' ' . $unit . ' kalınca sabah 08:00\'de haber vereceğim';
        }
        $lines[] = '• Şu an stok: ' . StockUsage::quantity($product?->currentStock() ?? 0.0) . ' ' . $unit;

        return implode("\n", $lines);
    }

    private static function productAddSummary(array $d): string
    {
        $lines = ['🆕 *Ürün kartı açılacak — kontrol et:*'];
        foreach ($d['items'] as $item) {
            $lines[] = '• ' . $item['description'] . ' (' . ($item['unit'] ?? 'adet') . ')'
                . ($item['unit_price'] !== null ? ' · alış ' . Money::format((float) $item['unit_price']) . ' ₺' : '');
        }
        $lines[] = 'Birim yanlışsa düzeltmeyi yaz (ör. "maya paket").';

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
            ExpenseExtractor::KIND_PRODUCT_ADD => self::commitProductAdd($d),
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

        // K4: aynı mesajdaki gider ayrı kayıt.
        $extraText = '';
        if (($extra = (float) ($d['extra_expense']['amount'] ?? 0)) > 0 && config('modules.expenses')) {
            Expense::create([
                'expense_date' => $d['date'] ?? now()->toDateString(),
                'amount' => Money::store($extra),
                'payment_status' => 'paid',
                'description' => $d['extra_expense']['description'] ?? 'Gider',
                'notes' => 'WhatsApp üzerinden girildi (kasa mesajı).',
            ]);
            $extraText = "\n📝 Gider kaydedildi: " . Money::format($extra) . ' ₺';
        }

        $text = '✅ Satış kaydedildi: ' . Money::format((float) $sale->total_amount) . ' ₺ · '
            . ($party ? $party->name : 'Peşin (' . $sale->paymentLabel() . ')') . $extraText;
        $text .= self::stockAfterLines($lines);
        if ($party) {
            $text .= "\n" . PartyBalances::line($party->name, PartyStatement::build($party)['balance']);
        }
        // Akşam cirosu (ürünsüz peşin): günün tek mesajlık özeti — panele girmeden günü görsün.
        if (! $party && $lines === []) {
            $text .= "\n\n" . self::daySummary((string) ($d['date'] ?? now(Reminder::TIMEZONE)->toDateString()));
        }

        return [$text, $party?->id];
    }

    /** "Bugün: ciro · alış · gider" + takip edilen hammaddelerin stoğu (kaç gün yeter). */
    public static function daySummary(string $date): string
    {
        $isToday = $date === now(Reminder::TIMEZONE)->toDateString();
        $sales = TradeDesk::salesSummary($date, $date);
        $purchases = TradeDesk::purchaseSummary($date, $date);
        $parts = ['ciro ' . Money::format($sales['total'])];
        if ($purchases['total'] > 0) {
            $parts[] = 'alış ' . Money::format($purchases['total']);
        }
        if (config('modules.expenses') && ($exp = (float) Expense::whereDate('expense_date', $date)->sum('amount')) > 0) {
            $parts[] = 'gider ' . Money::format($exp);
        }
        $lines = ['📊 *' . ($isToday ? 'Bugün' : Carbon::parse($date)->locale('tr')->translatedFormat('j F')) . ':* ' . implode(' · ', $parts) . ' ₺'];

        $tracked = Product::active()->with('unit')
            ->where('type', Product::TYPE_PRODUCT)
            ->where(fn ($q) => $q->where('auto_usage', true)->orWhereNotNull('reorder_level'))
            ->orderBy('name')->get();
        foreach ($tracked as $p) {
            $stock = $p->currentStock();
            $days = $p->daysLeft($stock);
            $lines[] = ($p->isLowStock($stock) ? '⚠️ ' : '📦 ') . $p->name . ' ' . StockUsage::quantity($stock) . ' ' . ($p->unit?->name ?? '')
                . ($days !== null ? ' (~' . $days . ' gün)' : '');
        }

        return implode("\n", $lines);
    }

    private static function commitPurchase(array $d): array
    {
        $party = (! empty($d['party_id']) || ! empty($d['party_name'])) ? self::resolveParty($d) : null;
        $created = [];
        foreach ($d['items'] ?? [] as $i => $item) {
            if (! empty($item['new']) && empty($item['product_id'])) {
                $product = self::createProduct($item['description'], $item['unit'] ?? null, $item['unit_price'] ?? null);
                $d['items'][$i]['product_id'] = $product->id;
                $created[] = $product->name;
            }
        }
        foreach ($d['items'] ?? [] as $item) {
            self::fillUnit($item['product_id'] ?? null, $item['unit'] ?? null);
        }
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
            . ($party ? ' · ' . $party->name : '') . (! empty($d['paid']) ? ' (ödendi)' : '')
            . ($created !== [] ? "\n🆕 Ürün kartı açıldı: " . implode(', ', $created) : '');
        $text .= self::stockAfterLines($lines);
        if ($party) {
            $text .= "\n" . PartyBalances::line($party->name, PartyStatement::build($party)['balance']);
        }

        return [$text, $party?->id];
    }

    private static function commitCount(array $d): array
    {
        self::fillUnit($d['product_id'] ?? null, $d['unit'] ?? null);
        $product = ! empty($d['product_id'])
            ? Product::with('unit')->findOrFail($d['product_id'])
            : self::createProduct($d['new_product']['name'], $d['new_product']['unit'] ?? null)->load('unit');
        $product->countTo((float) $d['counted_quantity'], $d['date'] ?? null);

        return ['✅ Sayım kaydedildi: ' . $product->name . ' ' . StockUsage::quantity($product->currentStock()) . ' ' . ($product->unit?->name ?? ''), null];
    }

    private static function commitSetting(array $d): array
    {
        $product = ! empty($d['product_id'])
            ? Product::findOrFail($d['product_id'])
            : self::createProduct($d['new_product']['name'], $d['new_product']['unit'] ?? null);
        $update = [];
        if (($d['purchase_price'] ?? null) !== null) {
            $update['purchase_price'] = $d['purchase_price'];
        }
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

    private static function commitProductAdd(array $d): array
    {
        $names = [];
        foreach ($d['items'] as $item) {
            $names[] = self::createProduct($item['description'], $item['unit'] ?? null, $item['unit_price'] ?? null)->name;
        }

        return ['✅ Ürün kartı açıldı: ' . implode(', ', $names)
            . "\nŞimdi elindekini yazabilirsin, ör. \"" . $names[0] . ' 15 ' . ($d['items'][0]['unit'] ?? 'adet') . ' var"', null];
    }

    /** Ürün kartı (stoklu). Aynı adlı ürün o arada açıldıysa onu kullanır — çift kart olmasın. */
    private static function createProduct(string $name, ?string $unit, ?float $purchasePrice = null): Product
    {
        if ($existing = self::matchProduct($name)) {
            return $existing;
        }

        return Product::create([
            'name' => self::title($name),
            'type' => Product::TYPE_PRODUCT,
            'unit_id' => self::unitId($unit),
            'purchase_price' => $purchasePrice > 0 ? $purchasePrice : null,
            'is_active' => true,
        ]);
    }

    /** Kartta birim yoksa mesajdaki birim yazılır ("20 çuval un" → Un: çuval). Var olan birim değiştirilmez. */
    private static function fillUnit(mixed $productId, ?string $unit): void
    {
        if ($productId && $unit && ($product = Product::find($productId)) && $product->unit_id === null) {
            $product->update(['unit_id' => self::unitId($unit)]);
        }
    }

    private static function unitId(?string $unit): ?int
    {
        $unit = trim((string) $unit);
        if ($unit === '') {
            return null;
        }
        $found = Unit::all()->first(fn (Unit $u) => self::lower($u->name) === self::lower($unit) || self::lower((string) $u->code) === self::lower($unit));

        return ($found ?? Unit::firstOrCreate(['code' => Str::slug($unit) ?: Str::random(6)], ['name' => self::lower($unit)]))->id;
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

    private static function hasNewProductItem(array $items): bool
    {
        foreach ($items as $item) {
            if (empty($item['product_id']) && (float) ($item['quantity'] ?? 0) > 0 && trim((string) ($item['description'] ?? '')) !== ''
                && ! self::matchProduct((string) $item['description'])) {
                return true;
            }
        }

        return false;
    }

    private static function firstMatchedItem(array $items): ?Product
    {
        foreach ($items as $item) {
            if ($p = self::matchProduct((string) ($item['description'] ?? ''))) {
                return $p;
            }
        }

        return null;
    }

    private static function title(string $s): string
    {
        $s = trim($s);

        return mb_strtoupper(mb_substr(strtr(mb_substr($s, 0, 1), ['i' => 'İ']), 0, 1)) . mb_substr($s, 1);
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
