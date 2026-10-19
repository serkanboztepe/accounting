<?php

namespace App\Services\Whatsapp;

use App\Models\Product;
use App\Support\StockUsage;

/**
 * Birleşik stok kartı (19.10, Oktay 18.10: "32 besi yemi, 17 ezme, 10 buzağı yemi var, güncelle" → yalnız ilki önerildi, ikisi
 * kayboldu; "günde 16 gidiyor" ayrı soruldu ya da kayboldu). Tek mesaj, tek kart, tek evet — her ürün bir satır:
 *   • var olan ürün → elindeki sayıya eşitlenir (33 → 32) ya da üstüne eklenir (5 + 10 = 15)
 *   • olmayan ürün → 🆕 kart açılır, söylenen miktarla başlar; benzer kart varsa satırda "aynı mı?"
 *   • günlük kullanım ("her sabah 12", "her 3 günde 1") ve uyarı eşiği o satırın ayarı; söylenmeyen değişmez
 *   • "X ekle" var olan, stoğu olan üründe belirsizdir → SORULUR ("17 mi olsun, 5 + 17 = 22 mi?"), tahmin yok
 */
final class StockCard
{
    public const MODES = ['set', 'add', 'unclear'];

    /** AI şeması: `stock_items` (stock_count / stock_setting / product_add). */
    public static function schema(): array
    {
        return [
            'type' => ['array', 'null'],
            'description' => 'Stok miktarı / günlük kullanım / uyarı eşiği / yeni ürün kartı — bir ya da birden çok ürün, HER ÜRÜN BİR SATIR, hepsi tek kayıt. '
                . 'Söylenmeyen alanı null bırak.',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'product_id' => ['type' => ['integer', 'null'], 'description' => 'ÜRÜNLER listesinden — yalnız AYNI ürünse ("buzağı yemi" listedeki "Besi Yemi" DEĞİLDİR); yoksa null'],
                    'product_name' => ['type' => 'string', 'description' => 'Kullanıcının yazdığı ürün adı'],
                    'unit' => ['type' => ['string', 'null'], 'description' => 'Birim (adet, çuval, kg, balya) — söylendiyse'],
                    'quantity' => ['type' => ['number', 'null'], 'description' => 'Söylenen miktar (yoksa null)'],
                    'quantity_mode' => ['type' => ['string', 'null'], 'enum' => [...self::MODES, null],
                        'description' => 'set = elde kalan / sayım ("elimde 32 var", "17 kaldı", "güncelle", "stok 10 olsun"); add = ÜSTÜNE ekle '
                            . '("10 tane daha", "üstüne 5 ekle", "stoğa 10 daha"); unclear = listede OLAN ürün için yalnız "X ekle" '
                            . 'denmiş, elde kalan mı üstüne mi belli değil. Yeni üründe set.'],
                    'usage_amount' => ['type' => ['number', 'null'], 'description' => 'Her seferinde düşülecek miktar ("günde 12" → 12, "3 günde 1" → 1) — yalnız bu ürün için söylendiyse'],
                    'usage_every_days' => ['type' => ['integer', 'null'], 'description' => 'Kaç günde bir ("günde" → 1, "3 günde 1" → 3, "haftada 2" → 7 ve usage_amount 2)'],
                    'reorder_level' => ['type' => ['number', 'null'], 'description' => 'Kaç kalınca haber verilsin — YALNIZ bu ürün için söylendiyse (cümlede hangi ürünün yanındaysa); başka ürüne taşıma'],
                ],
                'required' => ['product_name'],
            ],
        ];
    }

    /** AI girdisi → güvenli satırlar (ham). */
    public static function sanitize(mixed $raw): array
    {
        $rows = [];
        foreach ((array) $raw as $r) {
            if (! is_array($r)) {
                continue;
            }
            $name = trim((string) ($r['product_name'] ?? ''));
            if ($name === '' && empty($r['product_id'])) {
                continue;
            }
            $num = fn ($k) => isset($r[$k]) && is_numeric($r[$k]) && (float) $r[$k] >= 0 ? round((float) $r[$k], 2) : null;
            $rows[] = [
                'product_id' => ! empty($r['product_id']) ? (int) $r['product_id'] : null,
                'product_name' => $name,
                'unit' => is_string($r['unit'] ?? null) && trim($r['unit']) !== '' ? mb_strtolower(trim($r['unit'])) : null,
                'quantity' => $num('quantity'),
                'quantity_mode' => in_array($r['quantity_mode'] ?? null, self::MODES, true) ? $r['quantity_mode'] : null,
                'usage_amount' => ($u = $num('usage_amount')) > 0 ? $u : null,
                'usage_every_days' => isset($r['usage_every_days']) && (int) $r['usage_every_days'] >= 1 ? min(365, (int) $r['usage_every_days']) : null,
                'reorder_level' => $num('reorder_level'),
            ];
        }

        return $rows;
    }

    /** Satırları katalogla eşle (tekrar çalışınca aynı sonuç). */
    public static function normalize(array $d): array
    {
        $rows = [];
        foreach ((array) ($d['stock_items'] ?? []) as $r) {
            $name = trim((string) ($r['product_name'] ?? ''));
            $product = ! empty($r['product_id']) ? Product::active()->find((int) $r['product_id']) : null;
            if ($product && $name !== '' && ! TradeDraft::fits($product->name, $name)) {
                $product = null; // "buzağı yemi" → AI "Besi Yemi"ni seçmiş; adı tutmuyor
            }
            $product ??= $name !== '' ? TradeDraft::matchProduct($name) : null;
            if ($product && ! $product->isProduct()) {
                $product = null;
            }
            // Aynı ürün iki kez yazıldıysa son yazılan geçerli.
            $key = $product ? 'p' . $product->id : 'n' . mb_strtolower($name);
            $mode = $r['quantity_mode'] ?? null;
            if ($r['quantity'] === null) {
                $mode = null;
            } elseif (! $product || $mode === null || ($mode === 'unclear' && $product->currentStock() <= 0.005)) {
                $mode = $mode === 'add' && $product ? 'add' : 'set'; // yeni kart / boş stok: "ekle" = o kadar olur
            }
            $rows[$key] = [
                'product_id' => $product?->id,
                'product_name' => $product?->name ?? TradeDraft::title($name),
                'unit' => $r['unit'] ?? null,
                'quantity' => $r['quantity'],
                'quantity_mode' => $mode,
                'usage_amount' => $r['usage_amount'] ?? null,
                'usage_every_days' => $r['usage_every_days'] ?? null,
                'reorder_level' => $r['reorder_level'] ?? null,
                'new' => ! $product,
                'similar' => ! $product ? TradeDraft::similarProduct($name)?->name : null,
            ];
        }
        $d['kind'] = ExpenseExtractor::KIND_STOCK_UPDATE;
        $d['stock_items'] = array_values($rows);
        $d['amount'] = 0.0;
        $d['items'] = [];
        // Eski tek ürün alanları bu kartta kullanılmaz (ikinci kez işlenmesin).
        $d['product_id'] = $d['counted_quantity'] = $d['daily_usage'] = $d['reorder_level'] = $d['new_product'] = null;

        return $d;
    }

    public static function blocker(array $d): ?string
    {
        $rows = $d['stock_items'] ?? [];
        if ($rows === []) {
            return 'Hangi ürün? Ürünün adını ve elindeki miktarı yazar mısın?';
        }
        $ask = [];
        foreach ($rows as $r) {
            if ($r['quantity_mode'] === 'unclear') {
                $now = self::stock($r);
                $ask[] = $r['product_name'] . ' ' . self::q($r['quantity']) . ' mi olsun, yoksa ' . self::q($now) . ' + ' . self::q($r['quantity'])
                    . ' = ' . self::q($now + $r['quantity']) . ' mi?';
            } elseif ($r['quantity'] === null && $r['usage_amount'] === null && $r['reorder_level'] === null && empty($r['new'])) {
                $ask[] = $r['product_name'] . ' için ne yapayım: elindeki miktar mı, günde ne kadar gittiği mi?';
            }
        }

        return $ask === [] ? null : implode("\n❓ ", $ask);
    }

    /**
     * "17 mi olsun, 5 + 17 = 22 mi?" sorusuna yalnız sayıyla cevap: 17 → eşitle, 22 → üstüne ekle, başka sayı → elindeki o.
     * Tek belirsiz satırda; birden çoksa (hangi sayı hangisine?) null → AI okur.
     */
    public static function answer(array $d, string $body): ?array
    {
        $unclear = array_keys(array_filter($d['stock_items'] ?? [], fn ($r) => ($r['quantity_mode'] ?? null) === 'unclear'));
        $n = \App\Support\Money::parse(trim($body));
        if (count($unclear) !== 1 || ! preg_match('/^\s*\d[\d.,]*\s*$/u', $body) || $n < 0) {
            return null;
        }
        $i = $unclear[0];
        $r = $d['stock_items'][$i];
        $now = self::stock($r);
        if (abs($n - $now - $r['quantity']) < 0.005 && abs($n - $r['quantity']) >= 0.005) {
            $d['stock_items'][$i]['quantity_mode'] = 'add';
        } else {
            $d['stock_items'][$i]['quantity'] = $n;
            $d['stock_items'][$i]['quantity_mode'] = 'set';
        }

        return $d;
    }

    public static function summary(array $d): string
    {
        $lines = ['📦 *Stok — kontrol et:*'];
        foreach ($d['stock_items'] ?? [] as $r) {
            $unit = self::unit($r);
            $now = self::stock($r);
            $parts = [];
            if ($r['quantity'] !== null) {
                $parts[] = match ($r['quantity_mode']) {
                    'add' => self::q($now) . ' + ' . self::q($r['quantity']) . ' = ' . self::q($now + $r['quantity']) . $unit,
                    'unclear' => 'şu an ' . self::q($now) . $unit . ' (' . self::q($r['quantity']) . ' mi olsun, üstüne mi?)',
                    default => self::q($now) . ' → ' . self::q($r['quantity']) . $unit
                        . (! empty($r['product_id']) && abs($now - $r['quantity']) < 0.005 ? ' (zaten doğru)' : ''),
                };
            } elseif (! empty($r['product_id'])) {
                $parts[] = 'stok ' . self::q($now) . $unit;
            }
            if ($r['usage_amount'] !== null) {
                $parts[] = self::usageText($r) . ' düşülür' . (($r['usage_every_days'] ?? 1) > 1 ? '' : ' (yarından itibaren)');
            }
            if ($r['reorder_level'] !== null) {
                $parts[] = self::q($r['reorder_level']) . $unit . ' kalınca sabah 08:00 uyarı';
            }
            $lines[] = '• ' . $r['product_name'] . (! empty($r['new']) ? ' 🆕' : '') . ($parts ? ': ' . implode(' · ', $parts) : '');
            if (! empty($r['similar'])) {
                $lines[] = '   ❓ Stokta *' . $r['similar'] . '* de var — aynı ürünse "' . $r['similar'] . '" yazın';
            }
        }
        if (collect($d['stock_items'] ?? [])->contains(fn ($r) => ! empty($r['new']))) {
            $lines[] = '🆕 = yeni ürün kartı açılacak';
        }

        return implode("\n", $lines);
    }

    /** @return array{0:string,1:?int} */
    public static function commit(array $d): array
    {
        $done = [];
        foreach ($d['stock_items'] ?? [] as $r) {
            $product = ! empty($r['product_id']) ? Product::with('unit')->findOrFail($r['product_id'])
                : TradeDraft::openProduct($r['product_name'], $r['unit'] ?? null);
            $line = $product->name . (! empty($r['new']) ? ' (yeni kart)' : '');
            if ($r['quantity'] !== null && $r['quantity_mode'] !== 'unclear') {
                $target = $r['quantity_mode'] === 'add' ? $product->currentStock() + $r['quantity'] : $r['quantity'];
                $product->countTo((float) $target, $d['date'] ?? null, $r['quantity_mode'] === 'add'
                    ? 'Ekleme: +' . self::q($r['quantity']) : null);
                $line .= ' ' . self::q($product->currentStock()) . ($product->unit?->name ? ' ' . $product->unit->name : '');
            }
            $setting = [];
            if ($r['usage_amount'] !== null) {
                $setting += ['auto_usage' => true, 'daily_usage' => $r['usage_amount'],
                    'usage_every_days' => ($r['usage_every_days'] ?? 1) > 1 ? $r['usage_every_days'] : null];
            }
            if ($r['reorder_level'] !== null) {
                $setting['reorder_level'] = $r['reorder_level'] > 0 ? $r['reorder_level'] : null;
            }
            if ($setting !== []) {
                $product->update($setting);
                $product->refresh();
                $line .= ' · ' . implode(' · ', array_filter([
                    $product->usageLabel() !== null ? $product->usageLabel() . ' düşülecek' : null,
                    $r['reorder_level'] !== null ? self::q($r['reorder_level']) . ' kalınca haber vereceğim' : null,
                ]));
            }
            $done[] = $line;
        }

        return [count($done) > 1 ? "✅ Stok güncellendi:\n• " . implode("\n• ", $done) : '✅ Stok güncellendi: ' . $done[0], null];
    }

    private static function usageText(array $r): string
    {
        $q = self::q($r['usage_amount']) . self::unit($r);

        return ($r['usage_every_days'] ?? 1) > 1 ? 'her ' . $r['usage_every_days'] . ' günde ' . $q : 'her sabah ' . $q;
    }

    private static function stock(array $r): float
    {
        return ! empty($r['product_id']) ? (float) (Product::find($r['product_id'])?->currentStock() ?? 0) : 0.0;
    }

    private static function unit(array $r): string
    {
        $name = ! empty($r['product_id']) ? Product::with('unit')->find($r['product_id'])?->unit?->name : ($r['unit'] ?? null);

        return $name ? ' ' . $name : '';
    }

    private static function q(float|int|null $n): string
    {
        return StockUsage::quantity((float) $n);
    }
}
