<?php

namespace App\Services\Whatsapp;

use App\Models\Expense;
use App\Models\PartyLedgerEntry;
use App\Models\Purchase;
use App\Models\Sale;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Faz 4 — kayıt sırasında uyarı (mesaj atmaz; taslak özetine ⚠️ satırı ekler, kayıt yine kullanıcının onayıyla):
 *  - ÇİFT KAYIT: aynı tutar + aynı kişi/kategori/şube yakın günlerde zaten var (Demir Medya kira ×3, 15.10).
 *  - OLAĞANDIŞI TUTAR: bu kişi/kategori/şubenin olağanının çok üstü ya da altı (Akkuş 25.113, fazladan sıfır).
 * Yalnız okuma; rakamları kod hesaplar. Kesin değil — "doğru mu?" diye sorar, engellemez.
 */
final class DraftWarnings
{
    private const DUP_DAYS = 3;

    private const EXPENSE_DUP_DAYS = 7;

    /**
     * Olağandışı: en az bu kadar geçmiş kayıt varsa ve tutar ortancanın bu katı ve üstündeyse — yalnız FAZLADAN SIFIR.
     * Düşük tutar uyarısı yok, eşik 4 kat → 8 kat (kullanıcı 20.10: "genelde ~200.000, bu 50.000 — doğru mu? bu da gereksiz";
     * 200 binlik işe 50 bin ödemek olağan). Akkuş 25.113 (~15 kat) yine yakalanır.
     */
    private const MIN_HISTORY = 3;

    private const HIGH = 8.0;

    /** @return list<string> */
    public static function for(array $d): array
    {
        $amount = (float) ($d['amount'] ?? 0);
        if ($amount <= 0) {
            return [];
        }
        try {
            return array_values(array_filter([self::duplicate($d, $amount), self::unusual($d, $amount)]));
        } catch (\Throwable) {
            return []; // uyarı yardımcıdır — kayıt akışını asla bozmaz
        }
    }

    private static function date(array $d): Carbon
    {
        return Carbon::parse($d['date'] ?? now()->toDateString());
    }

    private static function near($q, string $col, Carbon $day, int $days)
    {
        return $q->whereBetween($col, [$day->copy()->subDays($days)->toDateString(), $day->copy()->addDays($days)->toDateString()]);
    }

    private static function same(float $a, $b): bool
    {
        return abs($a - (float) $b) < 0.5;
    }

    private static function duplicate(array $d, float $amount): ?string
    {
        $day = self::date($d);
        $kind = $d['kind'] ?? null;
        $hit = null;

        if ($kind === ExpenseExtractor::KIND_EXPENSE) {
            $hit = self::near(Expense::query(), 'expense_date', $day, self::EXPENSE_DUP_DAYS)
                ->when(! empty($d['category_id']), fn ($q) => $q->where('expense_category_id', $d['category_id']))
                ->get(['expense_date', 'amount', 'description'])->first(fn ($e) => self::same($amount, $e->amount));
            $hit = $hit ? [$hit->expense_date, $hit->description ?: 'gider'] : null;
        } elseif ($kind === ExpenseExtractor::KIND_TRADE_SALE && empty($d['party_id']) && empty($d['party_name'])) {
            // Kasa: aynı gün aynı şubeye aynı ciro iki kez.
            $hit = Sale::whereNull('party_id')->whereDate('sale_date', $day->toDateString())
                ->when(! empty($d['project_id']), fn ($q) => $q->where('project_id', $d['project_id']))
                ->get(['sale_date', 'total_amount', 'notes'])->first(fn ($s) => self::same($amount, $s->total_amount));
            $hit = $hit ? [$hit->sale_date, 'kasa'] : null;
        } elseif ($kind === ExpenseExtractor::KIND_TRADE_PURCHASE && ! empty($d['party_id'])) {
            $hit = self::near(Purchase::where('party_id', $d['party_id']), 'purchase_date', $day, self::DUP_DAYS)
                ->get(['purchase_date', 'total_amount'])->first(fn ($p) => self::same($amount, $p->total_amount));
            $hit = $hit ? [$hit->purchase_date, 'alış'] : null;
        } elseif (! empty($d['party_id']) && ($type = self::ledgerType($kind)) !== null) {
            // Çek numaraları farklıysa ayrı ödeme (21.10: aynı tutarlı ikinci çek sayfası "iki kez olmasın?" uyarısı aldı).
            $numbers = array_filter(array_map(fn ($c) => is_array($c) ? trim((string) ($c['number'] ?? '')) : '', (array) ($d['checks'] ?? [])));
            $hit = self::near(PartyLedgerEntry::where('party_id', $d['party_id'])->where('type', $type), 'entry_date', $day, self::DUP_DAYS)
                ->with('checks:id,party_ledger_entry_id,check_number')
                ->get(['id', 'entry_date', 'amount', 'description'])->first(fn ($e) => self::same($amount, $e->amount)
                    && ($numbers === [] || $e->checks->isEmpty() || array_intersect($numbers, $e->checks->pluck('check_number')->all()) !== []));
            $hit = $hit ? [$hit->entry_date, $hit->description ?: 'kayıt'] : null;
        }

        if (! $hit) {
            return null;
        }

        $what = preg_replace('/\s*—\s*\d+ çek: .*$/u', '', (string) $hit[1]); // çek listesi uyarıda tekrar edilmez
        $what = mb_strlen($what) > 50 ? mb_substr($what, 0, 50) . '…' : $what;

        return '⚠️ Aynı tutarda ' . mb_strtolower($what) . ' ' . Carbon::parse($hit[0])->format('d.m')
            . ' günü de girilmiş — iki kez olmasın?';
    }

