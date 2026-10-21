<?php

namespace App\Services\Whatsapp;

use App\Models\AssistantMemory;
use App\Models\Product;

/**
 * Kartı olmayan MAL alışında stok sorusu (kullanıcı 21.10, Akkuş "25.000 börek unu ödemesi yapıldı" → un stoğa hiç girmedi;
 * "bence burada stok sormalıydık"). Stoklu firmada, AI'ın `goods_name` ile işaretlediği tek bir mal için (hizmet, kira, mazot,
 * karışık market alışverişi DEĞİL), adet söylenmediyse kayıt öncesi tek numaralı soru:
 *
 *   📦 Börek unu nerede?
 *   *1* Geldi, elimizde — kaç tane? (ör. *1 20 çuval*)
 *   *2* Uncu Muhyiddin'de duruyor, sonra gelecek
 *   *0* Stok tutmuyorum
 *
 * 1 + adet → yeni ürün kartı + stoğa giren alış (mevcut "20 çuval un aldım" akışı) · 2 → parası peşin verilmiş mal = avans
 * (Tokgöz kuralı: tedarikçideki mal stoğa girmez, "geldi" denince girer) · 0 → eskisi gibi stoksuz kayıt ve firma hafızasına
 * "stok tutulmuyor" yazılır, o mal için bir daha sorulmaz. Soru atlanamaz ama 0 her zaman var.
 */
final class StockChoice
{
    private const MEMORY_PREFIX = 'Stok tutulmuyor: ';

    public static function shown(array $d): bool
    {
        return ! empty($d['stock_choice']);
    }

    public static function applies(array $d): bool
    {
        $goods = self::goods($d);
        if ($goods === null || ! empty($d['stock_choice_done']) || ! TradeDraft::enabled()
            || ! config('modules.stock') || ! config('modules.purchases')) {
            return false;
        }
        if (! in_array($d['kind'] ?? null, [ExpenseExtractor::KIND_PURCHASE, ExpenseExtractor::KIND_EXPENSE, ExpenseExtractor::KIND_PAYMENT], true)
            || (($d['kind'] ?? null) === ExpenseExtractor::KIND_PAYMENT && ! self::hasParty($d))
            || ! empty($d['settles_expense_id']) || ! empty($d['debt_repayment']) || ! empty($d['is_staff'])) {
            return false;
        }
        // Adet zaten söylendiyse ya da kartı varsa mevcut akış (yeni kart önerisi / stoklu alış) yürür.
        foreach ((array) ($d['items'] ?? []) as $item) {
            if (! empty($item['product_id']) || (float) ($item['quantity'] ?? 0) > 0) {
                return false;
            }
        }
        // Tedarikçide durduğu (avans) ya da eski borç olduğu zaten belli → stok sorusu yok.
        if (in_array($d['work_status'] ?? null, [Understanding::NOT_YET, Understanding::OLD_DEBT, Understanding::NO_WORK], true)
            && ($d['work_status_known'] ?? null) === true) {
            return false;
        }
        // Kartı olan (stoksuz kart dahil) mal: karar zaten verilmiş.
        if (TradeDraft::goodsMatch($goods)) {
            return false;
        }

        return ! self::remembered($goods);
    }

    public static function goods(array $d): ?string
    {
        $g = trim((string) ($d['goods_name'] ?? ''));

        return $g === '' ? null : mb_strtoupper(mb_substr($g, 0, 1)) . mb_substr($g, 1);
    }

    /** Ödeme: "ne için?" sorusunun yerine geçer (mal geldi / tedarikçide / eski borç) — iki soru üst üste gelmesin. */
    private static function isPayment(array $d): bool
    {
        return ($d['kind'] ?? null) === ExpenseExtractor::KIND_PAYMENT;
    }

    private static function hasParty(array $d): bool
    {
        return ! empty($d['party_id']) || ! empty($d['party_name']);
    }

    public static function question(array $d): string
    {
        $goods = self::goods($d) ?? 'Mal';
        $party = DraftGaps::party($d)?->name ?? ($d['party_name'] ?? null);
        $lines = ['📦 *' . $goods . '* nerede?', '*1* Geldi, elimizde — kaç tane? (ör. *1 20 çuval*)'];
        if (self::hasParty($d)) {
            $lines[] = '*2* ' . ($party ? WorkChoice::locative($party) : 'Satıcıda') . ' duruyor, sonra gelecek';
        }
        if (self::isPayment($d)) {
            $lines[] = '*3* Eski borcu ödedim';
        }
        $lines[] = '*0* Stok tutmuyorum';

        return implode("\n", $lines);
    }

