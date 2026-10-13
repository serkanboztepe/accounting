<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Alış belgesi (Alışlar tezgahı) — satışın aynası. Kalemler = stok girişleri (in/purchase), toplam =
 * cariden alındıysa cari "alış" satırı; peşinse aynı tutarda "ödeme" satırı da yazılır (bakiye değişmez).
 * Carisiz peşin alış cariye hiçbir şey yazmaz. Silinince stok girişleri ve cari satırları birlikte gider.
 * Caride basit "Alış Yaptım" (belgesiz cari satırı) bundan bağımsızdır.
 */
class Purchase extends Model
{
    protected $fillable = [
        'party_id',
        'purchase_date',
        'total_amount',
        'manual_amount',
        'paid',
        'payment_type',
        'notes',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'total_amount'  => 'decimal:2',
        'manual_amount' => 'decimal:2',
        'paid'          => 'boolean',
    ];

    public const PAYMENT_TYPES = [
        'cash'          => 'Nakit',
        'card'          => 'Kart',
        'bank_transfer' => 'Havale / EFT',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** Alış kalemleri = stok girişleri. */
    public function lines(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'purchase_id')
            ->where('reason', StockMovement::REASON_PURCHASE);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(PartyLedgerEntry::class, 'purchase_id');
    }

    public function supplierLabel(): string
    {
        return $this->party?->name ?? 'Peşin';
    }

    /**
     * Kalemleri stok girişine + cari satırlarına dönüştürür (tek doğruluk noktası). Düzenlemede eski
     * kalemler silinip yeniden yazılır. $lines: [['product_id'=>, 'quantity'=>, 'unit_price'=>, 'amount'=>], ...]
     */
    public function rebuildFromLines(array $lines): void
    {
        $this->lines()->delete();

        $total = 0.0;
        foreach ($lines as $line) {
            $productId = $line['product_id'] ?? null;
            $quantity = (float) ($line['quantity'] ?? 0);
            if (! $productId || $quantity <= 0) {
                continue;
            }

            $unitPrice = blank($line['unit_price'] ?? null) ? null : Money::parse($line['unit_price']);
            $lineAmount = blank($line['amount'] ?? null) ? null : Money::parse($line['amount']);
            $amount = $lineAmount ?? ($unitPrice !== null ? round($quantity * $unitPrice, 2) : null);

            StockMovement::create([
                'product_id'    => $productId,
                'purchase_id'   => $this->id,
                'direction'     => StockMovement::DIRECTION_IN,
                'reason'        => StockMovement::REASON_PURCHASE,
                'quantity'      => $quantity,
                'unit_price'    => $unitPrice,
                'amount'        => $amount,
                'party_id'      => $this->party_id,
                'movement_date' => $this->purchase_date,
            ]);

            $total += (float) ($amount ?? 0);
            $this->rememberPrice((int) $productId, $unitPrice ?? ($amount !== null ? round($amount / $quantity, 2) : null));
        }

        $total += (float) ($this->manual_amount ?? 0);
        $this->forceFill(['total_amount' => $total])->saveQuietly();

        $this->syncLedger($total);
    }

    /** Ürün kartındaki alış fiyatı = en son alıştaki fiyat (eski tarihli bir alış düzeltilirse ezmez). */
    private function rememberPrice(int $productId, ?float $price): void
    {
        if ($price === null || $price <= 0) {
            return;
        }
        $newer = StockMovement::where('product_id', $productId)
            ->where('reason', StockMovement::REASON_PURCHASE)
            ->where('movement_date', '>', $this->purchase_date)
            ->exists();
        if (! $newer) {
            Product::whereKey($productId)->update(['purchase_price' => $price]);
        }
    }

    /** Cariden alış → "alış" satırı; peşinse + "ödeme" satırı (net bakiye 0). Carisizde cari satırı yok. */
    protected function syncLedger(float $total): void
    {
        if (! $this->party_id || $total <= 0) {
            $this->ledgerEntries()->delete();

            return;
        }

        $description = 'Alış #' . $this->id . ($this->notes ? ' — ' . str($this->notes)->limit(60) : '');

        $this->ledgerEntries()->updateOrCreate(
            ['type' => PartyLedgerEntry::TYPE_PURCHASE],
            [
                'party_id'    => $this->party_id,
                'entry_date'  => $this->purchase_date,
                'amount'      => $total,
                'description' => $description,
            ],
        );

        if ($this->paid) {
            $this->ledgerEntries()->updateOrCreate(
                ['type' => PartyLedgerEntry::TYPE_PAYMENT],
                [
                    'party_id'     => $this->party_id,
                    'entry_date'   => $this->purchase_date,
                    'amount'       => $total,
                    'payment_type' => $this->payment_type === 'card' ? 'other' : ($this->payment_type ?? 'cash'),
                    'description'  => 'Peşin ödeme — Alış #' . $this->id,
                ],
            );
        } else {
            $this->ledgerEntries()->where('type', PartyLedgerEntry::TYPE_PAYMENT)->delete();
        }
    }
}
