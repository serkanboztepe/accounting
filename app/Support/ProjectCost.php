<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\PartyLedgerEntry;
use Illuminate\Support\Collection;

/**
 * Sade müteahhit maliyeti — Proje Raporu ve Ana sayfa AYNI hesabı kullanır (rakamlar çelişmesin):
 * maliyet = direkt gider + cariden alış (alış iadesi düşer) + alışı yazılmamış projeli ödeme.
 * Projesiz ödeme / avans maliyet DEĞİL (para çıkışı). $projectId null → firma geneli (projesiz kayıtlar dahil).
 */
final class ProjectCost
{
    public static function purchases(?int $projectId = null): float
    {
        $sum = fn (string $type) => (float) PartyLedgerEntry::query()
            ->when($projectId !== null, fn ($q) => $q->where('project_id', $projectId))
            ->where('type', $type)->sum('amount');

        return $sum(PartyLedgerEntry::TYPE_PURCHASE) - $sum(PartyLedgerEntry::TYPE_PURCHASE_RETURN);
    }

    public static function expenses(?int $projectId = null): float
    {
        return (float) Expense::query()->when($projectId !== null, fn ($q) => $q->where('project_id', $projectId))->sum('amount');
    }

    /**
     * Bir projeye yazılmış ödemenin, aynı cari + aynı projedeki alışı aşan kısmı (29.10 kullanıcı kararı):
     * para o projeye verildi ama iş/malzeme henüz alış olarak yazılmadı → maliyete girer, raporda ⚠ ile söylenir.
     * Alış yazıldıkça fark kapanır, çift sayılmaz. Sözleşmeli firmada ödeme contract_payments'ta → hesap yok.
     *
     * @return Collection<int, object{project_id:int, party_id:int, amount:float}>
     */
    public static function unbookedPaymentRows(?int $projectId = null): Collection
    {
        if (Features::contracts()) {
            return collect();
        }

        return PartyLedgerEntry::query()
            ->whereNotNull('project_id')->whereNotNull('party_id')
            ->when($projectId !== null, fn ($q) => $q->where('project_id', $projectId))
            ->whereIn('type', [PartyLedgerEntry::TYPE_PAYMENT, PartyLedgerEntry::TYPE_PURCHASE, PartyLedgerEntry::TYPE_PURCHASE_RETURN])
            ->selectRaw('project_id, party_id, SUM(CASE WHEN type = ? THEN amount WHEN type = ? THEN -amount WHEN type = ? THEN amount ELSE 0 END) AS excess',
                [PartyLedgerEntry::TYPE_PAYMENT, PartyLedgerEntry::TYPE_PURCHASE, PartyLedgerEntry::TYPE_PURCHASE_RETURN])
            ->groupBy('project_id', 'party_id')
            ->get()
            ->filter(fn ($r) => (float) $r->excess >= 0.01)
            ->map(fn ($r) => (object) ['project_id' => (int) $r->project_id, 'party_id' => (int) $r->party_id, 'amount' => round((float) $r->excess, 2)])
            ->values();
    }

    public static function unbookedPayments(?int $projectId = null): float
    {
        return (float) self::unbookedPaymentRows($projectId)->sum('amount');
    }

    /** Toplam maliyet = gider + alış + alışı yazılmamış projeli ödeme (sözleşme teslimatı hariç; onu çağıran ekler). */
    public static function total(?int $projectId = null): float
    {
        return self::expenses($projectId) + self::purchases($projectId) + self::unbookedPayments($projectId);
    }
}
