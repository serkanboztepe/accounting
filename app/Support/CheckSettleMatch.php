<?php

namespace App\Support;

use App\Models\Check;
use Illuminate\Support\Collection;

/**
 * Vadesi gelen çek "tahsil ettim / yattı / ödendi" (kullanıcı 26.10): çekin parası çek alındığı / verildiği gün deftere
 * işlendi — vadede yalnız çek işaretlenir, yeni tahsilat / ödeme YAZILMAZ (yoksa aynı para iki kez sayılır). Kullanıcı
 * bunun deftere işlenip işlenmediğini bilemez; bilen biziz → eşleşen çek varsa kart yalnız "işaretlensin mi?" der.
 */
final class CheckSettleMatch
{
    public const RECEIVED = 'received';

    public const GIVEN = 'given';

    /**
     * Bekleyen çekler: alınan (portfolio) / verilen (issued), vadesi bugün −45 … +10 gün. Cari ve tutar (±1 ₺) verilmişse süzülür.
     * Vadesi bugüne en yakın önce.
     *
     * @return Collection<int, Check>
     */
    public static function candidates(string $direction, ?int $partyId = null, ?float $amount = null): Collection
    {
        $today = CheckReminders::today();

        return Check::query()->with('party')
            ->where('status', $direction === self::RECEIVED ? 'portfolio' : 'issued')
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$today->copy()->subDays(45)->toDateString(), $today->copy()->addDays(10)->toDateString()])
            ->when($partyId, fn ($q) => $q->where('party_id', $partyId))
            ->get()
            ->filter(fn (Check $c) => ! $amount || abs((float) $c->amount - $amount) < 1)
            ->sortBy(fn (Check $c) => abs($c->due_date->diffInDays($today, false)))
            ->values();
    }

    /** "Ali Yılmaz — 20.000,00 ₺ çek (vade 26.10 · no 123)" */
    public static function line(Check $check): string
    {
        return ($check->party?->name ?? 'Kişi yok') . ' — ' . Money::format((float) $check->amount) . ' ₺ çek (vade '
            . $check->due_date?->format('d.m') . ($check->check_number ? ' · no ' . $check->check_number : '') . ')';
    }

    /** Ekranda / mesajda iş adı: alınan → "tahsil edildi", verilen → "ödendi". */
    public static function doneWord(string $direction): string
    {
        return $direction === self::RECEIVED ? 'tahsil edildi' : 'ödendi';
    }
}
