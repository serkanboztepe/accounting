<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Party extends Model
{
    use Concerns\LogsActivity;

    /** Silinirken ayrıntıya ekstre de girsin (cari sayfası "Sil" — kullanıcı 21.10: "cari silinince tüm detay loga"). */
    private bool $activityFull = false;

    public function activityAction(): string
    {
        return $this->is_employee ? 'Personel' : 'Cari';
    }

    public function activityPartyId(): ?int
    {
        return $this->id;
    }

    public function activityPartyName(): ?string
    {
        return $this->name;
    }

    public function activitySummary(): ?string
    {
        return $this->name;
    }

    public function activityDetails(): array
    {
        $details = [
            'Ad'        => $this->name,
            'Telefon'   => $this->phone,
            'Not'       => $this->notes,
            'Personel'  => $this->is_employee ? 'Evet' : null,
            'Maaş'      => \App\Models\ActivityLog::money($this->salary_amount),
            'Maaş günü' => $this->salary_day ? 'Ayın ' . $this->salary_day . "'i" : null,
        ];
        if ($this->activityFull) {
            $statement = \App\Support\PartyStatement::build($this);
            $details['Bakiye'] = \App\Support\PartyBalances::line($this->name, $statement['balance']);
            $details['Ekstre'] = collect($statement['rows'])->map(fn ($r) => $r['date'] . ' · ' . $r['label'] . ' · ' . $r['desc']
                . ($r['borc'] > 0 ? ' · borç ' . \App\Models\ActivityLog::money($r['borc']) : '') . ($r['alacak'] > 0 ? ' · alacak ' . \App\Models\ActivityLog::money($r['alacak']) : ''))
                ->implode("\n");
            $details['Carisiz kalan giderler'] = Expense::where('party_id', $this->id)->get()
                ->map(fn (Expense $e) => \App\Models\ActivityLog::date($e->expense_date) . ' · ' . ($e->description ?: 'Gider') . ' · ' . \App\Models\ActivityLog::money($e->amount))->implode("\n");
        }

        return \App\Models\ActivityLog::compact($details);
    }

    /**
     * Cari silinince veritabanı hareketlerini, satışlarını, alışlarını (ve iadelerini) kendiliğinden siler — olay tetiklenmez.
     * Önce her birini "silindi" yaz; carinin kendi satırına ekstrenin tamamı girer.
     */
    public function activityBeforeDelete(): void
    {
        $this->activityFull = true;
        $note = 'Cari silindiği için';
        foreach (PartyLedgerEntry::where('party_id', $this->id)->get() as $entry) {
            if (! $entry->activitySkipped()) {
                \App\Models\ActivityLog::record($entry, 'deleted', note: $note);
            }
        }
        foreach (Sale::where('party_id', $this->id)->get() as $sale) {
            $sale->activityBeforeDelete($note);
            \App\Models\ActivityLog::record($sale, 'deleted', note: $note);
        }
        foreach (Purchase::where('party_id', $this->id)->get() as $purchase) {
            $purchase->activityBeforeDelete($note);
            \App\Models\ActivityLog::record($purchase, 'deleted', note: $note);
        }
    }

    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'notes',
        'kind',
        'is_employee',
        'salary_amount',
        'salary_day',
        'salary_auto',
    ];

    protected $casts = [
        'is_employee'   => 'boolean',
        'salary_amount' => 'float',
        'salary_day'    => 'integer',
        'salary_auto'   => 'boolean',
    ];

    // Cari tipi (27.10, "Bu cariyle nasıl çalışıyorsun?"). Boş = bilinmiyor → bugünkü görünüm (tüm düğmeler).
    public const KIND_CUSTOMER = 'customer';
    public const KIND_SUPPLIER = 'supplier';
    public const KIND_BOTH = 'both';
    public const KIND_EMPLOYEE = 'employee';

    public const KINDS = [
        self::KIND_CUSTOMER => 'Ödeme aldığım',
        self::KIND_SUPPLIER => 'Ödeme yaptığım',
        self::KIND_BOTH     => 'İkisi de',
        self::KIND_EMPLOYEE => 'Personel',
    ];

    /**
     * Seçim kutusunun alt satırı (28.10: radyo yerine kutu düğme). Adlar paranın yönüne göre — "alım" mal çağrıştırıyordu, oysa usta,
     * taşeron, ev sahibi de bu gruba girer (kullanıcı 28.10).
     */
    public const KIND_HINTS = [
        self::KIND_CUSTOMER => 'müşteri',
        self::KIND_SUPPLIER => 'malcı, usta, taşeron, kira',
        self::KIND_BOTH     => 'hem alır hem öderim',
        self::KIND_EMPLOYEE => 'maaş ve avans',
    ];

    /** Personel işareti (is_employee) ile tip senkron: biri değişince öteki de (WhatsApp "personelim" is_employee'yi açar). */
    protected static function booted(): void
    {
        static::saving(function (self $party): void {
            if ($party->isDirty('kind')) {
                $party->is_employee = $party->kind === self::KIND_EMPLOYEE;
            } elseif ($party->isDirty('is_employee')) {
                $party->kind = $party->is_employee ? self::KIND_EMPLOYEE : ($party->kind === self::KIND_EMPLOYEE ? null : $party->kind);
            }
        });
    }

    /** Satış tarafı düğmeleri (Satış Yaptım, Ödeme Aldım, satış anlaşması). */
    public function sellsTo(): bool
    {
        return in_array($this->kind, [null, self::KIND_CUSTOMER, self::KIND_BOTH], true);
    }

    /** Alış tarafı düğmeleri (Alım Yaptım, Ödeme Yaptım, alış anlaşması). */
    public function buysFrom(): bool
    {
        return in_array($this->kind, [null, self::KIND_SUPPLIER, self::KIND_BOTH], true);
    }

    /**
     * Tipi boş cariye ilk hareketinden tip (PartyLedgerEntry::created): alış/ödeme → tedarikçi, satış/tahsilat → müşteri; öteki
     * yön de olmuşsa "ikisi de". Kullanıcının seçtiği tipin üzerine YAZILMAZ — yalnız boşsa (WhatsApp'ta açılan cari soru sormaz).
     */
    public static function fillKindFromHistory(self $party): void
    {
        if ($party->kind !== null || $party->is_employee) {
            return;
        }
        $kind = self::kindFromHistory($party->id);
        if ($kind !== null) {
            $party->forceFill(['kind' => $kind])->saveQuietly();
        }
    }

    public static function kindFromHistory(int $partyId): ?string
    {
        $types = PartyLedgerEntry::where('party_id', $partyId)->distinct()->pluck('type')->all();
        $agreements = Agreement::where('party_id', $partyId)->distinct()->pluck('direction')->all();
        $buys = array_intersect($types, [PartyLedgerEntry::TYPE_PURCHASE, PartyLedgerEntry::TYPE_PAYMENT, PartyLedgerEntry::TYPE_PURCHASE_RETURN]) !== []
            || in_array(Agreement::DIRECTION_PURCHASE, $agreements, true)
            || Contract::withoutGlobalScopes()->where('party_id', $partyId)->where(fn ($q) => $q->whereNull('direction')->orWhere('direction', '!=', 'sale'))->exists()
            || Expense::where('party_id', $partyId)->exists();
        $sells = array_intersect($types, [PartyLedgerEntry::TYPE_SALE, PartyLedgerEntry::TYPE_COLLECTION, PartyLedgerEntry::TYPE_SALE_RETURN]) !== []
            || in_array(Agreement::DIRECTION_SALE, $agreements, true)
            || Contract::withoutGlobalScopes()->where('party_id', $partyId)->where('direction', 'sale')->exists();

        return match (true) {
            $buys && $sells => self::KIND_BOTH,
            $buys => self::KIND_SUPPLIER,
            $sells => self::KIND_CUSTOMER,
            default => null,
        };
    }

    /**
     * Gösterimde fazla boşluk temizlenir ("ÜMİT KAYA " / "RAMADA  OTEL" → "ÜMİT KAYA" / "RAMADA OTEL").
     * İçeri aktarılan verilerde vardı; veritabanındaki kayıt değişmez (yalnız okunuş).
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn (?string $value) => $value === null ? null : preg_replace('/\s+/u', ' ', trim($value)));
    }

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

    public function agreements(): HasMany
    {
        return $this->hasMany(Agreement::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(PartyLedgerEntry::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
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