    private static function unusual(array $d, float $amount): ?string
    {
        $kind = $d['kind'] ?? null;
        $history = collect();
        $what = null;

        if ($kind === ExpenseExtractor::KIND_EXPENSE && ! empty($d['category_id'])) {
            // Düzenli giderler (her gün 34,60 ekmek) ölçüye girmez — normal gider "fazladan sıfır mı?" sanılıyordu (Çiftlik, 25.10).
            $history = Expense::where('expense_category_id', $d['category_id'])
                ->where(fn ($q) => $q->whereNull('notes')->orWhere('notes', 'not like', 'Düzenli gider #%'))
                ->latest('expense_date')->limit(20)->pluck('amount');
            $what = 'bu kategoride';
        } elseif ($kind === ExpenseExtractor::KIND_TRADE_SALE && empty($d['party_id']) && empty($d['party_name'])) {
            $history = Sale::whereNull('party_id')->when(! empty($d['project_id']), fn ($q) => $q->where('project_id', $d['project_id']))
                ->latest('sale_date')->limit(14)->pluck('total_amount');
            $what = ! empty($d['project_id']) ? 'bu şubenin günlük kasası' : 'günlük kasa';
        } elseif (! empty($d['party_id']) && ($type = self::ledgerType($kind)) !== null) {
            $history = PartyLedgerEntry::where('party_id', $d['party_id'])->where('type', $type)->latest('entry_date')->limit(20)->pluck('amount');
            $what = 'bu kişiyle';
        }

        $history = $history->map(fn ($v) => (float) $v)->filter(fn ($v) => $v > 0)->values();
        if ($history->count() < self::MIN_HISTORY) {
            return null;
        }
        $median = self::median($history);
        if ($median <= 0 || $amount < $median * self::HIGH) {
            return null;
        }

        return '⚠️ ' . ucfirst($what) . ' genelde ~' . Money::format(round($median)) . ' ₺, bu ' . Money::format($amount)
            . ' ₺ — fazladan sıfır olmasın, doğru mu?';
    }

    private static function median(Collection $values): float
    {
        $s = $values->sort()->values();
        $n = $s->count();

        return $n % 2 ? $s[intdiv($n, 2)] : ($s[$n / 2 - 1] + $s[$n / 2]) / 2;
    }

    private static function ledgerType(?string $kind): ?string
    {
        return match ($kind) {
            ExpenseExtractor::KIND_PAYMENT => PartyLedgerEntry::TYPE_PAYMENT,
            ExpenseExtractor::KIND_COLLECTION => PartyLedgerEntry::TYPE_COLLECTION,
            ExpenseExtractor::KIND_SALE => PartyLedgerEntry::TYPE_SALE,
            ExpenseExtractor::KIND_PURCHASE => PartyLedgerEntry::TYPE_PURCHASE,
            default => null,
        };
    }
}
