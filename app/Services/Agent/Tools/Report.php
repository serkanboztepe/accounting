<?php

namespace App\Services\Agent\Tools;

use App\Models\Party;
use App\Models\Project;
use App\Support\Features;
use App\Support\MonthlyResult;
use App\Support\PartyBalances;
use App\Support\ProjectCost;
use Illuminate\Support\Carbon;

/**
 * Okuma aracı: hazır hesaplar (plan v5 §2-10) — dönem sonucu, yer/şube sonucu, proje maliyeti, toplam alacak / borç.
 * Hepsi panel ve raporlarla AYNI sınıflardan (MonthlyResult, ProjectCost, PartyBalances): rakamlar ekranla çelişmez.
 */
final class Report
{
    public const KINDS = ['period', 'place', 'project_cost', 'receivables', 'payables', 'open_checks', 'stock'];

    public static function tool(): array
    {
        return [
            'name' => 'report',
            'description' => 'Hazır hesaplar (yalnız okuma) — rakamlar panel raporlarıyla aynıdır, sen hesap yapma: '
                . 'period = dönem sonucu (ciro − alış − personel − gider; "bugün ne kazandık", "bu ay", "dünkü ciro"; project_id verilirse o şube/yer). '
                . 'place = bir yerin sezon/baştan bugüne sonucu (çiftlik, tarla, ahır). '
                . 'project_cost = bir projenin/şantiyenin maliyeti (gider + alış + alışı yazılmamış ödeme); project_id yoksa firma geneli ve proje proje döküm. '
                . 'receivables = bize borçlu olanlar + toplam alacak. payables = bizim borçlu olduklarımız + toplam borç (alacakla netleştirilmez). '
                . 'open_checks = bekleyen çekler (verdiğimiz ve aldığımız; id, kişi, vade, tutar) — "çek ödendi" işaretlemeden önce buradan bul. '
                . 'stock = stokta ne kadar var (product_id verilirse o ürün, yoksa hepsi). '
                . 'Tek kişinin hesabı için party_overview, liste/ayrıntı için records kullan.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'kind' => ['type' => 'string', 'enum' => self::KINDS],
                    'date_from' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD (period için; tek gün ise ikisi aynı)'],
                    'date_to' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD'],
                    'project_id' => ['type' => ['integer', 'null'], 'description' => 'PROJELER/ŞUBELER/YERLER listesinden'],
                    'product_id' => ['type' => ['integer', 'null'], 'description' => 'stock için: ÜRÜNLER listesinden; yoksa tüm ürünler'],
                ],
                'required' => ['kind'],
            ],
        ];
    }

    public static function run(array $in): array
    {
        $kind = (string) ($in['kind'] ?? '');
        $project = ! empty($in['project_id']) ? Project::find((int) $in['project_id']) : null;
        if (! empty($in['project_id']) && ! $project) {
            return ['hata' => 'Bu id ile proje/yer yok.'];
        }

        return match ($kind) {
            'period' => self::period($in, $project),
            'place' => $project ? self::place($project) : ['hata' => 'place için project_id gerekli.'],
            'project_cost' => self::projectCost($project),
            'receivables' => self::balances(true),
            'payables' => self::balances(false),
            'open_checks' => self::openChecks(),
            'stock' => ['stok' => \App\Support\Features::stock()
                ? \App\Services\Whatsapp\TradeDraft::stockAnswer(! empty($in['product_id']) ? (int) $in['product_id'] : null) : 'Bu işletmede stok takibi açık değil.'],
            default => ['hata' => 'kind şunlardan biri olmalı: ' . implode(', ', self::KINDS)],
        };
    }

    private static function period(array $in, ?Project $project): array
    {
        $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? Carbon::parse($v) : null;
        $from = $date($in['date_from'] ?? null);
        $to = $date($in['date_to'] ?? null) ?? $from;
        if (! $from) {
            return ['hata' => 'period için date_from (ve date_to) ver — YYYY-MM-DD.'];
        }
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        $r = MonthlyResult::forRange($from, $to, $project?->id);
        $oneDay = $from->isSameDay($to);

        return array_filter([
            'başlık' => $r['label'],
            'ciro' => round((float) $r['revenue'], 2),
            'ciro_nakit' => round((float) ($r['cash'] ?? 0), 2) ?: null,
            'ciro_banka/kart' => round((float) ($r['bank'] ?? 0), 2) ?: null,
            'satış_iadesi' => round((float) ($r['returns'] ?? 0), 2) ?: null,
            'alışlar' => round((float) $r['purchases'], 2),
            'personel (işlenen maaş/yevmiye)' => round((float) $r['personnel'], 2),
            'giderler' => Features::expenses() ? round((float) $r['expenses'], 2) : null,
            'sonuç (yaklaşık)' => round((float) $r['result'], 2),
            'genel_maliyet_payı (şubeye düşen, tahmini)' => isset($r['allocated']) ? round((float) $r['allocated'], 2) : null,
            'yere_yazılmamış_genel_gider (sonuca dahil değil)' => isset($r['general_unallocated']) ? round((float) $r['general_unallocated'], 2) : null,
            'elden_ödenen_maaş' => round((float) ($r['personnel_paid'] ?? 0), 2) ?: null,
            'fiyatsız_mal_girişi' => ! empty($r['unpriced_goods']) ? $r['unpriced_goods'] : null,
            'not' => $oneDay
                ? 'Tek gün: aylık maaş günün sonucundan düşülmez (bilgi satırıdır). Ödeme ve tahsilat sonuç değildir (para hareketi).'
                : 'Yaklaşık: stokta duran mal düşülmez. Ödeme ve tahsilat sonuca girmez (para hareketi).',
        ], fn ($v) => $v !== null);
    }

    private static function place(Project $place): array
    {
        $r = MonthlyResult::forPlace($place);

        return array_filter([
            'yer' => $r['place'],
            'dönem' => $r['season_label'],
            'kapandı' => $r['closed'] ?: null,
            'satış' => round((float) $r['revenue'], 2),
            'alışlar' => round((float) $r['purchases'], 2),
            'personel' => round((float) $r['personnel'], 2),
            'giderler' => round((float) $r['expenses'], 2),
            'sonuç' => round((float) $r['result'], 2),
            'yere_yazılmamış_genel_gider (dağıtılmadı)' => round((float) $r['general_unallocated'], 2) ?: null,
            'üretim' => $r['production'] ?: null,
        ], fn ($v) => $v !== null);
    }

    private static function projectCost(?Project $project): array
    {
        $one = function (?int $id): array {
            $unbooked = ProjectCost::unbookedPaymentRows($id);
            $names = Party::whereIn('id', $unbooked->pluck('party_id')->unique())->pluck('name', 'id');

            return array_filter([
                'gider' => round(ProjectCost::expenses($id), 2),
                'alış (iade düşülmüş)' => round(ProjectCost::purchases($id), 2),
                'alışı_yazılmamış_ödeme' => round((float) $unbooked->sum('amount'), 2),
                'alışı_yazılmamış_ödeme_kime' => $unbooked->map(fn ($r) => ($names[$r->party_id] ?? '?') . ': ' . $r->amount)->values()->all() ?: null,
                'toplam_maliyet' => round(ProjectCost::total($id), 2),
            ], fn ($v) => $v !== null);
        };
        if ($project) {
            return ['proje' => $project->name] + $one($project->id)
                + ['not' => 'Projesiz ödeme/avans maliyet değildir. "Alışı yazılmamış ödeme": para bu projeye verildi ama iş/malzeme alış olarak yazılmadı.'];
        }
        $projects = Project::orderBy('name')->get(['id', 'name', 'status']);

        return [
            'firma_geneli' => $one(null),
            'projeler' => $projects->map(fn (Project $p) => ['id' => $p->id, 'proje' => $p->name, 'durum' => $p->status,
                'toplam_maliyet' => round(ProjectCost::total($p->id), 2)])->all(),
            'not' => 'Firma geneli projesiz kayıtları da içerir; projelerin toplamından büyük olabilir.',
        ];
    }

    private static function openChecks(): array
    {
        $checks = \App\Models\Check::with('party')->whereIn('status', ['issued', 'portfolio'])->orderBy('due_date')->limit(60)->get();

        return ['bekleyen_çek' => $checks->count(), 'liste' => $checks->map(fn ($c) => array_filter([
            'id' => $c->id, 'kişi' => $c->party?->name, 'yön' => $c->status === 'issued' ? 'biz verdik (ödenecek)' : 'biz aldık (tahsil edilecek)',
            'vade' => $c->due_date?->toDateString(), 'tutar' => round((float) $c->amount, 2), 'no' => $c->check_number,
        ], fn ($v) => $v !== null))->all(), 'not' => 'İşaretlemek için propose → type check_cleared, check_ids. Bakiye değişmez.'];
    }

    private static function balances(bool $receivable): array
    {
        $rows = $receivable ? PartyBalances::receivables() : PartyBalances::payables();

        return [
            'başlık' => $receivable ? 'Bize borçlu olanlar (alacağımız)' : 'Bizim borçlu olduklarımız (borcumuz)',
            'kişi_sayısı' => $rows->count(),
            'toplam' => round((float) $rows->sum('balance'), 2),
            'liste' => $rows->take(40)->map(fn ($r) => ['id' => $r['party']->id, 'ad' => $r['party']->name, 'tutar' => round((float) $r['balance'], 2)])->all(),
            'not' => $rows->count() > 40 ? 'İlk 40 kişi gösterildi; toplam hepsini kapsar.' : 'Alacak ve borç ayrı hesaplardır, netleştirilmez.',
        ];
    }
}
