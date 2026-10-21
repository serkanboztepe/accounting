<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use Concerns\LogsActivity;

    public function activityPartyId(): ?int
    {
        $id = Contract::withoutGlobalScopes()->whereKey($this->contract_id)->value('party_id');

        return $id ? (int) $id : null;
    }

    public function activityAction(): string
    {
        return 'Fatura';
    }

    public function activityAmount(): ?float
    {
        return (float) $this->total_amount;
    }

    public function activitySummary(): ?string
    {
        return trim('No ' . $this->invoice_number . ' · ' . \App\Models\ActivityLog::name(Contract::class, $this->contract_id, 'title'));
    }

    public function activityDetails(): array
    {
        return \App\Models\ActivityLog::compact([
            'Fatura no' => $this->invoice_number,
            'Tarih'     => \App\Models\ActivityLog::date($this->invoice_date),
            'Tutar'     => \App\Models\ActivityLog::money($this->total_amount),
            'Sözleşme'  => \App\Models\ActivityLog::name(Contract::class, $this->contract_id, 'title'),
            'Dosya'     => $this->invoice_path ? 'Var' : null,
            'Not'       => $this->notes,
        ]);
    }

    use HasFactory;

    protected $fillable = [
        'contract_id',
        'invoice_number',
        'invoice_date',
        'total_amount',
        'invoice_path',
        'notes',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'total_amount' => 'decimal:2',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function deliveryAllocations(): HasMany
    {
        return $this->hasMany(InvoiceDelivery::class);
    }
}
