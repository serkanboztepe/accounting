<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractItem extends Model
{
    protected $fillable = [
        'contract_id',
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
