<?php

namespace App\Services\Whatsapp;

use App\Models\Expense;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Reminder;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Support\Money;
use App\Support\MonthlyResult;
use App\Support\PartyBalances;
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
            && \App\Support\Features::stockPurchases() && \App\Support\Features::stock() && empty($d['settles_expense_id'])) {
            $product = self::productFromItems($d['items'] ?? []) ?? (empty($d['items']) ? self::mentionedProduct((string) ($d['description'] ?? '')) : null);
            // AI'ın adlandırdığı mal kartın adını niteliyorsa ("Börek unu" ↔ "Un") başka üründür — stok sorusu (StockChoice) yürür.
            if ($product && ($goods = trim((string) ($d['goods_name'] ?? ''))) !== '' && ! self::goodsMatch($goods)) {
                $product = null;
            }
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
            && ! self::hasNewProductItem($d['items'] ?? []) && \App\Support\Features::expenses()) {
            $d['kind'] = ExpenseExtractor::KIND_EXPENSE;
            $d['items'] = [];

            return $d;
        }

        // Basit cari satış / "alacak" sanılan mal satışı → satış belgesi.
        if (\App\Support\Features::salesDesk() && in_array($kind, [ExpenseExtractor::KIND_SALE, ExpenseExtractor::KIND_DEBT_NOTE], true)
            && ($kind === ExpenseExtractor::KIND_SALE || ($d['debt_side'] ?? null) === 'receivable')
            && (self::hasCatalogItem($d['items'] ?? []) || (empty($d['items']) && self::mentionedProduct((string) ($d['description'] ?? ''))))) {
            $d['kind'] = $kind = ExpenseExtractor::KIND_TRADE_SALE;
            $d['paid'] = $d['paid'] ?? false;
        }

        if ($kind === ExpenseExtractor::KIND_TRADE_SALE) {
            return self::normalizeSale($d);
        }
        if ($kind === ExpenseExtractor::KIND_TRADE_PURCHASE) {
            return self::normalizePurchase($d);
        }
        // Birleşik stok kartı (19.10): AI ürün satırlarını `stock_items` ile verdiyse sayım/ayar/ürün açma tek kart olur.
        if ($kind === ExpenseExtractor::KIND_STOCK_UPDATE
            || (in_array($kind, [ExpenseExtractor::KIND_STOCK_COUNT, ExpenseExtractor::KIND_STOCK_SETTING, ExpenseExtractor::KIND_PRODUCT_ADD], true)
                && ! empty($d['stock_items']))) {
            return StockCard::normalize($d);
        }
        if ($kind === ExpenseExtractor::KIND_PRODUCT_ADD) {
            return self::normalizeProductAdd($d);
        }
        if ($kind === ExpenseExtractor::KIND_PRODUCTION) {
            return self::normalizeProduction($d);
        }
        if ($kind === ExpenseExtractor::KIND_PRODUCT_RENAME) {
            $d['product_id'] = self::validProduct($d['product_id'] ?? null)?->id;
            $d['product_name'] = trim((string) ($d['product_name'] ?? '')) !== '' ? self::title((string) $d['product_name']) : null;

            return $d;
        }
        if (in_array($kind, [ExpenseExtractor::KIND_STOCK_COUNT, ExpenseExtractor::KIND_STOCK_SETTING, ExpenseExtractor::KIND_STOCK_QUERY], true)) {
            $named = trim((string) ($d['product_name'] ?? ''));
            $product = self::validProduct($d['product_id'] ?? null);
            if ($product && $named !== '' && ! self::fits($product->name, $named)) {
                $product = null; // "buzağı yemi var" → AI "Yem"i seçmiş; adı tutmuyor
            } elseif ($product && $named === '' && ($qualified = self::qualifiedName($product->name, (string) ($d['description'] ?? ''))) !== null) {
                // Ad yok, açıklamada kartın adının ÖNÜNDE niteleyen kelime var ("Buzağı yemi sayımı" ↔ "Yem") → başka ürün.
                $product = null;
                $d['product_name'] = $qualified;
            }
            $product ??= self::productFromItems($d['items'] ?? [])
                ?? ($named !== '' ? self::matchProduct($named) : self::mentionedProduct((string) ($d['description'] ?? '')));
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

    /** Üretim: kalemler katalogla eşlenir; kartta yoksa yeni kart önerilir (benzer kart varsa "aynı mı?"). Para yok. */
    private static function normalizeProduction(array $d): array
    {
        $items = [];
        foreach ((array) ($d['items'] ?? []) as $item) {
            $desc = trim((string) ($item['description'] ?? ''));
            $qty = (float) ($item['quantity'] ?? 0);
            if ($desc === '' && empty($item['product_id'])) {
                continue;
            }
            $product = self::itemProduct($item);
            $items[] = ['description' => $product?->name ?? self::title($desc), 'product_id' => $product?->id, 'quantity' => $qty > 0 ? $qty : null,
                'unit' => $item['unit'] ?? null, 'new' => ! $product, 'similar' => ! $product ? self::similarProduct($desc)?->name : null,
                'unit_price' => null, 'amount' => null];
        }
        $d['items'] = $items;
        $d['amount'] = 0.0;

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
        // Düzeltme turunda (Çiftlik 22.10 "Pesin alis") cari önceki taslaktan gelir: kendiliğinden seçildiyse işaret kalsın.
        $d['party_auto'] = ! empty($d['party_auto']) && ! empty($d['party_id']);
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
                // Söylenen miktar açılış stoğu olur ("400 ton saman ekle" — Oktay 16.10: yalnız kart açılıp stok 0 kalıyordu).
                'product_id' => null, 'quantity' => isset($item['quantity']) && (float) $item['quantity'] > 0 ? (float) $item['quantity'] : null, 'amount' => null,
            ];
        }
        $daily = isset($d['daily_usage']) && (float) $d['daily_usage'] > 0 ? (float) $d['daily_usage'] : null;
        $reorder = isset($d['reorder_level']) && (float) $d['reorder_level'] > 0 ? (float) $d['reorder_level'] : null;
        if ($new === []) {
            // Kart zaten var ama günlük kullanım / eşik söylendi → o kartın ayarı (bilgi kaybolmasın).
            $existing = self::matchProduct(trim((string) ($d['items'][0]['description'] ?? '')));
            if ($existing && ($daily !== null || $reorder !== null)) {
                return ['kind' => ExpenseExtractor::KIND_STOCK_SETTING, 'product_id' => $existing->id, 'daily_usage' => $daily,
                    'reorder_level' => $reorder, 'purchase_price' => null, 'items' => [], 'amount' => 0.0] + $d;
            }

            return ['kind' => ExpenseExtractor::KIND_STOCK_QUERY, 'product_id' => null] + $d;
        }
        $d['items'] = array_values($new);
        $d['amount'] = 0.0;
        // Günlük kullanım / eşik tek karta aittir (Çiftlik 16.10: "günde 16 gidiyor" kayboldu). Birden çok üründe hangisi belli
        // değil → sıradaki kalem olarak sorulur, sessizce düşmez.
        if (count($d['items']) > 1 && ($daily !== null || $reorder !== null)) {
            $said = trim(($daily !== null ? 'günde ' . StockUsage::quantity($daily) . ' gidiyor' : '')
                . ($reorder !== null ? ' ' . StockUsage::quantity($reorder) . ' kalınca haber ver' : ''));
            $d['next_items'] = trim(($d['next_items'] ?? '') . ' ' . $said . ' (aynı mesajdan: ürün kartları açıldı; bu ayarın HANGİ ürün için olduğunu sor)');
            $daily = $reorder = null;
        }
        $d['daily_usage'] = $daily;
        $d['reorder_level'] = $reorder;

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
            $product = self::itemProduct($item);
            $qty = isset($item['quantity']) && $item['quantity'] !== null ? (float) $item['quantity'] : null;
            $price = isset($item['unit_price']) && $item['unit_price'] !== null ? (float) $item['unit_price'] : null;
            $amount = ! empty($item['amount']) ? (float) $item['amount'] : null;
            $prev = null;
            // Kartta olmayan, miktarı belli mal: onaylanınca kart açılır — alışta stoklu, satışta STOKSUZ (yalnız satış fiyatı;
            // 17.10 kullanıcı: Oktay süt satışında her seferinde fiyat sorulmasın, stok da eksiye düşmesin).
            $new = ! $product && $qty > 0 && trim((string) ($item['description'] ?? '')) !== ''
                && ($isPurchase || ($d['kind'] ?? null) === ExpenseExtractor::KIND_TRADE_SALE);

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
            } elseif ($qty > 0) {
                // Kartsız kalem (17.10 Oktay: "50 litre süt" + "litresi 30 TL" → toplam 0 kalıyordu): miktar × birim fiyat
                // (lt / kg / birim yoksa adet); yalnız toplam söylendiyse birim fiyat çıkarılır; ikisi de yoksa fiyat sorulur.
                if ($amount === null && $price !== null) {
                    $amount = round($qty * $price, 2);
                } elseif ($amount !== null && $price === null) {
                    $price = round($amount / $qty, 2);
                }
                if ($amount === null && trim((string) ($item['description'] ?? '')) !== '') {
                    $missing[] = self::title((string) $item['description']);
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
                'stockless' => $new && ! $isPurchase,
                'similar' => $new ? self::similarProduct((string) $item['description'])?->name : null,
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
        // Önce anla: AI'ın anlama sorusu ("mal elinizde mi, Tokgöz'de mi?") varsa önce o — fiyat sonra sorulur.
        if ($missing !== [] && ! (($d['work_status_known'] ?? null) === false && trim((string) ($d['question'] ?? '')) !== '')) {
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
            ExpenseExtractor::KIND_STOCK_UPDATE => StockCard::summary($d),
            ExpenseExtractor::KIND_PRODUCT_RENAME => self::renameSummary($d),
            ExpenseExtractor::KIND_PRODUCTION => self::productionSummary($d),
            default => '',
        };
    }

    /** Alışta kartta olmayan mal var mı ("gider" cevabıyla gidere çevrilebilir). */
    public static function canBecomeExpense(array $d): bool
    {
        return ($d['kind'] ?? null) === ExpenseExtractor::KIND_TRADE_PURCHASE && \App\Support\Features::expenses()
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
        if ($kind === ExpenseExtractor::KIND_STOCK_UPDATE) {
            return StockCard::blocker($d);
        }
        if ($kind === ExpenseExtractor::KIND_PRODUCT_RENAME) {
            return empty($d['product_id']) ? 'Hangi ürünün adı değişecek? Stoktaki adıyla yazar mısınız?'
                : (empty($d['product_name']) ? 'Yeni adı ne olsun?' : null);
        }
        if ($kind === ExpenseExtractor::KIND_PRODUCTION) {
            if (($d['items'] ?? []) === []) {
                return 'Ne ürettiniz, ne kadar? (ör. "400 ton silaj")';
            }
            foreach ($d['items'] as $item) {
                if (empty($item['quantity'])) {
                    return $item['description'] . ' ne kadar?';
                }
            }

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
            // Satışta da miktar tahmin edilmez ("Un satışım oldu" → 1 adet sanılıyordu, Serkan 16.10): stoktan düşecek.
            if (! empty($item['product_id']) && $kind === ExpenseExtractor::KIND_TRADE_SALE && empty($item['quantity'])) {
                $p = Product::find($item['product_id']);

                return 'Kaç ' . ($p?->unit?->name ?? 'tane') . ' sattın, kaç liraya?' . ($p?->isProduct() ? ' (stoktan düşecek)' : '');
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
        // Tarih yalnız bugün değilse ya da gece girilen dünkü kasaysa (kullanıcı 20.10: "cevaplar çok uzun").
        if (! empty($d['night_till']) || ! self::isToday($d)) {
            $lines[] = '• Tarih: ' . Carbon::parse($d['date'] ?? now()->format('Y-m-d'))->format('d.m.Y')
                . (! empty($d['night_till']) ? ' (dünkü kasa — gece girildi; bugünün ise *bugünün* yaz)' : '');
        }
        if (($extra = (float) ($d['extra_expense']['amount'] ?? 0)) > 0) {
            $lines[] = '📝 *+ Gider:* ' . Money::format($extra) . ' ₺ — ' . ($d['extra_expense']['description'] ?? 'gider') . ' (ayrı kayıt)';
            $lines[] = 'İkisini birden kaydederim.';
        }
        if (! empty($d['due_date']) && empty($d['paid'])) {
            $lines[] = '• Ödeme tarihi: ' . Carbon::parse($d['due_date'])->locale('tr')->translatedFormat('j F Y');
        }
        if (empty($d['paid']) && $party) {
            $before = TurnTrace::statement('TradeDraft::saleSummary', $party)['balance'];
            $lines[] = '• Bakiye: ' . self::balancePhrase($before) . ' → ' . self::balancePhrase($before + (float) $d['amount']);
        }

        return implode("\n", $lines);
    }

    private static function isToday(array $d): bool
    {
        return empty($d['date']) || $d['date'] === now('Europe/Istanbul')->toDateString();
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
        if (! self::isToday($d)) {
            $lines[] = '• Tarih: ' . Carbon::parse($d['date'])->format('d.m.Y');
        }
        if ($party) {
            $before = TurnTrace::statement('TradeDraft::purchaseSummary', $party)['balance'];
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
                // Şubeli firmada "işletme stoğu": şube stoğu yok (karar A) — şube adı görünce şube stoğu sanılmasın.
                $line .= ' (stok ' . StockUsage::quantity($now) . ' → ' . StockUsage::quantity($after) . ')';
            } elseif (! empty($item['new']) && ! empty($item['stockless'])) {
                $line .= ' (🆕 ürün kartı açılacak' . ($item['unit_price'] !== null ? ' · ' . ($item['unit'] ? self::perUnit((string) $item['unit']) : 'tanesi') . ' ' . Money::format((float) $item['unit_price']) . ' ₺' : '') . ' · stok tutulmaz)'
                    . (! empty($item['similar']) ? "\n     ❓ *" . $item['similar'] . '* ürün kartı da var — aynı ürünse "' . $item['similar'] . '" yazın' : '');
            } elseif (! empty($item['new'])) {
                $line .= ' (🆕 yeni ürün' . (! empty($item['unit']) ? ', birim ' . $item['unit'] : '') . ' · stok 0 → ' . StockUsage::quantity((float) $item['quantity']) . ')'
                    . (! empty($item['similar']) ? "\n     ❓ Stokta *" . $item['similar'] . '* de var — aynı ürünse "' . $item['similar'] . '" yazın' : '');
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

    private static function renameSummary(array $d): string
    {
        $product = ! empty($d['product_id']) ? Product::find($d['product_id']) : null;

        return '📦 *Ürün adı — kontrol et:*' . "\n• " . ($product?->name ?? '?') . ' → *' . ($d['product_name'] ?? '?') . '*'
            . "\nStok ve geçmiş kayıtlar aynen kalır, yalnız adı değişir.";
    }

    private static function productionSummary(array $d): string
    {
        $lines = ['🌾 *Üretim girişi — kontrol et:* (alış/gider değil, maliyetsiz stok girişi)'];
        foreach ($d['items'] ?? [] as $item) {
            $product = ! empty($item['product_id']) ? Product::with('unit')->find($item['product_id']) : null;
            $unit = $product?->unit?->name ?? $item['unit'] ?? 'adet';
            $qty = (float) ($item['quantity'] ?? 0);
            $line = '• ' . $item['description'] . ': ' . ($qty > 0 ? StockUsage::quantity($qty) . ' ' . $unit : '?');
            $line .= $product
                ? ' (stok ' . StockUsage::quantity($product->currentStock()) . ' → ' . StockUsage::quantity($product->currentStock() + $qty) . ')'
                : ' (🆕 yeni ürün kartı)';
            $lines[] = $line;
            if (! empty($item['similar'])) {
                $lines[] = '   ❓ Stokta *' . $item['similar'] . '* de var — aynı ürünse "' . $item['similar'] . '" yazın';
            }
        }

        return implode("\n", $lines);
    }

    private static function productAddSummary(array $d): string
    {
        $lines = ['🆕 *Ürün kartı açılacak — kontrol et:*'];
        foreach ($d['items'] as $item) {
            $lines[] = '• ' . $item['description'] . ' (' . ($item['unit'] ?? 'adet') . ')'
                . ($item['unit_price'] !== null ? ' · alış ' . Money::format((float) $item['unit_price']) . ' ₺' : '')
                . (! empty($item['quantity']) ? ' · stok 0 → ' . StockUsage::quantity((float) $item['quantity']) . ' ' . ($item['unit'] ?? 'adet') : '');
        }
        $unit = $d['items'][0]['unit'] ?? 'adet';
        if (($d['daily_usage'] ?? null) !== null) {
            $lines[] = '• Her sabah ' . StockUsage::quantity((float) $d['daily_usage']) . ' ' . $unit . ' stoktan düşülecek (yarından itibaren)';
        }
        if (($d['reorder_level'] ?? null) !== null) {
            $lines[] = '• ' . StockUsage::quantity((float) $d['reorder_level']) . ' ' . $unit . ' kalınca sabah 08:00\'de haber vereceğim';
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
            ExpenseExtractor::KIND_STOCK_UPDATE => StockCard::commit($d),
            ExpenseExtractor::KIND_PRODUCT_RENAME => self::commitRename($d),
            ExpenseExtractor::KIND_PRODUCTION => self::commitProduction($d),
        };
    }

    private static function commitSale(array $d): array
    {
        $party = empty($d['paid']) ? self::resolveParty($d) : null;
        $created = [];
        foreach ($d['items'] ?? [] as $i => $item) {
            if (! empty($item['new']) && ! empty($item['stockless']) && empty($item['product_id'])) {
                $product = self::createStocklessProduct($item['description'], $item['unit'] ?? null, $item['unit_price'] ?? null);
                $d['items'][$i]['product_id'] = $product->id;
                if ($product->wasRecentlyCreated) {
                    $created[] = $product;
                }
            } elseif (! empty($item['product_id']) && ($item['unit_price'] ?? null) !== null) {
                self::rememberSalePrice((int) $item['product_id'], (float) $item['unit_price']);
            }
        }
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
            'project_id' => self::projectId($d),
        ]);
        if ($party && ! empty($d['due_date'])) {
            $sale->ledgerEntry?->update(['due_date' => $d['due_date']]);
        }

        // K4: aynı mesajdaki gider ayrı kayıt.
        $extraText = '';
        if (($extra = (float) ($d['extra_expense']['amount'] ?? 0)) > 0 && \App\Support\Features::expenses()) {
            Expense::create([
                'expense_date' => $d['date'] ?? now()->toDateString(),
                'amount' => Money::store($extra),
                'payment_status' => 'paid',
                'description' => $d['extra_expense']['description'] ?? 'Gider',
                'project_id' => self::projectId($d),
                'notes' => 'WhatsApp üzerinden girildi (kasa mesajı).',
            ]);
            $extraText = "\n📝 Gider kaydedildi: " . Money::format($extra) . ' ₺';
        }

        $text = '✅ Satış kaydedildi: ' . Money::format((float) $sale->total_amount) . ' ₺ · '
            . ($party ? $party->name : 'Peşin (' . $sale->paymentLabel() . ')') . $extraText;
        $text .= self::stockAfterLines($lines);
        foreach ($created as $product) {
            $text .= "\n🆕 " . $product->name . ' ürün kartı açıldı'
                . ($product->default_price !== null ? ': ' . ($product->unit?->name ? self::perUnit($product->unit->name) : 'tanesi') . ' ' . Money::format((float) $product->default_price) . ' ₺' : '')
                . ' (stok tutulmuyor)';
        }
        if ($party) {
            $text .= "\n" . PartyBalances::line($party->name, TurnTrace::statement('TradeDraft::commitSale', $party)['balance']);
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
        if (\App\Support\Features::expenses() && ($exp = (float) Expense::whereDate('expense_date', $date)->sum('amount')) > 0) {
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
            'project_id' => self::projectId($d),
        ]);

        $text = '✅ Alış kaydedildi: ' . Money::format((float) $purchase->total_amount) . ' ₺'
            . ($party ? ' · ' . $party->name : '') . (! empty($d['paid']) ? ' (ödendi)' : '')
            . ($created !== [] ? "\n🆕 Ürün kartı açıldı: " . implode(', ', $created) : '');
        $text .= self::stockAfterLines($lines);
        if ($party) {
            $text .= "\n" . PartyBalances::line($party->name, TurnTrace::statement('TradeDraft::commitPurchase', $party)['balance']);
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
        $stocked = false;
        foreach ($d['items'] as $item) {
            $product = self::createProduct($item['description'], $item['unit'] ?? null, $item['unit_price'] ?? null);
            if (! empty($item['quantity'])) {
                $product->countTo((float) $item['quantity'], $d['date'] ?? null);
                $stocked = true;
            }
            $names[] = $product->name . (! empty($item['quantity']) ? ' (stok ' . StockUsage::quantity((float) $item['quantity']) . ' ' . ($item['unit'] ?? 'adet') . ')' : '');
        }
        // Tek ürün: söylenen günlük kullanım / eşik aynı onayla karta yazılır (normalizeProductAdd çok üründe boşaltır).
        $setting = [];
        $notes = [];
        if (count($d['items']) === 1 && (float) ($d['daily_usage'] ?? 0) > 0) {
            $setting += ['auto_usage' => true, 'daily_usage' => (float) $d['daily_usage']];
            $notes[] = 'her sabah ' . StockUsage::quantity((float) $d['daily_usage']) . ' ' . ($d['items'][0]['unit'] ?? 'adet') . ' düşülecek';
        }
        if (count($d['items']) === 1 && (float) ($d['reorder_level'] ?? 0) > 0) {
            $setting['reorder_level'] = (float) $d['reorder_level'];
            $notes[] = StockUsage::quantity((float) $d['reorder_level']) . ' kalınca haber vereceğim';
        }
        if ($setting !== []) {
            $product->update($setting);
        }

        return ['✅ Ürün kartı açıldı: ' . implode(', ', $names) . ($notes ? ' · ' . implode(' · ', $notes) : '')
            . ($stocked ? '' : "\nŞimdi elindekini yazabilirsin, ör. \"" . $d['items'][0]['description'] . ' 15 ' . ($d['items'][0]['unit'] ?? 'adet') . ' var"'), null];
    }

    private static function commitRename(array $d): array
    {
        $product = Product::findOrFail($d['product_id']);
        $old = $product->name;
        $product->update(['name' => $d['product_name']]);

        return ['✅ Ürün adı değişti: ' . $old . ' → ' . $product->name, null];
    }

    /** Üretim: kart yoksa açılır, miktar stoğa girer (sebep "üretim", maliyet yok). */
    private static function commitProduction(array $d): array
    {
        $lines = [];
        foreach ($d['items'] as $item) {
            $product = ! empty($item['product_id']) ? Product::with('unit')->findOrFail($item['product_id'])
                : self::createProduct($item['description'], $item['unit'] ?? null)->load('unit');
            StockMovement::create([
                'product_id' => $product->id,
                'direction' => StockMovement::DIRECTION_IN,
                'reason' => StockMovement::REASON_PRODUCTION,
                'quantity' => (float) $item['quantity'],
                'movement_date' => $d['date'] ?? now()->toDateString(),
                'notes' => 'Üretim (WhatsApp)',
            ]);
            $lines[] = $product->name . ' ' . StockUsage::quantity($product->currentStock()) . ' ' . ($product->unit?->name ?? '');
        }

        return ['✅ Üretim stoğa girdi: ' . implode(' · ', $lines), null];
    }

    /** WhatsApp satışından açılan stoksuz kartın işareti (notes) — fiyatı son satışla güncellenir; elle açılan kartlara dokunulmaz. */
    public const STOCKLESS_NOTE = 'WhatsApp satışından açıldı (stoksuz)';

    /** Satışta kartsız mal → stoksuz kart (yalnız satış fiyatı). Aynı adlı kart varsa onu kullanır. */
    private static function createStocklessProduct(string $name, ?string $unit, ?float $salePrice): Product
    {
        if ($existing = self::matchProduct($name)) {
            return $existing;
        }

        return Product::create([
            'name' => self::title($name),
            'type' => Product::TYPE_SERVICE,
            'unit_id' => self::unitId($unit),
            'default_price' => $salePrice > 0 ? $salePrice : null,
            'is_active' => true,
            'notes' => self::STOCKLESS_NOTE,
        ]);
    }

    /** "Bu sefer litresi 32" → WhatsApp'tan açılmış stoksuz kartın fiyatı güncellenir (stoklu/elle açılmış kartlara dokunulmaz). */
    private static function rememberSalePrice(int $productId, float $price): void
    {
        $product = Product::find($productId);
        if ($product && $price > 0 && $product->notes === self::STOCKLESS_NOTE
            && abs((float) $product->default_price - $price) >= 0.005) {
            $product->update(['default_price' => $price]);
        }
    }

    /** "litre" → "litresi", "kg" → "kilosu", "ton" → "tonu"; bilinmeyen → "birimi". */
    private static function perUnit(string $unit): string
    {
        return match (mb_strtolower(trim($unit))) {
            'litre', 'lt', 'l' => 'litresi',
            'kg', 'kilo', 'kilogram' => 'kilosu',
            'ton' => 'tonu',
            'adet', 'tane' => 'tanesi',
            'çuval', 'cuval' => 'çuvalı',
            'koli' => 'kolisi',
            'paket' => 'paketi',
            'kasa' => 'kasası',
            'balya' => 'balyası',
            'metre', 'm' => 'metresi',
            default => 'birimi',
        };
    }

    /** Ürün kartı (stoklu). Aynı adlı ürün o arada açıldıysa onu kullanır — çift kart olmasın. */
    /** Stok kartı (StockCard) yeni ürünü buradan açar — birim ve tür kuralları tek yerde. */
    public static function openProduct(string $name, ?string $unit): Product
    {
        return self::createProduct($name, $unit)->load('unit');
    }

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

    /** Proje / şube: seçilen ya da mesajda adı geçen (yeni ad ise açılır). */
    private static function projectId(array $d): ?int
    {
        if (! \App\Support\Features::places()) {
            return null;
        }
        if (! empty($d['project_id'])) {
            return (int) $d['project_id'];
        }

        return ! empty($d['project_name'])
            ? \App\Models\Project::firstOrCreate(['name' => $d['project_name']], ['status' => 'active'])->id
            : null;
    }

    // ---------------------------------------------------------------- sorular (kayıt yok)

    public static function answer(array $d): string
    {
        if ($d['kind'] === ExpenseExtractor::KIND_MONTH_RESULT) {
            // Şube sorusu ("dünkü Ergenekon ciro") — yalnız mevcut şube; soru cevabında yeni şube açılmaz.
            $branch = \App\Support\Features::places() && ! empty($d['project_id']) ? (int) $d['project_id'] : null;
            if (! empty($d['date_from'])) {
                $from = Carbon::parse($d['date_from']);
                $to = ! empty($d['date_to']) ? Carbon::parse($d['date_to']) : $from->copy();
                // Gece 00:00–05:00 "bugün" = biten iş günü (fırın gece kapanır; Akkuş 00:12'de "bugünkü cirolar").
                $local = now(Reminder::TIMEZONE);
                $today = $local->toDateString();
                $note = '';
                if ($local->hour < 5 && $from->toDateString() === $today && $to->toDateString() === $today) {
                    $from = $to = $local->copy()->subDay()->startOfDay();
                    $note = "\n_Gece yarısını geçtiği için dünü (" . $from->locale('tr')->translatedFormat('j F') . ') gösterdim._';
                }
                if ($to->lt($from)) {
                    [$from, $to] = [$to, $from];
                }

                return MonthlyResult::whatsappText(MonthlyResult::forRange($from, $to, $branch)) . $note;
            }
            $month = ! empty($d['month']) ? Carbon::parse($d['month'] . '-01') : now();
            $text = MonthlyResult::whatsappText($branch
                ? MonthlyResult::forRange($month->copy()->startOfMonth(), $month->copy()->endOfMonth(), $branch)
                : MonthlyResult::forMonth($month));
            // Dönem söylenmedi ("Ergenekon ciro ne kadar", Akkuş 16.10 21:57 — kasayı yeni girmişti): önce bugünün cirosu, sonra ay.
            // Bugün = iş günü (gece 00–05 dün).
            if (empty($d['month'])) {
                $local = now(Reminder::TIMEZONE);
                $day = ($local->hour < 5 ? $local->copy()->subDay() : $local->copy())->startOfDay();
                $revenue = MonthlyResult::forRange($day, $day, $branch)['revenue'];
                $text = '📅 *' . ($local->hour < 5 ? 'Dün' : 'Bugün') . ' (' . $day->locale('tr')->translatedFormat('j F') . ')*: ciro '
                    . ($revenue > 0 ? Money::format($revenue) . ' ₺' : 'henüz girilmedi') . "\n\n" . $text;
            }

            return $text;
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

    /**
     * Kalem adı ↔ ürün kartı (17.10, Oktay: "besi yemi" ve "buzağı yemi" sessizce "Yem" kartına bağlanıyordu). Eşleşme ancak
     * kartın her kelimesi yazılanda varsa VE yazılanda kartta olmayan ayırt edici bir kelime yoksa ("besi" yok → yeni ürün).
     * Ek toleransı: "yemi" = "yem", "unu" = "un". Birim/sayı/dolgu kelimeleri sayılmaz.
     */
    public static function matchProduct(string $text): ?Product
    {
        if (self::words($text) === []) {
            return null;
        }
        $hits = Product::active()->get()->filter(fn (Product $p) => self::fits($p->name, $text));

        return $hits->sortByDesc(fn (Product $p) => mb_strlen($p->name))->first();
    }

    /** Uzun serbest açıklamada geçen ürün ("Ak Un'dan 20 çuval un aldım") — kalem yokken kaba tanıma (eski davranış). */
    public static function mentionedProduct(string $text): ?Product
    {
        $text = self::lower($text);
        if ($text === '') {
            return null;
        }
        $hits = Product::active()->get()->filter(function (Product $p) use ($text) {
            $name = self::lower($p->name);

            return $name !== '' && preg_match('/(^|[^\p{L}])' . preg_quote($name, '/') . '\p{L}{0,3}([^\p{L}]|$)/u', $text);
        });

        return $hits->sortByDesc(fn (Product $p) => mb_strlen($p->name))->first();
    }

    /** Yeni kalemle bir kelimesi ortak mevcut kart ("besi yemi" ↔ "Yem") — özette "aynı mı?" diye hatırlatılır. */
    public static function similarProduct(string $text): ?Product
    {
        $words = self::words($text);
        if ($words === []) {
            return null;
        }

        return Product::active()->get()->first(function (Product $p) use ($words) {
            foreach (self::words($p->name) as $n) {
                foreach ($words as $w) {
                    if (self::same($w, $n)) {
                        return true;
                    }
                }
            }

            return false;
        });
    }

    private const FILLER = ['adet', 'tane', 'kg', 'kilo', 'gr', 'gram', 'ton', 'çuval', 'cuval', 'koli', 'teneke', 'paket', 'kutu', 'balya',
        'torba', 'm2', 'm²', 'm3', 'm³', 'metre', 'mt', 'lt', 'litre', 'şişe', 'sise', 'kasa', 'bidon', 've', 'ile', 'da', 'de',
        // Kayıt dili ("Buzağı yemi sayımı", "un stoğu", "elimde var") — ürünün adı değil.
        'sayım', 'sayımı', 'sayim', 'sayimi', 'stok', 'stoğu', 'stoku', 'stoğa', 'elimde', 'var', 'kaldı', 'kaldi', 'alış', 'alışı',
        'satış', 'satışı', 'ürün', 'ürünü', 'giriş', 'girişi', 'ekle'];

    /** @return list<string> ayırt edici kelimeler (sayı, birim, dolgu yok) */
    private static function words(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}²³]+/u', self::lower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($parts, fn ($w) => ! preg_match('/^\d/u', $w) && ! in_array($w, self::FILLER, true) && mb_strlen($w) >= 2));
    }

    /** "yemi" ~ "yem", "unu" ~ "un" (Türkçe ek, en çok 3 harf). */
    private static function same(string $a, string $b): bool
    {
        [$short, $long] = mb_strlen($a) <= mb_strlen($b) ? [$a, $b] : [$b, $a];

        return $a === $b || (str_starts_with($long, $short) && mb_strlen($long) - mb_strlen($short) <= 3 && mb_strlen($short) >= 2);
    }

    public static function fits(string $name, string $text): bool
    {
        $n = self::words($name);
        $t = self::words($text);
        if ($n === [] || $t === []) {
            return false;
        }
        foreach ($n as $nw) {
            if (! array_filter($t, fn ($tw) => self::same($tw, $nw))) {
                return false;
            }
        }
        foreach ($t as $tw) {
            if (! array_filter($n, fn ($nw) => self::same($tw, $nw))) {
                return false; // kartta olmayan ayırt edici kelime ("besi") → başka ürün
            }
        }

        return true;
    }

    /**
     * Açıklamada kartın adı geçiyor ama önünde niteleyen bir kelime varsa ("Buzağı yemi sayımı" ↔ "Yem") o nitelikli adı döner
     * (başka ürün); kartın adı başta ya da dolgu kelimesinden sonra geliyorsa ("Un günlük kullanım…") null (aynı ürün).
     */
    /** Malın kartı: ad eşleşir ve önünde niteleyen kelime yoksa ("Börek unu" → "Un" kartı DEĞİL; "un" → "Un"). */
    public static function goodsMatch(string $goods): ?Product
    {
        $product = self::matchProduct($goods);

        return $product && self::qualifiedName($product->name, $goods) === null ? $product : null;
    }

    private static function qualifiedName(string $productName, string $text): ?string
    {
        $first = self::words($productName)[0] ?? null;
        $tokens = self::words($text);
        if ($first === null) {
            return null;
        }
        foreach ($tokens as $i => $t) {
            if (self::same($t, $first)) {
                return $i > 0 ? self::title($tokens[$i - 1] . ' ' . $t) : null;
            }
        }

        return null;
    }

    /** Kalemin ürünü: AI'ın verdiği id kalem adına uymuyorsa yok sayılır (AI "besi yemi"ni "Yem"e bağlamasın). */
    private static function itemProduct(array $item): ?Product
    {
        $desc = trim((string) ($item['description'] ?? ''));
        $p = self::validProduct($item['product_id'] ?? null);
        if ($p && $desc !== '' && ! self::fits($p->name, $desc)) {
            $p = null;
        }

        return $p ?? ($desc !== '' ? self::matchProduct($desc) : null);
    }

    private static function validProduct(mixed $id): ?Product
    {
        return $id ? Product::active()->find((int) $id) : null;
    }

    private static function productFromItems(array $items): ?Product
    {
        foreach ($items as $item) {
            if (! empty($item['product_id']) && ($p = self::itemProduct($item))) {
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

    public static function title(string $s): string
    {
        $s = trim($s);

        return mb_strtoupper(mb_substr(strtr(mb_substr($s, 0, 1), ['i' => 'İ']), 0, 1)) . mb_substr($s, 1);
    }

    private static function hasCatalogItem(array $items): bool
    {
        foreach ($items as $item) {
            if (self::itemProduct($item)) {
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
