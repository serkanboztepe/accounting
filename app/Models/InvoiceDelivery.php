<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceDelivery extends Model
{
    protected $fillable = [
        'invoice_id',
        'delivery_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(ContractDelivery::class, 'delivery_id');
    }
}
