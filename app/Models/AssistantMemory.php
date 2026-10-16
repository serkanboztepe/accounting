<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Asistan hafızası (Faz 2): kullanıcının öğrettiği kalıcı bilgi. Bkz. migration 2026_10_17_000001. */
class AssistantMemory extends Model
{
    public const KINDS = [
        'alias' => 'Kişi / takma ad',
        'agreement' => 'Anlaşma / karar',
        'price' => 'Fiyat / alışkanlık',
        'preference' => 'Tercih',
        'note' => 'Not',
    ];

    public const ICONS = ['alias' => '👤', 'agreement' => '🤝', 'price' => '💰', 'preference' => '⚙️', 'note' => '📝'];

    protected $fillable = ['kind', 'subject', 'fact', 'party_id', 'product_id', 'phone', 'taught_by', 'source', 'status', 'replaced_by_id'];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Bu numara için geçerli olanlar: firmanın ortak bilgileri + yalnız bu numaranın tercihleri. */
    public function scopeActiveFor(Builder $q, ?string $phone): Builder
    {
        return $q->where('status', 'active')
            ->where(fn ($w) => $w->whereNull('phone')->when($phone, fn ($x) => $x->orWhere('phone', $phone)));
    }

    public function line(): string
    {
        return (self::ICONS[$this->kind] ?? '•') . ' ' . $this->fact;
    }
}
