<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartyLedgerEntry extends Model
{
    public const DIRECTION_DEBIT  = 'borc';   // cari borçlanır (bize borcu artar)
    public const DIRECTION_CREDIT = 'alacak'; // cari alacaklanır (bizim borcumuz artar)

    public const DIRECTIONS = [
        self::DIRECTION_DEBIT  => 'Borç',
        self::DIRECTION_CREDIT => 'Alacak',
    ];

    // Çift yönlü hareket tipleri → yön (borç/alacak) buradan türetilir.
    public const TYPE_SALE       = 'satis';    // biz sattık → cari borçlanır
    public const TYPE_PURCHASE   = 'alis';     // biz aldık → biz borçlanırız (cari alacak)
    public const TYPE_COLLECTION = 'tahsilat'; // para girdi → cari alacak
    public const TYPE_PAYMENT    = 'odeme';    // para çıktı → cari borç

    public const TYPES = [
        self::TYPE_SALE       => ['label' => 'Satış',         'direction' => self::DIRECTION_DEBIT],
        self::TYPE_PURCHASE   => ['label' => 'Alış / Hizmet', 'direction' => self::DIRECTION_CREDIT],
        self::TYPE_COLLECTION => ['label' => 'Tahsilat',      'direction' => self::DIRECTION_CREDIT],
        self::TYPE_PAYMENT    => ['label' => 'Ödeme',         'direction' => self::DIRECTION_DEBIT],
    ];

    protected $fillable = [
        'party_id',
        'project_id',
        'entry_date',
        'description',
        'type',
        'direction',
        'amount',
        'notes',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'amount'     => 'decimal:2',
    ];

    /**
     * Yön her zaman tipten türetilir (tip varsa) — bakiye hesabı tutarlı kalsın.
     */
    protected static function booted(): void
    {
        static::saving(function (self $entry): void {
            if ($entry->type && isset(self::TYPES[$entry->type])) {
                $entry->direction = self::TYPES[$entry->type]['direction'];
            }
        });
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type]['label']
            ?? (self::DIRECTIONS[$this->direction] ?? '—');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
