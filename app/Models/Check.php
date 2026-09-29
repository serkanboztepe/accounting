<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Check extends Model
{
    use HasFactory;

    protected $fillable = [
        'party_id',
        'project_id',
        'contract_payment_id',
        'party_ledger_entry_id',
        'bounce_entry_id',
        'check_number',
        'bank_name',
        'issue_date',
        'due_date',
        'amount',
        'status',
        'description',
        'notes',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contractPayment(): BelongsTo
    {
        return $this->belongsTo(ContractPayment::class);
    }

    /** Çekle yapılan tahsilat satırı (kaynak). */
    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(PartyLedgerEntry::class, 'party_ledger_entry_id');
    }

    /**
     * Karşılıksız senkronu — Çekler ekranından statü değişince otomatik:
     *   bounced  → tahsilatı geri alan ters BORÇ satırı (Model A, seçenek ii). İz kalır, bakiye geri döner.
     *   bounced'tan çıkış → o ters kaydı geri al (idempotent, bounce_entry_id üzerinden).
     */
    protected static function booted(): void
    {
        static::updated(function (self $check): void {
            if (! $check->wasChanged('status')) {
                return;
            }

            if ($check->status === 'bounced' && $check->bounce_entry_id === null) {
                $counter = PartyLedgerEntry::create([
                    'party_id'     => $check->party_id,
                    'project_id'   => $check->project_id,
                    'entry_date'   => $check->due_date ?? now(),
                    'type'         => PartyLedgerEntry::TYPE_CHECK_BOUNCED,
                    'payment_type' => 'check',
                    'amount'       => $check->amount,
                    'description'  => 'Karşılıksız çek' . ($check->check_number ? ' #' . $check->check_number : ''),
                ]);

                $check->bounce_entry_id = $counter->id;
                $check->saveQuietly();
            } elseif ($check->status !== 'bounced' && $check->bounce_entry_id !== null) {
                PartyLedgerEntry::whereKey($check->bounce_entry_id)->delete();
                $check->bounce_entry_id = null;
                $check->saveQuietly();
            }
        });
    }
}
