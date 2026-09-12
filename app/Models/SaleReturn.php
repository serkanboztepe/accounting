<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SaleReturn extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
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

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** İade kalemleri = stok girişleri (in/return). */
    public function lines(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'sale_return_id');
    }

    /** İadenin cari alacak kaydı. */
    public function ledgerEntry(): HasOne
    {
        return $this->hasOne(PartyLedgerEntry::class, 'sale_return_id');
    }
}
