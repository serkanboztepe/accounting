<?php

namespace App\Support;

use App\Models\Agreement;
use App\Models\Check;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\StockMovement;
use App\Services\Whatsapp\WorkChoice;
use Illuminate\Support\Collection;

/**
 * Anlaşma bakiyesi (kullanıcı 26.10: "bu anlaşmaya ne ödedim, ne kaldı?"). Ödeme / tahsilat satırı `agreement_id` ile
 * anlaşmaya bağlanır; bağsız olan "Diğer alışlar / satışlar"dır.
 *
 *   Anlaşma bakiyesi = anlaşmadan gelen (teslimat tutarı, iade düşülür) − o anlaşmaya bağlı ödemeler
 *   Diğer            = carinin bakiyesi − anlaşma bakiyeleri   → parçaların toplamı HER ZAMAN cari bakiyesi (çift sayım yok)
 *
 * Bakiye işareti yöne göre: alış anlaşmasında + = biz borçluyuz, satışta + = cari bize borçlu.
 * Arşiv: bitmiş / kapatılmış ve bakiyesi sıfır anlaşma — listede ve "Hangisi için?" sorusunda görünmez; bakiye oluşursa geri gelir.
 * Panel ("Ödeme Yaptım / Aldım", Çekler) ve WhatsApp aynı seçenekleri ve aynı cümleyi buradan alır.
 */
final class AgreementBalances
{
    /** Ödeme / tahsilat tipi → hangi yöndeki anlaşmalara gider. */
    public static function directionFor(string $ledgerType): ?string
    {
        return match ($ledgerType) {
            PartyLedgerEntry::TYPE_PAYMENT    => Agreement::DIRECTION_PURCHASE,
            PartyLedgerEntry::TYPE_COLLECTION => Agreement::DIRECTION_SALE,
            default => null,
        };
    }

    /** Bu anlaşmaya bağlı ödemeler (alışta ödeme +, iade parası −; satışta tahsilat +). */
    public static function paid(Agreement $agreement): float
    {
        $entries = $agreement->relationLoaded('ledgerEntries') ? $agreement->ledgerEntries : $agreement->ledgerEntries()->get();
        $ours = $agreement->isPurchase() ? PartyLedgerEntry::DIRECTION_DEBIT : PartyLedgerEntry::DIRECTION_CREDIT;

        return round($entries->sum(fn (PartyLedgerEntry $e) => ($e->direction === $ours ? 1 : -1) * (float) $e->amount), 2);
    }

    /** Gelen − ödenen (alışta + = borcun, satışta + = alacağın). */
    public static function balance(Agreement $agreement): float
    {
        return round($agreement->deliveredTotal() - self::paid($agreement), 2);
    }

    /** Bekleyen çekler (verilen henüz ödenmemiş / alınan henüz tahsil edilmemiş) — özet için. */
    public static function pendingChecks(Agreement $agreement): Collection
    {
        return Check::whereIn('party_ledger_entry_id', $agreement->ledgerEntries()->pluck('id'))
            ->whereIn('status', ['issued', 'portfolio'])->orderBy('due_date')->get();
    }

    public static function archived(Agreement $agreement, ?float $balance = null): bool
    {
        return ! $agreement->isOpen() && abs($balance ?? self::balance($agreement)) < 0.01;
    }

    /**
     * Carinin anlaşmaları + bakiyeleri + "Diğer".
     *
     * @return array{rows: list<array{agreement: Agreement, balance: float, paid: float, archived: bool}>, other: array<string, float>}
     *   other[yön] = o yönden bakınca anlaşma dışı bakiye (alış: + biz borçluyuz · satış: + cari borçlu)
     */
    public static function forParty(Party $party, ?float $partyBalance = null): array
    {
        $agreements = Agreement::where('party_id', $party->id)->with('ledgerEntries')
            ->orderByDesc('agreement_date')->orderByDesc('id')->get()->each(fn (Agreement $a) => $a->loadForCard());
        $rows = [];
        $net = 0.0; // anlaşmaların cari bakiyesine katkısı (borç − alacak)
        foreach ($agreements as $a) {
            $balance = self::balance($a);
            $net += $a->isPurchase() ? -$balance : $balance;
            $rows[] = ['agreement' => $a, 'balance' => $balance, 'paid' => self::paid($a), 'archived' => self::archived($a, $balance)];
        }
        $other = round(($partyBalance ?? PartyStatement::build($party)['balance']) - $net, 2);

        return ['rows' => $rows, 'other' => [Agreement::DIRECTION_SALE => $other, Agreement::DIRECTION_PURCHASE => -$other]];
    }

