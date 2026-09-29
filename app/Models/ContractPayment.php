<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractPayment extends Model
{
    use HasFactory;

    // Sadeleştirilmiş yöntemler: Nakit / Havale-EFT / Çek (senet + diğer kaldırıldı).
    public const PAYMENT_TYPES = [
        'cash'          => 'Nakit',
        'bank_transfer' => 'Havale/EFT',
        'check'         => 'Çek',
    ];

    // Ödeme durumu — hepsi için aynı (giderdeki gibi).
    public const STATUSES = [
        'unpaid' => 'Ödenmedi',
        'paid'   => 'Ödendi',
    ];

    protected $fillable = [
        'contract_id',
        'payment_date',
        'payment_type',
        'status',
        'amount',
        'notes',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // Ödeme silinince bağlı çek(ler) de temizlensin (öksüz çek kalmasın).
        static::deleting(function (self $payment): void {
            $payment->checks()->delete();
        });
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(Check::class);
    }

    /**
     * Çek ödemesiyse bağlı Check kaydını oluştur/güncelle; çek statüsü ödeme durumundan
     * türetilir (ödenmedi → verildi/issued, ödendi → ödendi/paid). Çek değilse bağlı
     * çek(ler) temizlenir. Tek doğruluk noktası — hem Ödemeler ekranı hem teklif dönüşümü buradan geçer.
     */
    public function syncCheck(?string $dueDate = null): void
    {
        if ($this->payment_type !== 'check') {
            $this->checks()->delete();

            return;
        }

        // Satış sözleşmeleri global scope ('purchase') ile gizli — çeki bağlarken scope'suz yükle,
        // yoksa teklif→sözleşme (satış) dönüşümünde contract null gelir, party_id NOT NULL patlar.
        $contract = $this->contract()->withoutGlobalScopes()->first();

        $payload = [
            'party_id'   => $contract?->party_id,
            'project_id' => $contract?->project_id,
            'issue_date' => $this->payment_date,
            'due_date'   => $dueDate ?? $this->payment_date,
            'amount'     => $this->amount,
            'status'     => $this->status === 'paid' ? 'paid' : 'issued',
        ];

        $check = $this->checks()->first();
        $check ? $check->update($payload) : $this->checks()->create($payload);
    }
}
