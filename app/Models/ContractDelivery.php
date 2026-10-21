<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Unit;

class ContractDelivery extends Model
{
    use Concerns\LogsActivity;

    public function activityPartyId(): ?int
    {
        $id = Contract::withoutGlobalScopes()->whereKey($this->contract_id)->value('party_id');

        return $id ? (int) $id : null;
    }

    public function activityAction(): string
    {
        return 'Teslimat / hakediş';
    }

    public function activityAmount(): ?float
    {
        return (float) $this->amount;
    }

    public function activitySummary(): ?string
    {
        return \App\Models\ActivityLog::name(Contract::class, $this->contract_id, 'title');
    }

    public function activityDetails(): array
    {
        return \App\Models\ActivityLog::compact([
            'Sözleşme'    => \App\Models\ActivityLog::name(Contract::class, $this->contract_id, 'title'),
            'Tarih'       => \App\Models\ActivityLog::date($this->delivery_date),
            'Kalem'       => \App\Models\ActivityLog::name(ContractItem::class, $this->contract_item_id, 'description') ?? \App\Models\ActivityLog::name(Product::class, $this->product_id),
            'Miktar'      => \App\Support\StockUsage::quantity((float) $this->quantity) . ($this->unit_id ? ' ' . \App\Models\ActivityLog::name(Unit::class, $this->unit_id) : ''),
            'Birim fiyat' => \App\Models\ActivityLog::money($this->unit_price),
            'Tutar'       => \App\Models\ActivityLog::money($this->amount),
            \App\Support\Labels::project() => \App\Models\ActivityLog::name(\App\Models\Project::class, $this->project_id),
            'Not'         => $this->notes,
        ]);
    }

    use HasFactory;

    protected $fillable = [
        'contract_id',
        'contract_item_id',
        'product_id',
        'project_id',
        'unit_id',
        'delivery_date',
        'quantity',
        'unit_price',
        'amount',
        'notes',
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // Teslimat ürün kimliğini kaleminden miras alır — tüm oluşturma yolları için
        // tek noktadan (böylece her teslimat, kalemi kataloğa bağlıysa stoğa da bağlanır).
        static::saving(function (ContractDelivery $delivery) {
            if ($delivery->product_id === null && $delivery->contract_item_id) {
                $productId = $delivery->contractItem()->value('product_id');
                if ($productId) {
                    $delivery->product_id = $productId;
                }
            }
        });
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function contractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function invoiceAllocations(): HasMany
    {
        return $this->hasMany(InvoiceDelivery::class, 'delivery_id');
    }
}
