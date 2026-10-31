<?php

namespace App\Models;

use App\Support\StockUsage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Anlaşma kalemi. Tür kalemden anlaşılır (kullanıcı tür seçmez):
 *   miktarlı = miktar × birim fiyat · götürü = is_lump (miktar 1, fiyat = toplam) · fiyat listesi = miktar boş.
 * Gelen = bu kaleme bağlı teslim satırları − iadeler (stock_movements.agreement_item_id); saklanmaz.
 */
class AgreementItem extends Model
{
    use Concerns\LogsActivityAsPart;

    /** İşlem geçmişi: alt satır — anlaşma "düzenlendi" olarak yazılır. */
    public function activityParent(): ?Model
    {
        return Agreement::find($this->agreement_id);
    }

    protected $fillable = [
        'agreement_id',
        'product_id',
        'quantity',
        'unit_price',
        'amount',
        'is_lump',
        'closed_at',
        'sort',
    ];

    protected $casts = [
        'quantity'   => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount'     => 'decimal:2',
        'is_lump'    => 'boolean',
        'closed_at'  => 'datetime',
    ];

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Teslim satırları ve iadeleri. */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function isList(): bool
    {
        return ! $this->is_lump && $this->quantity === null;
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /** Gelen miktar (götürüde oran: 0,5 = yarısı — tutardan; miktar sütunu 2 haneye yuvarlanıyor). */
    public function deliveredQuantity(): float
    {
        if ($this->is_lump) {
            $total = (float) $this->unit_price;

            return $total > 0 ? $this->deliveredAmount() / $total : 0.0;
        }

        return $this->signedSum('quantity');
    }

    public function deliveredAmount(): float
    {
        return $this->signedSum('amount');
    }

    /** Kalan miktar (fiyat listesinde yok → null). Eksi olabilir: anlaşmadan fazla geldi. */
    public function remainingQuantity(): ?float
    {
        if ($this->isList()) {
            return null;
        }

        return round((float) $this->quantity - $this->deliveredQuantity(), $this->is_lump ? 4 : 2);
    }

    /**
     * Anlaşılan tutar (fiyat listesinde yok). Miktarlı kalemde SAKLI tutar esastır: "3000 tuğla 1 milyona" → 1.000.000
     * (miktar × kuruşa yuvarlı fiyat 999.990 çıkıyordu, 31.10). Tutarı boş eski satırda miktar × fiyat.
     */
    public function agreedAmount(): float
    {
        if ($this->isList()) {
            return 0.0;
        }
        if (! $this->is_lump && $this->amount !== null && (float) $this->amount > 0) {
            return round((float) $this->amount, 2);
        }

        return round((float) $this->quantity * (float) $this->unit_price, 2);
    }

    /** Kuruşu yuvarlanmamış birim fiyat: miktarlı kalemde anlaşılan tutar ÷ miktar (1.000.000 ÷ 3000 = 333,333…). */
    public function exactPrice(): float
    {
        $qty = (float) $this->quantity;
        if (! $this->is_lump && ! $this->isList() && $qty > 0 && $this->amount !== null && (float) $this->amount > 0) {
            return (float) $this->amount / $qty;
        }

        return (float) $this->unit_price;
    }

    /**
     * Anlaşma fiyatından $qty teslimin tutarı: kuruşsuz fiyattan (400 tuğla → 133.333,33); kalan miktarı BİTİREN teslim kalan
     * tutarı alır — hepsi teslim edilince toplam tam anlaşılan tutar olur (son kuruş farkı kalmaz).
     */
    public function amountFor(float $qty): float
    {
        $rest = $this->is_lump ? null : $this->remainingQuantity();
        if ($rest !== null && $rest > 0 && abs($qty - $rest) < 0.005) {
            $left = round($this->agreedAmount() - $this->deliveredAmount(), 2);
            if ($left > 0) {
                return $left;
            }
        }

        return round($qty * $this->exactPrice(), 2);
    }

    /** Kalan tutar = kalan miktar × anlaşma fiyatı (eksiye inmez). Götürüde düz çıkarma: anlaşılan − gelen tutar. */
    public function remainingAmount(): float
    {
        // Götürü: kalan oran 4 haneye yuvarlanıp toplamla çarpılınca 590.000 yerine 589.980 çıkıyordu (25.10 kullanım incelemesi).
        if ($this->is_lump) {
            return max(0.0, round($this->agreedAmount() - $this->deliveredAmount(), 2));
        }
        $rest = $this->remainingQuantity();

        return $rest === null ? 0.0 : max(0.0, round($rest * $this->exactPrice(), 2));
    }

    /** Ekranda kalem adı (ürün kartı adı). */
    public function name(): string
    {
        return $this->product?->name ?? '—';
    }

    public function unitName(): string
    {
        return $this->product?->unit?->name ?? '';
    }

    /** "300 m³" / "götürü" / "liste" — kısa miktar metni. */
    public function quantityLabel(?float $qty): string
    {
        if ($qty === null) {
            return '—';
        }

        return trim(StockUsage::quantity($qty) . ' ' . $this->unitName());
    }

    private function signedSum(string $column): float
    {
        $rows = $this->relationLoaded('movements') ? $this->movements : $this->movements()->get();

        return round($rows->sum(fn (StockMovement $m) => ($m->reason === StockMovement::REASON_RETURN ? -1 : 1) * (float) $m->{$column}), 2);
    }
}
