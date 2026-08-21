<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractPayment extends Model
{
    use HasFactory;

    public const PAYMENT_TYPES = [
        'cash'            => 'Nakit',
        'eft'             => 'EFT',
        'bank_transfer'   => 'Havale',
        'check'           => 'Çek',
        'promissory_note' => 'Senet',
        'other'           => 'Diğer',
    ];

    protected $fillable = [
        'contract_id',
        'payment_date',
        'payment_type',
        'amount',
        'notes',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }


    public function checks(): HasMany
    {
        return $this->hasMany(Check::class);
    }
}
