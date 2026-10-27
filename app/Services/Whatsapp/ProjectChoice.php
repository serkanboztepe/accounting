<?php

namespace App\Services\Whatsapp;

use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Models\WhatsappPendingExpense;
use App\Support\Labels;
use App\Support\Phone;
use Illuminate\Support\Collection;

/**
 * Proje / Şube sorusu — TEK mekanizma (müteahhitte "Proje", fırında "Şube"; ad Labels::project()).
 * Gider, alış, satış ve kasada sorulur; ödeme/tahsilatta sorulmaz (maliyet zaten alışta/giderde).
 *  - mesajda adı geçtiyse (AI project_id doldurdu) ya da tek proje/şube varsa sorulmaz;
 *  - öneri: o carinin son kaydının şubesi; carisizse bu numaranın son 5 aynı tür kaydı hep aynı şubeyse o
 *    (Akkuş 0553 = Ergenekon; iki şube giren Emre'ye sorulur) — *evet* öneriyi kabul eder;
 *  - numara seçer, 0 = genel / ortak (şubesiz);
 *  - peşin kasa (ciro) şubeli firmada ZORUNLU: seçilmeden kaydedilmez.
 */
final class ProjectChoice
{
    // Anlaşma + anlaşmadan teslim (22.10: proje sorulmadı, tuğla Genel'e gitti) — teslimatta yalnız anlaşmanın projesi yoksa sorulur.
    public const KINDS = [ExpenseExtractor::KIND_EXPENSE, ExpenseExtractor::KIND_PURCHASE, ExpenseExtractor::KIND_SALE,
        ExpenseExtractor::KIND_TRADE_SALE, ExpenseExtractor::KIND_TRADE_PURCHASE, ExpenseExtractor::KIND_SALARY,
        ExpenseExtractor::KIND_AGREEMENT, ExpenseExtractor::KIND_AGREEMENT_DELIVERY];

    /**
     * Maliyet yazan türler: proje/şube HER ZAMAN sorulur, hatırlanmaz (kullanıcı 22.10: "her zaman sorsun, hatırlamasın, öyle hata
     * olur" — Gül Mobilya "320 dolap" önceki teslimatın projesine yazılıyordu; "stoklu alım yoksa şube de olsa proje de olsa maliyeti
     * arttırır"). "(son)" önerisi yok, tek proje olsa da sorulur, *evet* numaranın yerine geçmez; mesajda adı geçerse sorulmaz.
     * Stoğa giren alım genel (applies false). Satış/kasa eskisi gibi (öneri + kasada zorunlu).
     */
    public const COST_KINDS = [ExpenseExtractor::KIND_EXPENSE, ExpenseExtractor::KIND_PURCHASE, ExpenseExtractor::KIND_TRADE_PURCHASE,
        ExpenseExtractor::KIND_SALARY, ExpenseExtractor::KIND_AGREEMENT, ExpenseExtractor::KIND_AGREEMENT_DELIVERY];

    public static function alwaysAsk(array $d): bool
    {
        // Var olan borcun ödenmesi (kira ödendi, borç kapandı) yeni maliyet değil — gider zaten kendi projesinde.
        return in_array($d['kind'] ?? ExpenseExtractor::KIND_EXPENSE, self::COST_KINDS, true)
            && empty($d['settles_expense_id']) && empty($d['debt_repayment'])
            // Anlaşmalı SATIŞ / teslim ettiğimiz mal gelir, maliyet değil — eski davranış.
            && ($d['agreement_direction'] ?? null) !== 'sale' && ($d['delivery_direction'] ?? null) !== 'sale';
    }

