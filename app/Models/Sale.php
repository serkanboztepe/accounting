<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'party_id',
        'project_id',
        'sale_date',
        'total_amount',
        'notes',
    ];

    protected $casts = [
        'sale_date'    => 'date',
        'total_amount' => 'decimal:2',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Satış kalemleri = stok çıkışları (out/sale). */
    public function lines(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'sale_id')
            ->where('reason', StockMovement::REASON_SALE);
    }

    /** İade hareketleri = stok girişleri (in/return). */
    public function returns(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'sale_id')
            ->where('reason', StockMovement::REASON_RETURN);
    }

    /** İade başlıkları (her "İade Al" işlemi bir parti). */
    public function saleReturns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    /** Satışın cari BORÇ kaydı (iade alacak kayıtları hariç). */
    public function ledgerEntry(): HasOne
    {
        return $this->hasOne(PartyLedgerEntry::class, 'sale_id')
            ->where('type', PartyLedgerEntry::TYPE_SALE);
    }

    /** Bir ürün için: satılan − iade edilen = iade edilebilecek kalan. */
    public function returnableQtyForProduct(int $productId): float
    {
        $sold = (float) $this->lines()->where('product_id', $productId)->sum('quantity');
        $returned = (float) $this->returns()->where('product_id', $productId)->sum('quantity');

        return max(0.0, $sold - $returned);
    }

    /** İade alınabilecek (henüz iade edilmemiş) kalem var mı? */
    public function hasReturnableItems(): bool
    {
        return (float) $this->lines()->sum('quantity') > (float) $this->returns()->sum('quantity');
    }

    /**
     * İade Al formu için ürün-bazlı satırlar: satılan, önce iade, kalan.
     * Yalnız kalan > 0 olan ürünler döner.
     */
    public function returnFormLines(): array
    {
        return $this->lines()
            ->with('product')
            ->get()
            ->groupBy('product_id')
            ->map(function ($group) {
                $first = $group->first();
                $productId = (int) $first->product_id;
                $sold = (float) $group->sum('quantity');
                $returned = (float) $this->returns()->where('product_id', $productId)->sum('quantity');
                $remaining = max(0.0, $sold - $returned);

                return [
                    'product_id'      => $productId,
                    'product_label'   => $first->product?->name ?? ('#' . $productId),
                    'sold'            => $sold,
                    'returned_before' => $returned,
                    'remaining'       => $remaining,
                    'unit_price'      => $first->unit_price !== null ? Money::format((float) $first->unit_price) : null,
                    'return_qty'      => 0,
                ];
            })
            ->filter(fn ($row) => $row['remaining'] > 0)
            ->values()
            ->toArray();
    }

    /**
     * Kalemleri stok çıkışına + cari borca dönüştürür (tek doğruluk noktası).
     * Düzenlemede eski kalemler silinip yeniden yazılır; stok/cari otomatik güncel.
     * $lines: [['product_id'=>, 'quantity'=>, 'unit_price'=> (Money::store'lu), 'amount'=>], ...]
     */
    public function rebuildFromLines(array $lines): void
    {
        // Eski kalemleri temizle (stoğu geri verir; net etkiyi baştan kurarız).
        $this->lines()->delete();

        $total = 0.0;

        foreach ($lines as $line) {
            $productId = $line['product_id'] ?? null;
            $quantity  = (float) ($line['quantity'] ?? 0);
            if (! $productId || $quantity <= 0) {
                continue;
            }

            // Money::parse "185,50" / "185.50" / float — hepsini çözer.
            $unitPrice = blank($line['unit_price'] ?? null) ? null : Money::parse($line['unit_price']);
            $lineAmount = blank($line['amount'] ?? null) ? null : Money::parse($line['amount']);
            $amount = $lineAmount
                ?? ($unitPrice !== null ? round($quantity * $unitPrice, 2) : null);

            StockMovement::create([
                'product_id'    => $productId,
                'sale_id'       => $this->id,
                'direction'     => StockMovement::DIRECTION_OUT,
                'reason'        => StockMovement::REASON_SALE,
                'quantity'      => $quantity,
                'unit_price'    => $unitPrice,
                'amount'        => $amount,
                'party_id'      => $this->party_id,
                'project_id'    => $this->project_id,
                'movement_date' => $this->sale_date,
            ]);

            $total += (float) ($amount ?? 0);
        }

        $this->forceFill(['total_amount' => $total])->saveQuietly();

        $this->syncLedger($total);
    }

    /**
     * İade işle: her ürün için giren/return stok hareketi + satış iadesi (alacak) cari kaydı.
     * $lines: [['product_id'=>, 'return_qty'=>, 'unit_price'=> (Money'li)], ...]
     * "Sevkte satış" modeli: stok geri +, cari borç − (alacak).
     */
    public function processReturn(array $lines, string $date, ?string $notes = null): ?SaleReturn
    {
        // Önce geçerli kalemleri süz + tutarları hesapla.
        $prepared = [];
        $totalReturn = 0.0;

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $qty = (float) ($line['return_qty'] ?? 0);
            if (! $productId || $qty <= 0) {
                continue;
            }

            $unitPrice = blank($line['unit_price'] ?? null) ? null : Money::parse($line['unit_price']);
            $amount = $unitPrice !== null ? round($qty * $unitPrice, 2) : null;

            $prepared[] = compact('productId', 'qty', 'unitPrice', 'amount');
            $totalReturn += (float) ($amount ?? 0);
        }

        if (empty($prepared)) {
            return null;
        }

        // İade başlığı (parti) — geri almak için tek nokta.
        $return = $this->saleReturns()->create([
            'party_id'     => $this->party_id,
            'project_id'   => $this->project_id,
            'return_date'  => $date,
            'total_amount' => $totalReturn,
            'notes'        => $notes,
        ]);

        foreach ($prepared as $p) {
            StockMovement::create([
                'product_id'     => $p['productId'],
                'sale_id'        => $this->id,
                'sale_return_id' => $return->id,
                'direction'      => StockMovement::DIRECTION_IN,
                'reason'         => StockMovement::REASON_RETURN,
                'quantity'       => $p['qty'],
                'unit_price'     => $p['unitPrice'],
                'amount'         => $p['amount'],
                'party_id'       => $this->party_id,
                'project_id'     => $this->project_id,
                'movement_date'  => $date,
                'notes'          => $notes,
            ]);
        }

        if ($totalReturn > 0) {
            PartyLedgerEntry::create([
                'party_id'       => $this->party_id,
                'project_id'     => $this->project_id,
                'sale_id'        => $this->id,
                'sale_return_id' => $return->id,
                'entry_date'     => $date,
                'type'           => PartyLedgerEntry::TYPE_SALE_RETURN,
                'amount'         => $totalReturn,
                'description'    => 'Satış #' . $this->id . ' iadesi',
            ]);
        }

        return $return;
    }

    /** Satış tutarını cari borç (satis) kaydına yazar/günceller. */
    protected function syncLedger(float $total): void
    {
        $this->ledgerEntry()->updateOrCreate(
            ['sale_id' => $this->id],
            [
                'party_id'    => $this->party_id,
                'project_id'  => $this->project_id,
                'entry_date'  => $this->sale_date,
                'type'        => PartyLedgerEntry::TYPE_SALE,
                'amount'      => $total,
                'description' => 'Direkt satış #' . $this->id,
            ],
        );
    }
}
