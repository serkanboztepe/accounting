<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Party extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'notes',
    ];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function contractPayments(): HasMany
    {
        return $this->hasMany(ContractPayment::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(Check::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(PartyLedgerEntry::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * Müşterinin TÜM satışlarındaki iade edilebilir kalemler — üstteki "İade Al" listesi.
     * Her satır kendi satışına + fiyatına bağlı (LIFO/tahmin yok). En yeni satış üstte.
     *
     * @return array<int, array<string, mixed>>
     */
    public function returnableLines(): array
    {
        $rows = [];

        foreach ($this->sales()->orderByDesc('sale_date')->orderByDesc('id')->get() as $sale) {
            foreach ($sale->returnFormLines() as $line) {
                $rows[] = array_merge($line, [
                    'sale_id'          => $sale->id,
                    'sale_ref'         => '#' . $sale->id,
                    'sale_date'        => $sale->sale_date?->format('d.m.Y'),
                    'unit_price_label' => $line['unit_price'] !== null ? $line['unit_price'] . ' ₺' : '—',
                ]);
            }
        }

        return $rows;
    }
}