    public static function applies(array $d): bool
    {
        $kind = $d['kind'] ?? ExpenseExtractor::KIND_EXPENSE;
        // Maaştan kesinti (27.10) maliyet değil, para da çıkmaz — proje sorulmaz.
        if (! empty($d['salary_deduction'])) {
            return false;
        }
        // Mimar (Ferhat, kullanıcı kararı 15.10): eskisi gibi — yalnız giderde proje menüsü, alış/satışta soru yok.
        if (config('app.profile') === 'mimar' && $kind !== ExpenseExtractor::KIND_EXPENSE) {
            return false;
        }

        // Stoklu alış (stoğa giren mal) şube SORMAZ — stok işletme geneli, hammadde tüm şubeler için alınır; şube
        // sorusu "şube stoğu var" sanısı yaratıyordu (Serkan 16.10). Mesajda şube adı geçse de yazılmaz (prepare temizler).
        // "Alışı yere yaz" açık firmada (çiftlik 26.10) sorulur: yem ahıra, gübre tarlaya; üretim de o yerin.
        if (\App\Support\Features::placeCost()) {
            return in_array($kind, [...self::KINDS, ExpenseExtractor::KIND_PRODUCTION], true);
        }
        if ($kind === ExpenseExtractor::KIND_TRADE_PURCHASE && self::hasStockItem($d)) {
            return false;
        }

        return (bool) \App\Support\Features::places() && in_array($kind, self::KINDS, true);
    }

