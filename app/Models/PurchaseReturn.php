<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Alış iadesi (SaleReturn'ün aynası): tedarikçiye geri verilen mal. Silinince stok çıkışı + cari satırı birlikte gider. */
class PurchaseReturn extends Model
{
    protected $fillable = [
        'purchase_id',
        'party_id',
        'project_id',
        'return_date',
        'total_amount',
        'notes',
    ];

    protected $casts = [
        'return_date'  => 'date',
        'total_amount' => 'decimal:2',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** İade kalemleri = stok çıkışları (out/return). */
    public function lines(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'purchase_return_id');
    }

    /** İadenin cari kaydı (borcumuz azalır). */
    public function ledgerEntry(): HasOne
    {
        return $this->hasOne(PartyLedgerEntry::class, 'purchase_return_id');
    }
}
