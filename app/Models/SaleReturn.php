<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SaleReturn extends Model
{
    use Concerns\LogsActivity;

    /** İşlem geçmişi: iade processReturn sonunda (kalemler kurulunca) yazılır; silme kendiliğinden. */
    public function activityAuto(): bool
    {
        return false;
    }

    public function activityAction(): string
    {
        return 'İade aldı';
    }

    public function activityAmount(): ?float
    {
        return (float) $this->total_amount;
    }

    public function activitySummary(): ?string
    {
        return 'Satış #' . $this->sale_id . ' iadesi';
    }

    public function activityDetails(): array
    {
        $lines = $this->id ? StockMovement::where('sale_return_id', $this->id)->with('product.unit')->get()
            ->map(fn (StockMovement $m) => \App\Support\StockUsage::quantity((float) $m->quantity) . ' ' . ($m->product?->unit?->name ? $m->product->unit->name . ' ' : '')
                . ($m->product?->name ?? 'Ürün') . ($m->amount !== null ? ' · ' . \App\Models\ActivityLog::money($m->amount) : ''))->implode("\n") : '';

        return \App\Models\ActivityLog::compact([
            'Belge'   => 'Satış #' . $this->sale_id,
            'Tarih'   => \App\Models\ActivityLog::date($this->return_date),
            'Cari'    => \App\Models\ActivityLog::name(Party::class, $this->party_id),
            'Ürünler' => $lines,
            'Toplam'  => \App\Models\ActivityLog::money($this->total_amount),
            'Not'     => $this->notes,
        ]);
    }

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
