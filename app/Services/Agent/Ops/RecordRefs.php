<?php

namespace App\Services\Agent\Ops;

use App\Models\Check;
use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Purchase;
use App\Models\Sale;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Kayıt referansları ("ledger:12", "expense:4", "sale:7", "purchase:3") — okuma araçlarının verdiği ref'ler. Ajan silme/düzeltme
 * önerirken yalnız bunları kullanır; burada kayda çevrilir, silinebilir mi bakılır, kartta görünecek satır üretilir.
 */
final class RecordRefs
{
    public const TYPES = ['ledger' => PartyLedgerEntry::class, 'expense' => Expense::class, 'sale' => Sale::class, 'purchase' => Purchase::class];

    /** @return array{0:string,1:int}|null */
    public static function parse(mixed $ref): ?array
    {
        return is_string($ref) && preg_match('/^(ledger|expense|sale|purchase):(\d+)$/', trim($ref), $m) ? [$m[1], (int) $m[2]] : null;
    }

    public static function find(string $ref): ?Model
    {
        $p = self::parse($ref);

        return $p ? (self::TYPES[$p[0]])::find($p[1]) : null;
    }

    /** Silinemiyorsa nedeni (ajan kullanıcıya söyler); silinebilirse null. */
    public static function blocker(string $ref): ?string
    {
        $p = self::parse($ref);
        if (! $p) {
            return '"' . mb_substr((string) $ref, 0, 40) . '" geçerli bir ref değil (ledger:ID, expense:ID, sale:ID, purchase:ID — okuma araçlarından al).';
        }
        $model = self::find($ref);
        if (! $model) {
            return $ref . ' bulunamadı (silinmiş olabilir).';
        }
        if ($model instanceof PartyLedgerEntry) {
            foreach (['sale_id' => 'sale', 'purchase_id' => 'purchase'] as $column => $type) {
                if ($model->{$column}) {
                    return $ref . ' bir belgenin satırı — tek başına silinmez; belgeyi sil: ' . $type . ':' . $model->{$column} . '.';
                }
            }
            if ($model->sale_return_id || $model->purchase_return_id) {
                return $ref . ' bir iade satırı — panelden geri alınır.';
            }
            if ($model->type === PartyLedgerEntry::TYPE_CHECK_BOUNCED || Check::where('bounce_entry_id', $model->id)->exists()) {
                return $ref . ' karşılıksız çek satırı — Çekler ekranından düzeltilir.';
            }
            if (Check::where('party_ledger_entry_id', $model->id)->exists()) {
                return $ref . ' çekle yapılmış — çekleriyle birlikte panelden (Çekler / kişi sayfası) silinir.';
            }
        }
        if ($model instanceof Sale && \App\Models\SaleReturn::where('sale_id', $model->id)->exists()) {
            return $ref . ' satışının iadesi var — önce iade geri alınmalı (panel).';
        }
        if ($model instanceof Purchase && \App\Models\PurchaseReturn::where('purchase_id', $model->id)->exists()) {
            return $ref . ' alışının iadesi var — önce iade geri alınmalı (panel).';
        }

        return null;
    }

    public static function partyId(Model $model): ?int
    {
        return $model->party_id ? (int) $model->party_id : null;
    }

    /** Kartta ve ajan sonucunda görünen tek satır: "08.11 Ödeme 120.000,00 ₺ — Gürbüz Toprak (açıklama)". */
    public static function line(Model $model): string
    {
        [$date, $label, $amount, $text] = match (true) {
            $model instanceof PartyLedgerEntry => [$model->entry_date, $model->typeLabel(), $model->amount, $model->description],
            $model instanceof Expense => [$model->expense_date, 'Gider', $model->amount, $model->description],
            $model instanceof Sale => [$model->sale_date, 'Satış belgesi', $model->total_amount, $model->notes],
            $model instanceof Purchase => [$model->purchase_date, 'Alış belgesi', $model->total_amount, $model->notes],
            default => [null, 'Kayıt', 0, null],
        };
        // Devreden borç/alacak satırı ekstrede "Alış/Satış" türüyle durur; müşteriye kendi adıyla gösterilir.
        if ($model instanceof PartyLedgerEntry && preg_match('/^(Borç kaydı|Alacak kaydı)/u', (string) $text, $m)) {
            [$label, $text] = [$m[1], null];
        }
        $party = self::partyId($model) ? Party::whereKey(self::partyId($model))->value('name') : null;

        return trim(($date ? \Illuminate\Support\Carbon::parse($date)->format('d.m') . ' ' : '') . $label . ' ' . Money::format((float) $amount) . ' ₺'
            . ($party ? ' — ' . $party : '') . ($text ? ' (' . mb_substr(trim((string) $text), 0, 40) . ')' : ''));
    }

    /** Değişiklik izi (parmak izi): kayıt onay beklerken değişti/silindi mi. */
    public static function stamp(string $ref): string
    {
        $model = self::find($ref);

        return $ref . '@' . ($model ? ($model->updated_at?->format('YmdHis') . ':' . ($model->amount ?? $model->total_amount ?? '')) : 'yok');
    }
}
