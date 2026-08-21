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

    protected $fillable = [
        'party_id',
        'entry_date',
        'description',
        'direction',
        'amount',
        'notes',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'amount'     => 'decimal:2',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