    /**
     * "Hangisi için?" seçenekleri: yöndeki arşivlenmemiş anlaşmalar, en sonda Diğer. Anlaşma yoksa boş (soru sorulmaz).
     * $editing: kayıtlı bir ödemeyi bağlarken / düzenlerken onun tutarı bulunduğu yerden geri eklenir ("öncesi" iki kez düşülmesin).
     *
     * @return list<array{id: int|null, label: string, balance: float}>
     */
    public static function choices(Party $party, string $ledgerType, ?float $partyBalance = null, ?PartyLedgerEntry $editing = null): array
    {
        $direction = self::directionFor($ledgerType);
        if ($direction === null || ! Features::agreements()) {
            return [];
        }
        $data = self::forParty($party, $partyBalance);
        $back = $editing && self::directionFor((string) $editing->type) === $direction ? (float) $editing->amount : 0.0;
        $options = [];
        foreach ($data['rows'] as $row) {
            $a = $row['agreement'];
            $mine = $back > 0 && (int) $editing->agreement_id === $a->id;
            // Düzenlenen ödemenin bağlı olduğu anlaşma arşivde olsa da listede kalır.
            if ($a->direction === $direction && (! $row['archived'] || $mine)) {
                $options[] = ['id' => $a->id, 'label' => self::label($a), 'balance' => round($row['balance'] + ($mine ? $back : 0.0), 2)];
            }
        }
        if ($options === []) {
            return [];
        }
        $options[] = ['id' => null, 'label' => self::otherLabel($direction),
            'balance' => round($data['other'][$direction] + ($editing && ! $editing->agreement_id ? $back : 0.0), 2)];

        return $options;
    }

    /** "Demir (A Blok)" */
    public static function label(Agreement $agreement): string
    {
        $project = $agreement->project?->name;

        return $agreement->title . ($project ? ' (' . $project . ')' : '');
    }

    public static function otherLabel(string $direction): string
    {
        return $direction === Agreement::DIRECTION_SALE ? 'Diğer satışlar' : 'Diğer alışlar';
    }

    /**
     * Seçeneğin yanındaki sonuç cümlesi (kullanıcı 26.10 onayladı — tek satır, "fazla ödemiş" denmez):
     *   alış:  "80.000 borcun vardı, 55.000'e düşecek" · "25.000 borcun vardı, borcun kalmayacak ✅" ·
     *          "10.000 borcun vardı, 15.000 alacaklı olacaksın" · "borcun yok, 25.000 alacaklı olacaksın"
     *   satış: aynı cümleler alacak / borçlu sözcükleriyle.
     * Tutar henüz yazılmadıysa (panel) yalnız bugünkü durum.
     */
    public static function sentence(float $before, float $amount, string $direction): string
    {
        $sale = $direction === Agreement::DIRECTION_SALE;
        [$owe, $owed, $ourSide, $theirSide] = $sale
            ? ['alacağın', 'alacağın', 'borçlu', 'borçluydun']
            : ['borcun', 'borcun', 'alacaklı', 'alacaklıydın'];
        $before = round($before, 2);
        if ($amount <= 0.009) {
            return match (true) {
                $before > 0.009 => self::money($before) . ' ' . $owe . ' var',
                $before < -0.009 => self::money(-$before) . ' ' . $ourSide . ($sale ? 'sun' : 'sın'),
                default => $owe . ' yok',
            };
        }
        $after = round($before - $amount, 2);
        if ($before > 0.009) {
            return self::money($before) . ' ' . $owe . ' vardı, ' . match (true) {
                $after > 0.009 => self::to($after) . ' düşecek',
                $after > -0.01 => $owed . ' kalmayacak ✅',
                default => self::money(-$after) . ' ' . $ourSide . ' olacaksın',
            };
        }
        if ($before < -0.009) {
            return self::money(-$before) . ' ' . $theirSide . ', ' . self::money(-$after) . ' ' . $ourSide . ' olacaksın';
        }

        return $owe . ' yok, ' . self::money($amount) . ' ' . $ourSide . ' olacaksın';
    }

    /** Ödeme/tahsilat bir belgeyle birlikte (peşin) yazıldıysa: belgenin tek anlaşması varsa o (birden çoksa null). */
    public static function agreementOfDocument(?string $column, ?int $documentId): ?int
    {
        if (! $column || ! $documentId) {
            return null;
        }
        $ids = StockMovement::where($column, $documentId)->whereNotNull('agreement_item_id')
            ->join('agreement_items', 'agreement_items.id', '=', 'stock_movements.agreement_item_id')
            ->distinct()->pluck('agreement_items.agreement_id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    /** Tam sayı kuruşsuz: 55000 → "55.000", 1250.5 → "1.250,50". */
    public static function money(float $amount): string
    {
        $amount = round(abs($amount), 2);

        return Money::format($amount, abs($amount - round($amount)) < 0.005 ? 0 : 2);
    }

    /** Yönelme ekiyle: 55.000 → "55.000'e", 60 → "60'a", 6 → "6'ya" (son okunan sözcüğe göre). */
    public static function to(float $amount): string
    {
        $amount = round(abs($amount), 2);
        $kurus = (int) round(($amount - floor($amount)) * 100);
        $words = explode(' ', Money::words($kurus > 0 ? $kurus : $amount));
        $last = end($words) ?: 'sıfır';

        return self::money($amount) . mb_substr(WorkChoice::dative($last), mb_strlen($last));
    }
}
