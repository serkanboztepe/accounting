<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Anlaşma (Faz 6, 21.10): cariyle "şunu şu fiyattan alacağız / satacağız". Borç YAZMAZ — teslimat normal alış/satış
 * belgesidir, kalemi anlaşma kalemine bağlanır. Kart: Anlaşılan / Gelen / Kalan (ödenen yok; para cari ekstresinde).
 * Muhasebe programlarındaki Sipariş → İrsaliye deseni: teslimde anlaşma değil kalem seçilir. İş mantığı: AgreementDesk.
 */
class Agreement extends Model
{
    use Concerns\LogsActivity;

    public const DIRECTION_PURCHASE = 'purchase';
    public const DIRECTION_SALE = 'sale';

    public const DIRECTIONS = [
        self::DIRECTION_PURCHASE => 'Alış',
        self::DIRECTION_SALE     => 'Satış',
    ];

    public const STATUS_OPEN = 'open';
    public const STATUS_DONE = 'done';      // hepsi geldi (kendiliğinden)
    public const STATUS_CLOSED = 'closed';  // elle kapatıldı — kalan borç doğurmaz

    public const STATUSES = [
        self::STATUS_OPEN   => 'Açık',
        self::STATUS_DONE   => 'Bitti',
        self::STATUS_CLOSED => 'Kapatıldı',
    ];

    protected $fillable = [
        'party_id',
        'direction',
        'project_id',
        'title',
        'agreement_date',
        'budget',
        'status',
        'notes',
    ];

    protected $casts = [
        'agreement_date' => 'date',
        'budget'         => 'decimal:2',
    ];

    public function activityAction(): string
    {
        return 'Anlaşma';
    }

    public function activityAmount(): ?float
    {
        return $this->id ? $this->agreedTotal() : null;
    }

    public function activitySummary(): ?string
    {
        return $this->title;
    }

    public function activityDetails(): array
    {
        $items = $this->id ? AgreementItem::with('product')->where('agreement_id', $this->id)->orderBy('sort')->orderBy('id')->get()
            ->map(fn (AgreementItem $i) => $i->name() . ' · ' . match (true) {
                $i->is_lump  => 'götürü ' . ActivityLog::money($i->unit_price),
                $i->isList() => 'liste ' . ActivityLog::money($i->unit_price),
                default      => $i->quantityLabel((float) $i->quantity) . ' × ' . ActivityLog::money($i->unit_price),
            } . ($i->isClosed() ? ' (eski fiyat)' : ''))->implode("\n") : '';

        return ActivityLog::compact([
            'Ad'       => $this->title,
            'Yön'      => self::DIRECTIONS[$this->direction] ?? $this->direction,
            'Tarih'    => ActivityLog::date($this->agreement_date),
            \App\Support\Labels::project() => ActivityLog::name(Project::class, $this->project_id),
            'Bütçe'    => ActivityLog::money($this->budget),
            'Durum'    => self::STATUSES[$this->status] ?? $this->status,
            'Kalemler' => $items,
            'Not'      => $this->notes,
        ]);
    }

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
        return $this->hasMany(AgreementItem::class)->orderBy('sort')->orderBy('id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function isPurchase(): bool
    {
        return $this->direction === self::DIRECTION_PURCHASE;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /** Kalemler + teslimatları (kart ve liste için tek sorgu). */
    public function loadForCard(): static
    {
        return $this->loadMissing(['items.product.unit', 'items.movements', 'project']);
    }

    /** Anlaşılan: miktarlı + götürü kalemlerin toplamı (fiyat listesi girmez — onun bütçesi var). */
    public function agreedTotal(): float
    {
        return round($this->items->sum(fn (AgreementItem $i) => $i->agreedAmount()), 2);
    }

    /** Gelen: tüm kalemlere gelen teslimatların tutarı (iadeler düşülür). */
    public function deliveredTotal(): float
    {
        return round($this->items->sum(fn (AgreementItem $i) => $i->deliveredAmount()), 2);
    }

    /** Kalan: miktarlı + götürü kalemlerde kalan × anlaşma fiyatı. Kapatılmış anlaşmada 0. */
    public function remainingTotal(): float
    {
        if ($this->status === self::STATUS_CLOSED) {
            return 0.0;
        }

        return round($this->items->sum(fn (AgreementItem $i) => $i->isClosed() ? 0 : $i->remainingAmount()), 2);
    }

    public function hasListItems(): bool
    {
        return $this->items->contains(fn (AgreementItem $i) => $i->isList() && ! $i->isClosed());
    }

    /** İlerleme yüzdesi (anlaşılan tutar üzerinden; yalnız fiyat listesiyse null). */
    public function progress(): ?int
    {
        $agreed = $this->agreedTotal();
        if ($agreed <= 0) {
            return null;
        }
        $done = $agreed - $this->remainingTotal();

        return (int) max(0, min(100, round($done / $agreed * 100)));
    }

    public function hasDeliveries(): bool
    {
        return StockMovement::whereIn('agreement_item_id', $this->items()->pluck('id'))->exists();
    }

    /** Cari sayfasında düğme adı: alışta "Teslim aldım", satışta "Teslim ettim". */
    public function deliverLabel(): string
    {
        return $this->isPurchase() ? 'Teslim aldım' : 'Teslim ettim';
    }
}
