<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Sale extends Model
{
    use Concerns\LogsActivity;

    protected static function booted(): void
    {
        // İşlem geçmişi: alanlar (tarih, cari, not) değişmeden önceki hal — kalemler rebuildFromLines'ta kurulunca yazılır.
        static::updating(fn (self $sale) => $sale->activityRemember());
    }

    /** İşlem geçmişi: ekleme/düzenleme rebuildFromLines sonunda (kalemler + toplam kurulunca) yazılır; silme kendiliğinden. */
    private ?array $activityBefore = null;

    private ?ActivityLog $activityRow = null;

    public function activityAuto(): bool
    {
        return false;
    }

    public function activityAction(): string
    {
        return $this->party_id ? 'Satış' : 'Peşin satış';
    }

    public function activityAmount(): ?float
    {
        return (float) $this->total_amount;
    }

    public function activitySummary(): ?string
    {
        return $this->activityLines() ?: $this->notes;
    }

    private function activityLines(): string
    {
        if (! $this->id) {
            return '';
        }

        return StockMovement::where('sale_id', $this->id)->whereNull('sale_return_id')->with('product.unit')->get()
            ->map(fn (StockMovement $m) => \App\Support\StockUsage::quantity((float) $m->quantity) . ' ' . ($m->product?->unit?->name ? $m->product->unit->name . ' ' : '')
                . ($m->product?->name ?? 'Ürün') . ($m->amount !== null ? ' · ' . \App\Models\ActivityLog::money($m->amount) : ''))
            ->implode("\n");
    }

    public function activityDetails(): array
    {
        return \App\Models\ActivityLog::compact([
            'Belge'         => 'Satış #' . $this->id,
            'Tarih'         => \App\Models\ActivityLog::date($this->sale_date),
            'Kişi / Firma'  => $this->party_id ? \App\Models\ActivityLog::name(Party::class, $this->party_id) : 'Peşin (kişisiz)',
            'Ürünler'       => $this->activityLines(),
            'Ürünsüz tutar' => (float) $this->manual_amount > 0 ? \App\Models\ActivityLog::money($this->manual_amount) : null,
            'Toplam'        => \App\Models\ActivityLog::money($this->total_amount),
            'Nakit'         => (float) $this->cash_amount > 0 ? \App\Models\ActivityLog::money($this->cash_amount) : null,
            'Kart / banka'  => (float) $this->bank_amount > 0 ? \App\Models\ActivityLog::money($this->bank_amount) : null,
            \App\Support\Labels::project() => \App\Models\ActivityLog::name(\App\Models\Project::class, $this->project_id),
            'Not'           => $this->notes,
        ]);
    }

    /** Satış silinince iadeleri veritabanında kendiliğinden silinir — önce yaz. */
    public function activityBeforeDelete(?string $note = null): void
    {
        foreach (SaleReturn::where('sale_id', $this->id)->get() as $return) {
            \App\Models\ActivityLog::record($return, 'deleted', note: $note ?? 'Satış #' . $this->id . ' silindiği için');
        }
    }

    /** Düzenlemeden önceki hal (alanlar güncellenmeden ya da kalemler silinmeden önce). */
    private function activityRemember(): void
    {
        if ($this->activityBefore === null && $this->exists && ! $this->wasRecentlyCreated) {
            $this->activityBefore = ['details' => $this->activityOriginal()->activityDetails(), 'amount' => (float) $this->getRawOriginal('total_amount')];
        }
    }

    /** Kalemler ve toplam kurulunca: yeni satışsa "eklendi", değilse "düzenlendi" (değişen yoksa satır yok). */
    private function activityWrite(): void
    {
        if ($this->activityRow) {
            // Aynı işin devamı (peşin satışta nakit/kart dağılımı) — az önce yazılan satır son hali alır.
            $this->activityRow->update(['details' => $this->activityDetails(), 'amount' => $this->activityAmount(), 'summary' => mb_substr((string) $this->activitySummary(), 0, 255) ?: null]);

            return;
        }
        $this->activityRow = $this->activityBefore === null
            ? \App\Models\ActivityLog::record($this, 'created')
            : \App\Models\ActivityLog::record($this, 'updated', $this->activityBefore['details'], beforeAmount: $this->activityBefore['amount']);
        $this->activityBefore = null;
    }

    use HasFactory;

    protected $fillable = [
        'party_id',
        'project_id',
        'sale_date',
        'total_amount',
        'manual_amount',
        'cash_amount',
        'bank_amount',
        'notes',
    ];

    protected $casts = [
        'sale_date'     => 'date',
        'total_amount'  => 'decimal:2',
        'manual_amount' => 'decimal:2',
        'cash_amount'   => 'decimal:2',
        'bank_amount'   => 'decimal:2',
    ];

    /** Peşin (carisiz) satış: cariye hiçbir şey yazılmaz, para alınmış sayılır. */
    public function isCash(): bool
    {
        return $this->party_id === null;
    }

    /** Peşin satışta ödeme şekli: "Nakit", "Kart" ya da "Nakit + Kart". */
    public function paymentLabel(): ?string
    {
        if (! $this->isCash()) {
            return null;
        }
        $cash = (float) $this->cash_amount > 0;
        $bank = (float) $this->bank_amount > 0;

        return match (true) {
            $cash && $bank => 'Nakit + Kart',
            $bank          => 'Kart',
            default        => 'Nakit',
        };
    }

    /** Fişte / listede müşteri adı; carisizde "Peşin müşteri". */
    public function customerLabel(): string
    {
        return $this->party?->name ?? 'Peşin müşteri';
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Satış kalemleri = stok çıkışları (out/sale). */
    public function lines(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'sale_id')
            ->where('reason', StockMovement::REASON_SALE);
    }

    /** İade hareketleri = stok girişleri (in/return). */
    public function returns(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'sale_id')
            ->where('reason', StockMovement::REASON_RETURN);
    }

    /** İade başlıkları (her "İade Al" işlemi bir parti). */
    public function saleReturns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    /** Satışın cari BORÇ kaydı (iade alacak kayıtları hariç). */
    public function ledgerEntry(): HasOne
    {
        return $this->hasOne(PartyLedgerEntry::class, 'sale_id')
            ->where('type', PartyLedgerEntry::TYPE_SALE);
    }

    /** İade alınabilecek kalan tutar (toplam − önceki iadeler). */
    public function returnableAmount(): float
    {
        return max(0.0, (float) $this->total_amount - (float) $this->saleReturns()->sum('total_amount'));
    }

    /** Bir ürün için: satılan − iade edilen = iade edilebilecek kalan. */
    public function returnableQtyForProduct(int $productId): float
    {
        $sold = (float) $this->lines()->where('product_id', $productId)->sum('quantity');
        $returned = (float) $this->returns()->where('product_id', $productId)->sum('quantity');

        return max(0.0, $sold - $returned);
    }

    /** İade alınabilecek (henüz iade edilmemiş) kalem var mı? */
    public function hasReturnableItems(): bool
    {
        return (float) $this->lines()->sum('quantity') > (float) $this->returns()->sum('quantity');
    }

    /**
     * İade Al formu için ürün-bazlı satırlar: satılan, önce iade, kalan.
     * Yalnız kalan > 0 olan ürünler döner.
     */
    public function returnFormLines(): array
    {
        return $this->lines()
            ->with('product')
            ->get()
            ->groupBy('product_id')
            ->map(function ($group) {
                $first = $group->first();
                $productId = (int) $first->product_id;
                $sold = (float) $group->sum('quantity');
                $returned = (float) $this->returns()->where('product_id', $productId)->sum('quantity');
                $remaining = max(0.0, $sold - $returned);

                return [
                    'product_id'      => $productId,
                    'product_label'   => $first->product?->name ?? ('#' . $productId),
                    'sold'            => $sold,
                    'returned_before' => $returned,
                    'remaining'       => $remaining,
                    'unit_price'      => $first->unit_price !== null ? Money::format((float) $first->unit_price) : null,
                    'return_qty'      => 0,
                ];
            })
            ->filter(fn ($row) => $row['remaining'] > 0)
            ->values()
            ->toArray();
    }

    /**
     * Kalemleri stok çıkışına + cari borca dönüştürür (tek doğruluk noktası).
     * Düzenlemede eski kalemler silinip yeniden yazılır; stok/cari otomatik güncel.
     * $lines: [['product_id'=>, 'quantity'=>, 'unit_price'=> (Money::store'lu), 'amount'=>], ...]
     */
    public function rebuildFromLines(array $lines): void
    {
        $this->activityRemember(); // işlem geçmişi: düzenlemeden önceki hal (kalemler silinmeden)

        // Eski kalemleri temizle (stoğu geri verir; net etkiyi baştan kurarız).
        // Düzenleme formu anlaşma bağını taşımaz: aynı ürünün eski bağı korunur (teslimat anlaşmadan kopmasın).
        $agreementLinks = $this->lines()->whereNotNull('agreement_item_id')->pluck('agreement_item_id', 'product_id');
        $this->lines()->delete();

        $total = 0.0;

        foreach ($lines as $line) {
            $productId = $line['product_id'] ?? null;
            $quantity  = (float) ($line['quantity'] ?? 0);
            if (! $productId || $quantity <= 0) {
                continue;
            }

            // Money::parse "185,50" / "185.50" / float — hepsini çözer.
            $unitPrice = blank($line['unit_price'] ?? null) ? null : Money::parse($line['unit_price']);
            $lineAmount = blank($line['amount'] ?? null) ? null : Money::parse($line['amount']);
            $amount = $lineAmount
                ?? ($unitPrice !== null ? round($quantity * $unitPrice, 2) : null);

            StockMovement::create([
                'product_id'    => $productId,
                'sale_id'       => $this->id,
                'direction'     => StockMovement::DIRECTION_OUT,
                'reason'        => StockMovement::REASON_SALE,
                'quantity'      => $quantity,
                'unit_price'    => $unitPrice,
                'amount'        => $amount,
                'party_id'      => $this->party_id,
                'project_id'    => $this->project_id,
                'movement_date' => $this->sale_date,
                'agreement_item_id' => $line['agreement_item_id'] ?? $agreementLinks[$productId] ?? null, // anlaşma teslimatı
            ]);

            $total += (float) ($amount ?? 0);
        }

        // Ürünsüz tutar (toplu ciro, lokantaya ekmek): kalemlere eklenir. Eski satışlarda boş → toplam aynı.
        $total += (float) ($this->manual_amount ?? 0);

        $this->forceFill(['total_amount' => $total])->saveQuietly();

        // Peşin satış düzenlenince nakit/kart dağılımı yeni toplama uyar (aynı ödeme şekli korunur).
        if ($this->isCash()) {
            $this->payWith($this->paymentMethod(), (float) $this->cash_amount);
        }

        $this->syncLedger($total);
        $this->activityWrite();
    }

    /** Peşin satışın ödeme şekli: cash | card | mixed (dağılımdan). */
    public function paymentMethod(): string
    {
        $cash = (float) $this->cash_amount > 0;
        $bank = (float) $this->bank_amount > 0;

        return match (true) {
            $cash && $bank => 'mixed',
            $bank          => 'card',
            default        => 'cash',
        };
    }

    /**
     * Peşin satışın nakit/kart dağılımını toplamdan kurar. mixed: nakit kısmı verilir, kalan kart.
     * Carili satışta dağılım tutulmaz (tahsilat ayrıca girilir).
     */
    public function payWith(string $method, float $cashPart = 0.0): void
    {
        if (! $this->isCash()) {
            return;
        }

        $total = (float) $this->total_amount;
        [$cash, $bank] = match ($method) {
            'card'  => [0.0, $total],
            'mixed' => [min(max(0.0, $cashPart), $total), max(0.0, $total - min(max(0.0, $cashPart), $total))],
            default => [$total, 0.0],
        };

        $this->forceFill(['cash_amount' => $cash, 'bank_amount' => $bank])->saveQuietly();
        if ($this->activityRow) {
            $this->activityWrite(); // aynı işin devamı: işlem geçmişi satırı nakit/kart dağılımını alır
        }
    }

    /**
     * İade işle: her ürün için giren/return stok hareketi + satış iadesi (alacak) cari kaydı.
     * $lines: [['product_id'=>, 'return_qty'=>, 'unit_price'=> (Money'li)], ...]
     * "Sevkte satış" modeli: stok geri +, cari borç − (alacak).
     */
    public function processReturn(array $lines, string $date, ?string $notes = null, ?float $manualAmount = null): ?SaleReturn
    {
        // Önce geçerli kalemleri süz + tutarları hesapla.
        $prepared = [];
        $totalReturn = 0.0;

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $qty = (float) ($line['return_qty'] ?? 0);
            if (! $productId || $qty <= 0) {
                continue;
            }

            $unitPrice = blank($line['unit_price'] ?? null) ? null : Money::parse($line['unit_price']);
            $amount = $unitPrice !== null ? round($qty * $unitPrice, 2) : null;

            $prepared[] = compact('productId', 'qty', 'unitPrice', 'amount');
            $totalReturn += (float) ($amount ?? 0);
        }

        // Ürünsüz satış belgesinin iadesi (WhatsApp "sattığım … geri geldi"): yalnız tutar.
        $totalReturn += max(0.0, (float) $manualAmount);
        if (empty($prepared) && $totalReturn <= 0) {
            return null;
        }

        // İade başlığı (parti) — geri almak için tek nokta.
        $return = $this->saleReturns()->create([
            'party_id'     => $this->party_id,
            'project_id'   => $this->project_id,
            'return_date'  => $date,
            'total_amount' => $totalReturn,
            'notes'        => $notes,
        ]);

        foreach ($prepared as $p) {
            StockMovement::create([
                'product_id'     => $p['productId'],
                'sale_id'        => $this->id,
                'sale_return_id' => $return->id,
                'direction'      => StockMovement::DIRECTION_IN,
                'reason'         => StockMovement::REASON_RETURN,
                'quantity'       => $p['qty'],
                'unit_price'     => $p['unitPrice'],
                'amount'         => $p['amount'],
                'party_id'       => $this->party_id,
                'project_id'     => $this->project_id,
                'movement_date'  => $date,
                'notes'          => $notes,
                'agreement_item_id' => $this->lines()->where('product_id', $p['productId'])->value('agreement_item_id'), // iade anlaşmadan da düşer
            ]);
        }

        // Peşin (carisiz) satışın iadesi cariye yazılmaz; stok yine geri girer.
        if ($totalReturn > 0 && $this->party_id) {
            PartyLedgerEntry::create([
                'party_id'       => $this->party_id,
                'project_id'     => $this->project_id,
                'sale_id'        => $this->id,
                'sale_return_id' => $return->id,
                'entry_date'     => $date,
                'type'           => PartyLedgerEntry::TYPE_SALE_RETURN,
                'amount'         => $totalReturn,
                'description'    => 'Satış #' . $this->id . ' iadesi',
            ]);
        }

        \App\Models\ActivityLog::record($return, 'created');

        return $return;
    }

    /** Satış tutarını cari borç (satis) kaydına yazar/günceller. */
    protected function syncLedger(float $total): void
    {
        // Peşin satış cariye yazılmaz.
        if (! $this->party_id) {
            $this->ledgerEntry()->delete();

            return;
        }

        $this->ledgerEntry()->updateOrCreate(
            ['sale_id' => $this->id],
            [
                'party_id'    => $this->party_id,
                'project_id'  => $this->project_id,
                'entry_date'  => $this->sale_date,
                'type'        => PartyLedgerEntry::TYPE_SALE,
                'amount'      => $total,
                'description' => 'Direkt satış #' . $this->id,
            ],
        );
    }
}
