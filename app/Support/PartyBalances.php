<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use Illuminate\Support\Collection;

/**
 * Tüm carilerin ekstre bakiyesi (PartyStatement) — dashboard "Açık Cari Hesapları" ve WhatsApp
 * "toplam alacağım/borcum" aynı kaynaktan okusun. Bakiye > 0: cari bize borçlu (alacağımız),
 * < 0: biz cariye borçluyuz. Alacak ve borç ASLA netleştirilmez (farklı kişilerle farklı hesaplar).
 */
class PartyBalances
{
    /**
     * Hareketi olan carilerin sıfır olmayan bakiyeleri.
     *
     * @param  array{project_id?:int}  $filters
     * @return Collection<int, array{party: Party, balance: float}>
     */
    public static function all(array $filters = []): Collection
    {
        $partyIds = collect()
            ->merge(Expense::query()->whereNotNull('party_id')->distinct()->pluck('party_id'))
            ->merge(PartyLedgerEntry::query()->whereNotNull('party_id')->distinct()->pluck('party_id'))
            ->merge(Contract::withoutGlobalScope('purchase')->whereNotNull('party_id')->distinct()->pluck('party_id'))
            ->unique()
            ->values();

        return Party::whereIn('id', $partyIds)->orderBy('name')->get()
            ->map(fn (Party $party) => [
                'party' => $party,
                'balance' => PartyStatement::build($party, $filters)['balance'],
            ])
            ->filter(fn (array $row) => abs($row['balance']) >= 0.01)
            ->values();
    }

    /** Bize borçlu cariler (en büyük üstte). */
    public static function receivables(array $filters = []): Collection
    {
        return self::all($filters)->filter(fn ($r) => $r['balance'] > 0)->sortByDesc('balance')->values();
    }

    /** Bizim borçlu olduğumuz cariler (en büyük üstte), balance pozitif tutar olarak. */
    public static function payables(array $filters = []): Collection
    {
        return self::all($filters)->filter(fn ($r) => $r['balance'] < 0)
            ->map(fn ($r) => ['party' => $r['party'], 'balance' => abs($r['balance'])])
            ->sortByDesc('balance')->values();
    }
}