    /**
     * Cevabı oku. Dönüş: ['data' => taslak] kayda hazır · ['ask' => soru] eksik (adet) · null → numara/adet değil (AI okusun).
     *
     * @return array{data?: array, ask?: string}|null
     */
    public static function answer(array $d, string $body): ?array
    {
        $text = trim(mb_strtolower($body));
        if (! preg_match('/^([0123])(?![\d.,])\s*(.*)$/su', $text, $m)) {
            // Numarasız "20 çuval geldi" de adet cevabıdır (soru adet bekliyorsa ya da metin adetle başlıyorsa).
            $qty = self::quantity($text);
            if ($qty !== null && (! empty($d['stock_need_qty']) || preg_match('/^\d/u', $text))) {
                return ['data' => self::arrived($d, $qty[0], $qty[1])];
            }

            return null;
        }
        $choice = (int) $m[1];
        if (($choice === 2 && ! self::hasParty($d)) || ($choice === 3 && ! self::isPayment($d))) {
            return ['ask' => 'Listede o numara yok. ' . self::question($d)];
        }

        return match ($choice) {
            0 => ['data' => self::stockless($d)],
            2 => ['data' => self::atSupplier($d)],
            3 => ['data' => self::known(self::done($d), Understanding::OLD_DEBT)],
            default => ($qty = self::quantity($m[2])) !== null
                ? ['data' => self::arrived($d, $qty[0], $qty[1])]
                : ['ask' => 'Kaç tane geldi? Birimiyle yaz (ör. *20 çuval*, *50 kg*).'],
        };
    }

    /** "20 çuval" / "1,5 ton" / "20" → [20.0, 'çuval'|null]; para sözü ("20 bin", "500 tl") adet değil. */
    public static function quantity(string $text): ?array
    {
        if (! preg_match('/(\d+(?:[.,]\d+)?)\s*([a-zçğıöşü²³]+)?/u', $text, $m)) {
            return null;
        }
        $unit = $m[2] ?? null;
        if (in_array($unit, ['tl', 'lira', 'bin', 'milyon', 'try'], true)) {
            return null;
        }
        $qty = (float) str_replace(',', '.', $m[1]);
        if ($qty <= 0) {
            return null;
        }
        $generic = ['tane', 'geldi', 'elimizde', 'adet'];

        return [$qty, $unit === null || in_array($unit, $generic, true) ? ($unit === 'adet' ? 'adet' : null) : $unit];
    }

    /** Geldi: kartı açılacak mal stoğa giren alış olur (mevcut yeni kart akışı); para ve cari olduğu gibi. */
    private static function arrived(array $d, float $qty, ?string $unit): array
    {
        $payment = self::isPayment($d);
        $d = self::done($d);
        $d['kind'] = ExpenseExtractor::KIND_TRADE_PURCHASE;
        if ($payment) {
            $d['paid'] = true; // mal geldi, parası verildi = peşin alış
        }
        $d['items'] = [[
            'description' => self::goods($d), 'product_id' => null, 'quantity' => $qty, 'unit' => $unit,
            'unit_price' => null, 'amount' => (float) ($d['amount'] ?? 0) ?: null,
        ]];
        // Kişisiz giderdeki satıcı adı alışın carisi olmaz (peşin dükkân alışı) — açıklamada kalır.
        if (empty($d['party_id']) && empty($d['party_name'])) {
            $d['paid'] = true;
        }
        $d = self::known($d, Understanding::DONE);
        $d['category_id'] = $d['category_name'] = null;
        $d['project_id'] = $d['project_name'] = null; // stoklu alış işletme geneli (16.10)

        return TradeDraft::route($d);
    }

    /** Tedarikçide duruyor: parası ödenmiş mal = avans; geldiğinde "geldi" denir, stoğa o zaman girer. */
    private static function atSupplier(array $d): array
    {
        $d = self::known(self::done($d), Understanding::NOT_YET);
        $d['paid'] = true;

        return $d;
    }

    /** Stok tutulmuyor: kayıt eskisi gibi (ödemede: mal geldi); firma hafızasına yazılır, bu mal için bir daha sorulmaz. */
    private static function stockless(array $d): array
    {
        $goods = (string) self::goods($d);
        if (self::isPayment($d)) {
            $d = self::known($d, Understanding::DONE);
        }
        if (! self::remembered($goods)) {
            AssistantMemory::create([
                'kind' => 'note',
                'subject' => self::MEMORY_PREFIX . $goods,
                'fact' => $goods . ' için stok tutulmuyor — alışında stok sorulmaz.',
                'source' => 'Stok sorusuna "0 · Stok tutmuyorum" cevabı',
            ]);
        }

        return self::done($d);
    }

    private static function known(array $d, string $status): array
    {
        return array_merge($d, ['work_status' => $status, 'work_status_known' => true, 'work_choice' => null, 'work_chosen' => true]);
    }

    private static function done(array $d): array
    {
        return array_merge($d, ['stock_choice' => null, 'stock_choice_done' => true, 'stock_need_qty' => null, 'question' => null,
            'is_new_entry' => false]);
    }

    public static function remembered(string $goods): bool
    {
        return AssistantMemory::where('status', 'active')
            ->whereRaw('LOWER(subject) = ?', [mb_strtolower(self::MEMORY_PREFIX . $goods)])
            ->exists();
    }
}
