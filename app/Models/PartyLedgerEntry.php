<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PartyLedgerEntry extends Model
{
    use Concerns\LogsActivity;

    /** İşlem geçmişi: belgenin (satış/alış/iade) cari satırı ayrı iş değil — belgenin satırında; karşılıksız çek karşılığı çekin satırında. */
    public function activitySkipped(): bool
    {
        return $this->sale_id || $this->purchase_id || $this->sale_return_id || $this->purchase_return_id
            || $this->type === self::TYPE_CHECK_BOUNCED;
    }

    public function activityAction(): string
    {
        return [self::TYPE_SALE => 'Satış', self::TYPE_COLLECTION => 'Para aldı', self::TYPE_PURCHASE => 'Alım',
            self::TYPE_PAYMENT => 'Ödeme yaptı', self::TYPE_SALE_RETURN => 'İade aldı', self::TYPE_PURCHASE_RETURN => 'İade verdi'][$this->type]
            ?? 'Cari hareketi';
    }

    public function activityAmount(): ?float
    {
        return (float) $this->amount;
    }

    public function activitySummary(): ?string
    {
        return $this->description;
    }

    public function activityDetails(): array
    {
        $checks = $this->id ? Check::where('party_ledger_entry_id', $this->id)->get()
            ->map(fn (Check $c) => trim(($c->check_number ? 'No ' . $c->check_number . ' · ' : '') . 'vade ' . \App\Models\ActivityLog::date($c->due_date) . ' · ' . \App\Models\ActivityLog::money($c->amount)))
            ->implode("\n") : '';

        return \App\Models\ActivityLog::compact([
            'Tür'           => $this->typeLabel(),
            'Tarih'         => \App\Models\ActivityLog::date($this->entry_date),
            'Tutar'         => \App\Models\ActivityLog::money($this->amount),
            'Açıklama'      => $this->description,
            'Ödeme yöntemi' => \App\Models\ActivityLog::PAYMENT_TYPES[$this->payment_type] ?? $this->payment_type,
            'Ödeme günü'    => \App\Models\ActivityLog::date($this->due_date),
            \App\Support\Labels::project() => \App\Models\ActivityLog::name(\App\Models\Project::class, $this->project_id),
            'Anlaşma'       => $this->agreement_id ? Agreement::whereKey($this->agreement_id)->value('title') : null,
            'Çekler'        => $checks,
            'Not'           => $this->notes ?? null,
        ]);
    }

    public const DIRECTION_DEBIT  = 'borc';   // cari borçlanır (bize borcu artar)
    public const DIRECTION_CREDIT = 'alacak'; // cari alacaklanır (bizim borcumuz artar)

    public const DIRECTIONS = [
        self::DIRECTION_DEBIT  => 'Borç',
        self::DIRECTION_CREDIT => 'Alacak',
    ];

    // Çift yönlü hareket tipleri → yön (borç/alacak) buradan türetilir.
    public const TYPE_SALE          = 'satis';          // biz sattık → cari borçlanır
    public const TYPE_SALE_RETURN   = 'satis_iade';     // satış iadesi → cari alacak (borcu azaltır)
    public const TYPE_PURCHASE      = 'alis';           // biz aldık → biz borçlanırız (cari alacak)
    public const TYPE_PURCHASE_RETURN = 'alis_iade';    // alış iadesi → cari borç (borcumuz azalır)
    public const TYPE_COLLECTION    = 'tahsilat';       // para girdi → cari alacak
    public const TYPE_PAYMENT       = 'odeme';          // para çıktı → cari borç
    public const TYPE_CHECK_BOUNCED = 'cek_karsiliksiz'; // çek karşılıksız → tahsilatı geri alan ters borç

    public const TYPES = [
        self::TYPE_SALE          => ['label' => 'Satış',          'direction' => self::DIRECTION_DEBIT],
        self::TYPE_SALE_RETURN   => ['label' => 'Satış İadesi',   'direction' => self::DIRECTION_CREDIT],
        self::TYPE_PURCHASE      => ['label' => 'Alış / Hizmet',  'direction' => self::DIRECTION_CREDIT],
        self::TYPE_PURCHASE_RETURN => ['label' => 'Alış İadesi',  'direction' => self::DIRECTION_DEBIT],
        self::TYPE_COLLECTION    => ['label' => 'Tahsilat',       'direction' => self::DIRECTION_CREDIT],
        self::TYPE_PAYMENT       => ['label' => 'Ödeme',          'direction' => self::DIRECTION_DEBIT],
        self::TYPE_CHECK_BOUNCED => ['label' => 'Karşılıksız Çek', 'direction' => self::DIRECTION_DEBIT],
    ];

    protected $fillable = [
        'party_id',
        'project_id',
        'sale_id',
        'sale_return_id',
        'purchase_id',
        'purchase_return_id',
        'agreement_id',
        'entry_date',
        'due_date',
        'description',
        'type',
        'payment_type',
        'direction',
        'amount',
        'notes',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'due_date' => 'date',
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
        // Çekle yapılan ödeme/tahsilat silinince (cari sayfası, "geri al") çekleri de gider — vadesiz kalıp Çekler'de, hatırlatmada
        // görünmesin (firma 7, 21.10: Seramikpark ödemeleri silindi, 7 çek kaldı). Sözleşme ödemesi de çeklerini böyle siler.
        // Tipi boş cari ilk hareketinden tip alır (27.10; seçilmiş tipe dokunulmaz) — Party::fillKindFromHistory.
        static::created(function (self $entry): void {
            if ($entry->party_id && ($party = Party::find($entry->party_id)) && $party->kind === null) {
                Party::fillKindFromHistory($party);
            }
        });
        static::deleting(function (self $entry): void {
            \App\Models\ActivityLog::deleteQuery(Check::where('party_ledger_entry_id', $entry->id), 'Bağlı ödeme/tahsilat silindiği için');
        });
    }

    /**
     * Avans satırları: açıklamada "avans" geçen para hareketleri. Yalnız "Avans…" ile başlayanlar aranıyordu; açıklama zaten "avans"
     * içerince başa eklenmiyor ("Boyacı Ahmet avansı", Yeni Yapı 19.10) → açık avans 0 sayılıp mahsup akışı hiç çalışmıyordu.
     */
    public function scopeAdvance($query)
    {
        return $query->whereRaw('LOWER(description) LIKE ?', ['%avans%']);
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

    /** Ödeme / tahsilat hangi anlaşma için (boş = anlaşma dışı). Bkz. AgreementBalances. */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    /** Bu tahsilat çekle yapıldıysa bağlı çek (vade/durum ekstrede gösterilebilsin). */
    public function check(): HasOne
    {
        return $this->hasOne(Check::class, 'party_ledger_entry_id');
    }

    /** Bu ödemeye/tahsilata bağlı tüm çekler (bir ödeme birden çok çekle yapılabilir — Faz F). */
    public function checks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Check::class, 'party_ledger_entry_id');
    }
}
