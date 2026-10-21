<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteItem extends Model
{
    use Concerns\LogsActivityAsPart;

    /** İşlem geçmişi: alt satır — üst kayıt "düzenlendi" olarak yazılır. */
    public function activityParent(): ?\Illuminate\Database\Eloquent\Model
    {
        return Quote::find($this->quote_id);
    }

    protected $fillable = [
        'quote_id',
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

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
