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
    use Concerns\LogsActivity;

    protected static function booted(): void
    {
        // İşlem geçmişi: alanlar (tarih, cari, peşin) değişmeden önceki hal — kalemler rebuildFromLines'ta kurulunca yazılır.
        static::updating(fn (self $purchase) => $purchase->activityRemember());
    }

    /** İşlem geçmişi: ekleme/düzenleme rebuildFromLines sonunda (kalemler + toplam kurulunca) yazılır; silme kendiliğinden. */
    private ?array $activityBefore = null;

    public function activityAuto(): bool
    {
        return false;
    }

    public function activityAction(): string
    {
        return $this->paid ? 'Peşin alım' : 'Alım';
    }

    public function activityAmount(): ?float
    {
        return (float) $this->total_amount;
    }

    public function activitySummary(): ?string
    {
        return $this->activityLines() ?: $this->notes;
    }

    private function activityLines(): string
    {
        if (! $this->id) {
            return '';
        }

        return StockMovement::where('purchase_id', $this->id)->whereNull('purchase_return_id')->with('product.unit')->get()
            ->map(fn (StockMovement $m) => \App\Support\StockUsage::quantity((float) $m->quantity) . ' ' . ($m->product?->unit?->name ? $m->product->unit->name . ' ' : '')
                . ($m->product?->name ?? 'Ürün') . ($m->amount !== null ? ' · ' . \App\Models\ActivityLog::money($m->amount) : ''))
            ->implode("\n");
    }

    public function activityDetails(): array
    {
        return \App\Models\ActivityLog::compact([
            'Belge'         => 'Alış #' . $this->id,
            'Tarih'         => \App\Models\ActivityLog::date($this->purchase_date),
            'Cari'          => $this->party_id ? \App\Models\ActivityLog::name(Party::class, $this->party_id) : 'Carisiz',
            'Ürünler'       => $this->activityLines(),
            'Ürünsüz tutar' => (float) $this->manual_amount > 0 ? \App\Models\ActivityLog::money($this->manual_amount) : null,
            'Toplam'        => \App\Models\ActivityLog::money($this->total_amount),
            'Ödeme'         => $this->paid ? 'Peşin · ' . (self::PAYMENT_TYPES[$this->payment_type] ?? 'Nakit') : 'Veresiye (cariye borç)',
            \App\Support\Labels::project() => \App\Models\ActivityLog::name(\App\Models\Project::class, $this->project_id),
            'Not'           => $this->notes,
        ]);
    }

    /** Alış silinince iadeleri veritabanında kendiliğinden silinir — önce yaz. */
    public function activityBeforeDelete(?string $note = null): void
    {
        foreach (PurchaseReturn::where('purchase_id', $this->id)->get() as $return) {
            \App\Models\ActivityLog::record($return, 'deleted', note: $note ?? 'Alış #' . $this->id . ' silindiği için');
        }
    }

    private function activityRemember(): void
    {
        if ($this->activityBefore === null && $this->exists && ! $this->wasRecentlyCreated) {
            $this->activityBefore = ['details' => $this->activityOriginal()->activityDetails(), 'amount' => (float) $this->getRawOriginal('total_amount')];
        }
    }

    private function activityWrite(): void
    {
        $this->activityBefore === null
            ? \App\Models\ActivityLog::record($this, 'created')
            : \App\Models\ActivityLog::record($this, 'updated', $this->activityBefore['details'], beforeAmount: $this->activityBefore['amount']);
        $this->activityBefore = null;
    }

    protected $fillable = [
        'party_id',
        'project_id',
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

    /** İade başlıkları (tedarikçiye geri verilen mal). */
    public function purchaseReturns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    /** Bir ürün için: alınan − iade edilen = iade edilebilecek kalan. */
    public function returnableQtyForProduct(int $productId): float
    {
        $bought = (float) $this->lines()->where('product_id', $productId)->sum('quantity');
        $returned = (float) StockMovement::where('purchase_id', $this->id)->where('reason', StockMovement::REASON_RETURN)
            ->where('product_id', $productId)->sum('quantity');

        return max(0.0, $bought - $returned);
    }

    /** İade edilebilecek kalan tutar (toplam − önceki iadeler). */
    public function returnableAmount(): float
    {
        return max(0.0, (float) $this->total_amount - (float) $this->purchaseReturns()->sum('total_amount'));
    }

    /**
     * İade işle (Sale::processReturn'ün aynası): her ürün için çıkan/return stok hareketi + cariden alındıysa "alış iadesi"
     * (borcumuz azalır). $lines: [['product_id'=>, 'return_qty'=>, 'unit_price'=>], ...]; ürünsüz alışta $manualAmount.
     */
    public function processReturn(array $lines, string $date, ?string $notes = null, ?float $manualAmount = null): ?PurchaseReturn
    {
        $prepared = [];
        $total = 0.0;
        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $qty = (float) ($line['return_qty'] ?? 0);
            if (! $productId || $qty <= 0) {
                continue;
            }
            $unitPrice = blank($line['unit_price'] ?? null) ? null : Money::parse($line['unit_price']);
            $amount = $unitPrice !== null ? round($qty * $unitPrice, 2) : null;
            $prepared[] = compact('productId', 'qty', 'unitPrice', 'amount');
            $total += (float) ($amount ?? 0);
        }
        $total += max(0.0, (float) $manualAmount);
        if ($prepared === [] && $total <= 0) {
            return null;
        }

        $return = $this->purchaseReturns()->create([
            'party_id'     => $this->party_id,
            'project_id'   => $this->project_id,
            'return_date'  => $date,
            'total_amount' => $total,
            'notes'        => $notes,
        ]);

        foreach ($prepared as $p) {
            StockMovement::create([
                'product_id'         => $p['productId'],
                'purchase_id'        => $this->id,
                'purchase_return_id' => $return->id,
                'direction'          => StockMovement::DIRECTION_OUT,
                'reason'             => StockMovement::REASON_RETURN,
                'quantity'           => $p['qty'],
                'unit_price'         => $p['unitPrice'],
                'amount'             => $p['amount'],
                'party_id'           => $this->party_id,
                'project_id'         => $this->project_id,
                'movement_date'      => $date,
                'notes'              => $notes,
                'agreement_item_id' => $this->lines()->where('product_id', $p['productId'])->value('agreement_item_id'), // iade anlaşmadan da düşer
            ]);
        }

        // Peşin (carisiz) alışın iadesi cariye yazılmaz; stok yine düşer, alış toplamından düşülür.
        if ($total > 0 && $this->party_id) {
            PartyLedgerEntry::create([
                'party_id'           => $this->party_id,
                'project_id'         => $this->project_id,
                'purchase_return_id' => $return->id,
                'entry_date'         => $date,
                'type'               => PartyLedgerEntry::TYPE_PURCHASE_RETURN,
                'amount'             => $total,
                'description'        => 'Alış #' . $this->id . ' iadesi',
            ]);
        }

        \App\Models\ActivityLog::record($return, 'created');

        return $return;
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
        $this->activityRemember(); // işlem geçmişi: düzenlemeden önceki hal (kalemler silinmeden)
        // Düzenleme formu anlaşma bağını taşımaz: aynı ürünün eski bağı korunur (teslimat anlaşmadan kopmasın).
        $agreementLinks = $this->lines()->whereNotNull('agreement_item_id')->pluck('agreement_item_id', 'product_id');
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
                'project_id'    => $this->project_id,
                'movement_date' => $this->purchase_date,
                'agreement_item_id' => $line['agreement_item_id'] ?? $agreementLinks[$productId] ?? null, // anlaşma teslimatı
            ]);

            $total += (float) ($amount ?? 0);
            $this->rememberPrice((int) $productId, $unitPrice ?? ($amount !== null ? round($amount / $quantity, 2) : null));
        }

        $total += (float) ($this->manual_amount ?? 0);
        $this->forceFill(['total_amount' => $total])->saveQuietly();

        $this->syncLedger($total);
        $this->activityWrite();
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
                'project_id'  => $this->project_id,
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
