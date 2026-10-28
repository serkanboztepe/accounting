<?php

namespace App\Support;

use App\Models\Purchase;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satışlar / Alışlar tezgahının kayıt mantığı — panel ve WhatsApp aynı yoldan kaydeder.
 * Peşin satış carisiz (cariye yazılmaz); cariye satış = satış belgesi + cari borç (Sale::rebuildFromLines).
 * Alış: cariden → cari alış; peşin → alış + ödeme (Purchase::rebuildFromLines).
 */
class TradeDesk
{
    /**
     * @param  array{mode?:string, party_id?:int|null, sale_date?:string, lines?:array, manual_amount?:mixed,
     *               payment?:string, cash_part?:mixed, notes?:string|null}  $data
     */
    public static function saveSale(array $data): Sale
    {
        $isCash = ($data['mode'] ?? 'cash') === 'cash';
        $partyId = $isCash ? null : ($data['party_id'] ?? null);
        $manual = Money::parse($data['manual_amount'] ?? null);

        if (! $isCash && ! $partyId) {
            throw ValidationException::withMessages(['party_id' => 'Veresiye satışta kişi / firma seçilmeli.']);
        }
        if (! Forms\TradeLines::hasLines($data['lines'] ?? []) && $manual <= 0) {
            throw ValidationException::withMessages(['manual_amount' => 'Ürün ekle ya da ürünsüz tutar yaz.']);
        }

        return DB::transaction(function () use ($data, $partyId, $manual, $isCash) {
            $sale = Sale::create([
                'party_id'      => $partyId,
                'project_id'    => $data['project_id'] ?? null,
                'sale_date'     => $data['sale_date'] ?? now()->toDateString(),
                'manual_amount' => $manual > 0 ? $manual : null,
                'notes'         => $data['notes'] ?? null,
            ]);

            $sale->rebuildFromLines($data['lines'] ?? []);

            if ($isCash) {
                $sale->payWith($data['payment'] ?? 'cash', (float) Money::parse($data['cash_part'] ?? null));
            }

            return $sale->fresh();
        });
    }

    /**
     * @param  array{mode?:string, party_id?:int|null, purchase_date?:string, lines?:array, manual_amount?:mixed,
     *               payment_type?:string|null, notes?:string|null, project_id?:int|null, keep_project?:bool}  $data
     */
    public static function savePurchase(array $data): Purchase
    {
        $isCash = ($data['mode'] ?? 'party') === 'cash';
        $partyId = $data['party_id'] ?? null;
        $manual = Money::parse($data['manual_amount'] ?? null);

        if (! $isCash && ! $partyId) {
            throw ValidationException::withMessages(['party_id' => 'Veresiye alışta kişi / firma seçilmeli.']);
        }
        if (! Forms\TradeLines::hasLines($data['lines'] ?? []) && $manual <= 0) {
            throw ValidationException::withMessages(['manual_amount' => 'Ürün ekle ya da ürünsüz tutar yaz.']);
        }

        return DB::transaction(function () use ($data, $partyId, $manual, $isCash) {
            $purchase = Purchase::create([
                'party_id'      => $partyId,
                // Ürünlü (stoğa giren) alış şubesiz: stok işletme geneli, maliyet şubeye bölünmez (Serkan 16.10).
                // İstisnalar: anlaşma teslimatı (keep_project) ve "Alışı yere yaz" açık firma (çiftlik 26.10: yem → ahır).
                'project_id'    => Forms\TradeLines::hasLines($data['lines'] ?? []) && empty($data['keep_project']) && ! Features::placeCost()
                    ? null : ($data['project_id'] ?? null),
                'purchase_date' => $data['purchase_date'] ?? now()->toDateString(),
                'manual_amount' => $manual > 0 ? $manual : null,
                'paid'          => $isCash,
                'payment_type'  => $isCash ? ($data['payment_type'] ?? 'cash') : null,
                'notes'         => $data['notes'] ?? null,
            ]);

            $purchase->rebuildFromLines($data['lines'] ?? []);

            return $purchase->fresh();
        });
    }

    /** Bugün / bu ay satış özeti: ciro, nakit, kart, veresiye (iadeler düşülmez; ayrı rapor). */
    public static function salesSummary(string $from, string $to): array
    {
        $row = Sale::query()->whereBetween('sale_date', [$from, $to])
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS total,
                COALESCE(SUM(CASE WHEN party_id IS NULL THEN cash_amount ELSE 0 END),0) AS cash,
                COALESCE(SUM(CASE WHEN party_id IS NULL THEN bank_amount ELSE 0 END),0) AS bank,
                COALESCE(SUM(CASE WHEN party_id IS NOT NULL THEN total_amount ELSE 0 END),0) AS credit')
            ->first();

        return [
            'count'  => (int) $row->n,
            'total'  => (float) $row->total,
            'cash'   => (float) $row->cash,
            'bank'   => (float) $row->bank,
            'credit' => (float) $row->credit,
        ];
    }

    /** Bugün / bu ay alış özeti: toplam, peşin, cariden. */
    public static function purchaseSummary(string $from, string $to): array
    {
        $row = Purchase::query()->whereBetween('purchase_date', [$from, $to])
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS total,
                COALESCE(SUM(CASE WHEN paid THEN total_amount ELSE 0 END),0) AS paid,
                COALESCE(SUM(CASE WHEN paid THEN 0 ELSE total_amount END),0) AS credit')
            ->first();

        return [
            'count'  => (int) $row->n,
            'total'  => (float) $row->total,
            'paid'   => (float) $row->paid,
            'credit' => (float) $row->credit,
        ];
    }
}
