<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractItem extends Model
{
    use Concerns\LogsActivityAsPart;

    /** İşlem geçmişi: alt satır — üst kayıt "düzenlendi" olarak yazılır. */
    public function activityParent(): ?\Illuminate\Database\Eloquent\Model
    {
        return Contract::withoutGlobalScopes()->find($this->contract_id);
    }

    protected $fillable = [
        'contract_id',
        'product_id',
        'description',
        'unit_id',
        'quantity',
        'unit_price',
        'amount',
        'notes',
    ];

    protected $casts = [
        'quantity'   => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount'     => 'decimal:2',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ContractDelivery::class);
    }

    public function deliveredQuantity(): float
    {
        return (float) $this->deliveries()->sum('quantity');
    }

    public function deliveredAmount(): float
    {
        return (float) $this->deliveries()->sum('amount');
    }

    public function remainingQuantity(): float
    {
        return max(0, (float) $this->quantity - $this->deliveredQuantity());
    }
}
