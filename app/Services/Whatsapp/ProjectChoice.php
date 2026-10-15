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
    public const KINDS = [ExpenseExtractor::KIND_EXPENSE, ExpenseExtractor::KIND_PURCHASE, ExpenseExtractor::KIND_SALE,
        ExpenseExtractor::KIND_TRADE_SALE, ExpenseExtractor::KIND_TRADE_PURCHASE];

    public static function applies(array $d): bool
    {
        return (bool) config('modules.projects') && in_array($d['kind'] ?? ExpenseExtractor::KIND_EXPENSE, self::KINDS, true);
    }

    /** @return Collection<int, Project> sabit sıra — menü numaraları bununla */
    public static function projects(): Collection
    {
        // Eski gider menüsüyle aynı sıra (müteahhit "2" yazma alışkanlığı bozulmasın): iptal hariç, eklenme sırası.
        return Project::whereNotIn('status', ['cancelled'])->orderBy('id')->take(30)->get(['id', 'name'])->values();
    }

    /** Taslağa şube bilgisini yerleştir (mesajdan / tek şube / öneri / soru). */
    public static function prepare(array $d, string $phone): array
    {
        unset($d['project_suggested'], $d['project_needed']);
        if (! self::applies($d) || ! empty($d['project_id']) || ! empty($d['project_name']) || ! empty($d['project_choice_done'])) {
            return $d;
        }
        $projects = self::projects();
        if ($projects->isEmpty()) {
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
        $menu = $projects->map(fn ($p, $i) => '   ' . ($i + 1) . ') ' . $p->name)->all();
        $general = '   0) Genel / ortak';

        if (! empty($d['project_suggested'])) {
            $name = $projects->firstWhere('id', $d['project_id'])?->name ?? '—';

            return array_merge(["🏪 {$label}: *{$name}* (son) — başkaysa numarayı yaz:"], $menu, [$general]);
        }
        if (! empty($d['project_needed'])) {
            return array_merge(['❓ Hangi ' . mb_strtolower($label) . '? Numarayı yaz:'], $menu,
                self::isCashSale($d) ? [] : [$general . ' (ya da direkt *evet*)']);
        }
        if (! empty($d['project_id']) && ($p = Project::find($d['project_id']))) {
            return ["🏪 {$label}: {$p->name}"];
        }
        if (! empty($d['project_name'])) {
            return ["🏪 {$label}: {$d['project_name']} (yeni)"];
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
