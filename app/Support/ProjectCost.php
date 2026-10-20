<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\PartyLedgerEntry;

/**
 * Sade müteahhit maliyeti — Proje Raporu ve Ana sayfa AYNI hesabı kullanır (rakamlar çelişmesin):
 * maliyet = direkt gider + cariden alış (alış iadesi düşer). Ödeme / avans maliyet DEĞİL (para çıkışı).
 * $projectId null → firma geneli (projesiz kayıtlar dahil).
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
}
