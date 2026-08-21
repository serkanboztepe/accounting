<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contract extends Model
{
    use HasFactory;

    /**
     * Satış (alacak) sözleşmeleri proje maliyeti/borcu DEĞİLDİR.
     * Varsayılan olarak tüm sözleşme sorgularından dışlanır; böylece hiçbir
     * maliyet/borç hesabı (rapor, dashboard, cari bakiyesi, ilişki toplamları)
     * satışı yanlışlıkla toplayamaz. Sözleşme ekranı bu scope'u bilinçli kaldırır.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('purchase', function (Builder $query) {
            $query->where('contracts.direction', '!=', self::DIRECTION_SALE);
        });
    }

    public const TYPE_SUPPLY = 'supply';
    public const TYPE_SUBCONTRACT = 'subcontract';

    public const TYPES = [
        self::TYPE_SUPPLY => 'Tedarikçi',
        self::TYPE_SUBCONTRACT => 'Taşeron',
    ];

    // Para yönü: alim = borç (maliyet), satis = alacak (tekliften doğan satış)
    public const DIRECTION_PURCHASE = 'alim';
    public const DIRECTION_SALE = 'satis';

    public const DIRECTIONS = [
        self::DIRECTION_PURCHASE => 'Alım',
        self::DIRECTION_SALE => 'Satış',
    ];

    protected $fillable = [
        'project_id',
        'party_id',
        'title',
        'contract_type',
        'direction',
        'contract_date',
        'start_date',
        'end_date',
        'total_amount',
        'status',
        'notes',
        'payment_plan',
    ];

    protected $casts = [
        'contract_date' => 'date',
        'start_date'    => 'date',
        'end_date'      => 'date',
        'total_amount'  => 'decimal:2',
        'payment_plan'  => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ContractPayment::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ContractDelivery::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContractItem::class);
    }

    public function updateTotalAmount(): void
    {
        $this->updateQuietly(['total_amount' => $this->items()->sum('amount')]);
    }

    public function reportableTotal(): float
    {
        $manual = (float) $this->total_amount;
        if ($manual > 0) {
            return $manual;
        }

        return (float) ($this->relationLoaded('items')
            ? $this->items->sum('amount')
            : $this->items()->sum('amount'));
    }

    public function invoicedAmount(): float
    {
        return (float) $this->invoices()->sum('total_amount');
    }

    public function uninvoicedAmount(): float
    {
        return max(0, $this->reportableTotal() - $this->invoicedAmount());
    }

    public function invoiceStatus(): string
    {
        $count = $this->invoices()->count();
        if ($count === 0) {
            return 'none';
        }
        if ($this->uninvoicedAmount() <= 0) {
            return 'complete';
        }
        return 'partial';
    }

    public function deliveredAmount(): float
    {
        return (float) $this->deliveries()->sum('amount');
    }

    public function paidAmount(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function cashPaidAmount(): float
    {
        $nonCheck = (float) $this->payments()
            ->where('payment_type', '!=', 'check')
            ->sum('amount');

        $collectedChecks = (float) Check::query()
            ->whereIn('status', ['paid', 'collected'])
            ->whereHas('contractPayment', fn ($q) => $q->where('contract_id', $this->id))
            ->sum('amount');

        return $nonCheck + $collectedChecks;
    }

    public function pendingCheckAmount(): float
    {
        return (float) Check::query()
            ->where('status', 'issued')
            ->whereHas('contractPayment', fn ($q) => $q->where('contract_id', $this->id))
            ->sum('amount');
    }

    public function remainingPaymentAmount(): float
    {
        return max(0, $this->reportableTotal() - $this->paidAmount());
    }

    public function isSubcontract(): bool
    {
        return $this->contract_type === self::TYPE_SUBCONTRACT;
    }

    public function isSupply(): bool
    {
        return $this->contract_type === self::TYPE_SUPPLY;
    }

    public function isSale(): bool
    {
        return $this->direction === self::DIRECTION_SALE;
    }

    public function isPurchase(): bool
    {
        return $this->direction !== self::DIRECTION_SALE;
    }
}