    private static function hasStockItem(array $d): bool
    {
        foreach ($d['items'] ?? [] as $item) {
            if (! empty($item['product_id']) || ! empty($item['new'])) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, Project> sabit sıra — menü numaraları bununla */
    public static function projects(): Collection
    {
        // Eski gider menüsüyle aynı sıra (müteahhit "2" yazma alışkanlığı bozulmasın): iptal hariç, eklenme sırası.
        return TurnTrace::read('ProjectChoice::projects', fn () => Project::whereNotIn('status', ['cancelled'])->orderBy('id')->take(30)->get(['id', 'name'])->values());
    }

    /** Taslağa şube bilgisini yerleştir (mesajdan / tek şube / öneri / soru). */
    public static function prepare(array $d, string $phone): array
    {
        unset($d['project_suggested'], $d['project_needed']);
        // Ürünlü (stoğa giren) alış hiç şubeye yazılmaz — mesajda şube adı geçse de (stok işletme geneli).
        if (($d['kind'] ?? null) === ExpenseExtractor::KIND_TRADE_PURCHASE && self::hasStockItem($d) && ! \App\Support\Features::placeCost()) {
            $d['project_id'] = $d['project_name'] = null;

            return $d;
        }
        if (! self::applies($d) || ! empty($d['project_id']) || ! empty($d['project_name']) || ! empty($d['project_choice_done'])) {
            return $d;
        }
        $projects = self::projects();
        if ($projects->isEmpty()) {
            return $d;
        }
        if (self::alwaysAsk($d)) {
            $d['project_needed'] = true;

            return $d;
        }
        if ($projects->count() === 1) {
            $d['project_id'] = $projects->first()->id;

            return $d;
        }
        if ($suggest = self::suggest($d, $phone, $projects->pluck('id')->all())) {
            $d['project_id'] = $suggest;
            $d['project_suggested'] = true;

            return $d;
        }
        $d['project_needed'] = true;

        return $d;
    }

    /** Kasa (peşin satış) şubeli firmada şubesiz kaydedilmez. */
    public static function blocker(array $d): ?string
    {
        if (! empty($d['project_needed']) && self::isCashSale($d)) {
            return 'Hangi ' . mb_strtolower(Labels::project()) . '? Numarasını yaz.';
        }
        if (! empty($d['project_needed']) && self::alwaysAsk($d) && self::applies($d)) {
            return 'Hangi ' . mb_strtolower(Labels::project()) . '? Numarasını yaz (0 = Genel).';
        }

        return null;
    }

    /** Özete eklenecek satırlar. */
    public static function lines(array $d): array
    {
        if (! self::applies($d)) {
            return [];
        }
        $label = Labels::project();
        $projects = self::projects();
        $icon = $label === 'Şube' ? '🏪' : '🏗';
        // Tek soru, bütün seçenekler (kullanıcı 20.10: "projeleri sorsun, başka seçim yapabileyim; 2 / 0 yazınca kaydetsin"):
        // "🏗 Hangi proje? *1* Fatih (son) · *2* Atatürk · *0* Genel" — öneri "(son)" ile işaretli, numara seçince kayıt.
        // Kalabalıksa (>6) alt alta.
        if (! empty($d['project_suggested']) || ! empty($d['project_needed'])) {
            $suggested = ! empty($d['project_suggested']) ? ($d['project_id'] ?? null) : null;
            $hint = $d['project_hint'] ?? null; // anlaşmanın projesi — işaret, seçim değil
            $inline = $projects->count() <= 6;
            $choices = $projects->map(function ($p, $i) use ($inline, $suggested, $hint) {
                $name = $p->name . ($suggested !== null && (int) $p->id === (int) $suggested ? ' (son)' : '')
                    . ($hint !== null && (int) $p->id === (int) $hint ? ' (anlaşmadaki)' : '');

                return $inline ? '*' . ($i + 1) . '* ' . $name : '   ' . ($i + 1) . ') ' . $name;
            })->all();
            if (! (self::isCashSale($d) && $suggested === null)) {
                $choices[] = $inline ? '*0* Genel' : '   0) Genel / ortak';
            }
            $head = "{$icon} Hangi " . mb_strtolower($label) . '?';

            return $inline ? [$head . ' ' . implode(' · ', $choices)] : array_merge([$head], $choices);
        }
        if (! empty($d['project_id']) && ($p = Project::find($d['project_id']))) {
            return ["{$icon} {$label}: {$p->name}"];
        }
        if (! empty($d['project_name'])) {
            return ["{$icon} {$label}: {$d['project_name']} (yeni)"];
        }

        return [];
    }

    /** Menü gösterildiyse numara cevabı: 0 = genel, n = n. şube; geçersizse null. */
    public static function choose(array $d, int $n): ?array
    {
        if (empty($d['project_suggested']) && empty($d['project_needed'])) {
            return null;
        }
        if ($n === 0) {
            return array_merge($d, ['project_id' => null, 'project_name' => null, 'project_choice_done' => true,
                'project_suggested' => false, 'project_needed' => false]);
        }
        $p = self::projects()->get($n - 1);

        return $p ? array_merge($d, ['project_id' => $p->id, 'project_name' => null, 'project_choice_done' => true,
            'project_suggested' => false, 'project_needed' => false]) : null;
    }

    public static function menuShown(array $d): bool
    {
        return ! empty($d['project_suggested']) || ! empty($d['project_needed']);
    }

    private static function isCashSale(array $d): bool
    {
        return ($d['kind'] ?? null) === ExpenseExtractor::KIND_TRADE_SALE && ! empty($d['paid'])
            && empty($d['party_id']) && empty($d['party_name']);
    }

    /** @param  list<int>  $activeIds */
    private static function suggest(array $d, string $phone, array $activeIds): ?int
    {
        // Carinin son şubeli hareketi (alış / satış).
        if (! empty($d['party_id'])) {
            $id = PartyLedgerEntry::where('party_id', $d['party_id'])->whereNotNull('project_id')
                ->orderByDesc('entry_date')->orderByDesc('id')->value('project_id');
            if ($id && in_array((int) $id, $activeIds, true)) {
                return (int) $id;
            }
        }
        // Bu numaranın son 5 aynı tür kaydı hep aynı şubeyse o (Akkuş 0553 = hep Ergenekon kasası).
        $recent = WhatsappPendingExpense::where('phone', $phone)->where('status', 'confirmed')
            ->latest('id')->limit(30)->get()
            ->filter(fn ($p) => ($p->extracted['kind'] ?? null) === ($d['kind'] ?? null) && ! empty($p->extracted['project_id']))
            ->take(5)
            ->map(fn ($p) => (int) $p->extracted['project_id'])
            ->unique();

        return $recent->count() === 1 && in_array($recent->first(), $activeIds, true) ? $recent->first() : null;
    }
}
