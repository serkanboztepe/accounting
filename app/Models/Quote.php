<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Quote extends Model
{
    public const STATUS_DRAFT     = 'taslak';
    public const STATUS_SENT      = 'gonderildi';
    public const STATUS_ACCEPTED  = 'kabul';
    public const STATUS_REJECTED  = 'red';
    public const STATUS_EXPIRED   = 'suresi_doldu';

    public const STATUSES = [
        self::STATUS_DRAFT    => 'Taslak',
        self::STATUS_SENT     => 'Gönderildi',
        self::STATUS_ACCEPTED => 'Kabul Edildi',
        self::STATUS_REJECTED => 'Reddedildi',
        self::STATUS_EXPIRED  => 'Süresi Doldu',
    ];

    protected $fillable = [
        'party_id',
        'project_id',
        'title',
        'quote_date',
        'valid_until',
        'total_amount',
        'status',
        'converted_contract_id',
        'notes',
        'payment_plan',
    ];

    protected $casts = [
        'quote_date'   => 'date',
        'valid_until'  => 'date',
        'total_amount' => 'decimal:2',
        'payment_plan' => 'array',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }

    public function convertedContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'converted_contract_id');
    }

    /**
     * Manuel toplam varsa onu, yoksa kalemlerin toplamını döndürür.
     * (Contract::reportableTotal ile aynı mantık — bkz. project_contract_total_model)
     */
    public function reportableTotal(): float
    {
        $manual = (float) $this->total_amount;
        if ($manual > 0) {
            return $manual;
        }

        return (float) ($this->relationLoaded('items')
            ? $this->items->sum('amount')
            : $this->items()->sum('amount'));
    }

    public function isConverted(): bool
    {
        return $this->converted_contract_id !== null;
    }

    /**
     * Teklifi bir SATIŞ sözleşmesine dönüştürür (direction=satis → alacak, maliyete girmez).
     * Kalemler birebir kopyalanır. Zaten dönüştürülmüşse mevcut sözleşmeyi döndürür.
     */
    public function convertToContract(): Contract
    {
        if ($this->isConverted()) {
            return $this->convertedContract;
        }

        return DB::transaction(function (): Contract {
            $contract = Contract::create([
                'project_id'    => $this->project_id,
                'party_id'      => $this->party_id,
                'title'         => $this->title,
                'contract_type' => null, // satış sözleşmesinde tedarikçi/taşeron türü uygulanmaz
                'direction'     => Contract::DIRECTION_SALE,
                'contract_date' => $this->quote_date ?? now(),
                'total_amount'  => Money::store($this->reportableTotal()) ?? '0.00',
                'status'        => 'draft',
                'notes'         => $this->notes,
            ]);

            foreach ($this->items as $item) {
                $contract->items()->create([
                    'description' => $item->description,
                    'unit_id'     => $item->unit_id,
                    'quantity'    => $item->quantity,
                    'unit_price'  => $item->unit_price,
                    'amount'      => $item->amount,
                    'notes'       => $item->notes,
                ]);
            }

            // Ödeme planı satırları → doğrudan Ödemeler (plan yok). Hepsi "ödenmedi" gelir;
            // çek satırı bağlı Check kaydı açar (durum senkronu). Yöntem 3'e indirgenir.
            foreach ($this->payment_plan ?? [] as $line) {
                $type = match ($line['payment_type'] ?? null) {
                    'cash'  => 'cash',
                    'check' => 'check',
                    default => 'bank_transfer', // eft/havale/senet/diğer → havale-eft
                };

                $payment = $contract->payments()->create([
                    'payment_date' => $line['date'] ?? $contract->contract_date,
                    'payment_type' => $type,
                    'status'       => 'unpaid',
                    'amount'       => Money::store($line['amount'] ?? 0) ?? '0.00',
                    'notes'        => $line['note'] ?? null,
                ]);

                $payment->syncCheck($line['date'] ?? null);
            }

            $this->update([
                'status'                => self::STATUS_ACCEPTED,
                'converted_contract_id' => $contract->id,
            ]);

            return $contract;
        });
    }
}
