<?php

namespace App\Services\Whatsapp;

use App\Models\PartyLedgerEntry;
use App\Models\Purchase;
use App\Support\Money;

/**
 * Müşteri adına üçüncü kişiye ödeme (kullanıcı 22.10, Yıldız: "Abdullah uçara 26500 tl Necati Çimen ruhsat için ödendi" →
 * AI "Abdullah Uçar'dan alış + ödendi" yazdı, müşteri alışı panelden sildi). Kural: bir cari İÇİN / ADINA başkasına ödenen
 * para o carinin hesabına ÖDEME olarak yazılır — ondan alacağımız olmasa da, yeni cari olsa da (`payment_purpose` on_behalf).
 *
 * AI yine de bize hiç mal/hizmet satmamış bir MÜŞTERİDEN (yalnız tahsilat/satış hareketi var) alış yazarsa kod sessiz geçmez:
 *
 *   Abdullah Uçar senin müşterin. Bu 26.500 ₺:
 *   *1* Onun adına ödedim — hesabına yazılsın
 *   *2* Ondan hizmet/mal aldım
 */
final class OnBehalfChoice
{
    public static function shown(array $d): bool
    {
        return ! empty($d['on_behalf_choice']);
    }

    public static function applies(array $d): bool
    {
        if (($d['kind'] ?? null) !== ExpenseExtractor::KIND_PURCHASE || ! empty($d['on_behalf_done']) || (float) ($d['amount'] ?? 0) <= 0) {
            return false;
        }
        $party = DraftGaps::party($d);
        if ($party === null) {
            return false;
        }
        $types = TurnTrace::read('OnBehalfChoice::applies.types', fn () => PartyLedgerEntry::where('party_id', $party->id)->distinct()->pluck('type')->all(),
            ['party_id' => $party->id]);
        $customer = array_intersect($types, [PartyLedgerEntry::TYPE_COLLECTION, PartyLedgerEntry::TYPE_SALE]) !== [];
        $supplier = array_intersect($types, [PartyLedgerEntry::TYPE_PURCHASE, PartyLedgerEntry::TYPE_PURCHASE_RETURN]) !== []
            || (config('modules.purchases') && TurnTrace::read('OnBehalfChoice::applies.purchases', fn () => Purchase::where('party_id', $party->id)->exists(),
                ['party_id' => $party->id]));

        return $customer && ! $supplier;
    }

    public static function question(array $d): string
    {
        $name = DraftGaps::party($d)?->name ?? ($d['party_name'] ?? 'Bu kişi');

        return $name . ' senin müşterin. Bu ' . Money::format((float) ($d['amount'] ?? 0)) . " ₺:\n"
            . "*1* Onun adına ödedim — hesabına yazılsın\n*2* Ondan hizmet/mal aldım";
    }

    /** 1 → müşteri adına ödeme (hesabına) · 2 → alış olduğu gibi · başka → null. */
    public static function choose(array $d, int $n): ?array
    {
        $base = ['on_behalf_choice' => null, 'on_behalf_done' => true, 'question' => null, 'is_new_entry' => false];

        return match ($n) {
            1 => array_merge($d, $base, self::onBehalf()),
            2 => array_merge($d, $base, ['work_chosen' => true]),
            default => null,
        };
    }

    /** Adına ödeme: düz ödeme, iş durumu / "ne için verdin?" sorusu yok, maliyete girmez. */
    public static function onBehalf(): array
    {
        return ['kind' => ExpenseExtractor::KIND_PAYMENT, 'payment_purpose' => 'on_behalf', 'paid' => null, 'advance' => false,
            'debt_repayment' => false, 'work_status' => null, 'work_status_known' => null, 'work_choice' => null, 'work_chosen' => true,
            'category_id' => null, 'category_name' => null, 'goods_name' => null];
    }

    public static function isOnBehalf(array $d): bool
    {
        return ($d['kind'] ?? null) === ExpenseExtractor::KIND_PAYMENT && ($d['payment_purpose'] ?? null) === 'on_behalf';
    }
}
