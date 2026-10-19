<?php

namespace App\Services\Whatsapp;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\WhatsappPendingExpense;
use App\Support\Money;
use App\Support\PartyBalances;
use App\Support\PartyStatement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * İade (kullanıcı 19.10: "yem sattım, 3 çuvalını geri aldım" / "Oktay'dan aldığım sütün 20 litresini geri verdim"). İptalden
 * (kayıt hiç olmamış → silinir) ayrıdır: asıl kayıt durur, yanına iade yazılır. Yön bulunan kayıttan gelir: satışsa satış
 * iadesi (stok +, onun borcu −), alışsa alış iadesi (stok −, bizim borcumuz −). Kart + tek onay; "son kaydı sil" geri alır.
 *
 *   Satış / alış belgesi (ürünlü)  → Sale/Purchase::processReturn (stok + cari, panelin İade'si gibi)
 *   Belge, ürünsüz                 → aynı, yalnız tutar
 *   Belgesiz basit Satış/Alış Yaptım → cari "Satış İadesi" / "Alış İadesi" satırı (başlıksız)
 */
final class ReturnDraft
{
    public const KIND = 'return';

    private const TIMEZONE = 'Europe/Istanbul';

    private const DAYS = 60;

    /** @param  array{party?:string,text?:string,date?:string,side?:string,quantity?:float,amount?:float,money_back?:bool}  $r */
    public static function propose(?WhatsappPendingExpense $pending, string $phone, array $r): string
    {
        $like = (new Party)->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $text = $r['text'] ?? null;
        $partyIds = [];
        foreach (array_filter([$r['party'] ?? null, $text]) as $name) {
            $ids = Party::where('name', $like, '%' . $name . '%')->pluck('id')->all();
            if ($ids !== []) {
                $partyIds = $ids;
                $text = $text === $name ? null : $text;
                break;
            }
        }
        if (! empty($r['party']) && $partyIds === []) {
            return 'Carilerde "' . $r['party'] . '" adında biri yok. Adını listedeki gibi yazar mısın? (*cariler* yazarsan listeyi gösteririm)';
        }
        if ($partyIds === [] && $text === null) {
            return 'Hangi kayıttan iade? Kişiyi ya da malı yazar mısın? (ör. "Ahmet\'e sattığım yemden 3 çuval geri geldi")';
        }

        $hits = self::candidates($partyIds, $text, $r['date'] ?? null, $r['side'] ?? null, $like);
        if ($hits->isEmpty()) {
            return 'İade edilecek kaydı bulamadım (son ' . self::DAYS . ' gün). Kimden / kime, hangi mal olduğunu yazar mısın?';
        }
        // Aynı kişiyle hem alış hem satış varsa ve yön söylenmediyse sor (Oktay hem süt satar hem yem alır).
        if (empty($r['side']) && $hits->pluck('side')->unique()->count() > 1) {
            $sale = $hits->firstWhere('side', 'sale');
            $purchase = $hits->firstWhere('side', 'purchase');

            return "İki kayıt var, hangisinden iade?\n1) " . $sale['line'] . "\n2) " . $purchase['line']
                . "\n\"Sattığım\" ya da \"aldığım\" diye yazar mısın?";
        }
        $hit = $hits->first(); // en yeni

        $plan = self::plan($hit, $text, $r);
        if (is_string($plan)) {
            return $plan;
        }

        $pending?->update(['status' => 'superseded']);
        $summary = self::summary($hit, $plan);
        WhatsappPendingExpense::create([
            'phone' => $phone,
            'extracted' => ['kind' => self::KIND] + $plan,
            'summary' => $summary,
            'status' => 'awaiting_confirmation',
        ]);

        return $summary . "\n\nKaydedeyim mi? *evet* / *iptal*";
    }

    /** Onay: asıl kayıt hâlâ yerindeyse iade yazılır (çift onay commitDraft'taki kilitle önlenir). */
    public static function commit(WhatsappPendingExpense $pending): string
    {
        $d = $pending->extracted;
        [$type, $id] = $d['source'];
        $model = match ($type) {
            'sale' => Sale::find($id),
            'purchase' => Purchase::find($id),
            default => PartyLedgerEntry::find($id),
        };
        if (! $model) {
            $pending->update(['status' => 'cancelled']);

            return 'İade edilecek kayıt artık yok (silinmiş olabilir). Yeniden yazar mısın?';
        }

        $date = $d['date'];
        $notes = $d['notes'] ?? null;
        $amount = (float) $d['amount'];
        $partyId = $model->party_id;
        if ($type === 'sale' || $type === 'purchase') {
            $return = $model->processReturn($d['lines'] ?? [], $date, $notes, ($d['manual_amount'] ?? null) ? (float) $d['manual_amount'] : null);
            if (! $return) {
                $pending->update(['status' => 'cancelled']);

                return 'İadeyi yazamadım (tutar ya da miktar eksik). Kaç tane / kaç TL geri geldiğini yazar mısın?';
            }
        } else {
            PartyLedgerEntry::create([
                'party_id' => $partyId,
                'project_id' => $model->project_id,
                'entry_date' => $date,
                'type' => $d['side'] === 'sale' ? PartyLedgerEntry::TYPE_SALE_RETURN : PartyLedgerEntry::TYPE_PURCHASE_RETURN,
                'amount' => $amount,
                'description' => $notes,
            ]);
        }
        // Parası da geri verildi / alındı: para hareketi (bakiye değişmez, ciro/alış yine iade kadar düşer).
        if (! empty($d['money_back']) && $partyId) {
            PartyLedgerEntry::create([
                'party_id' => $partyId,
                'entry_date' => $date,
                'type' => $d['side'] === 'sale' ? PartyLedgerEntry::TYPE_PAYMENT : PartyLedgerEntry::TYPE_COLLECTION,
                'payment_type' => 'cash',
                'amount' => $amount,
                'description' => 'İade parası' . ($notes ? ' — ' . $notes : ''),
            ]);
        }
        $pending->update(['status' => 'confirmed']);

        $party = $partyId ? Party::find($partyId) : null;

        return '✅ İade kaydedildi.' . ($party ? ' ' . PartyBalances::line($party->name, PartyStatement::build($party)['balance']) : '');
    }

    /** Son 60 günün satış/alış belgeleri + belgesiz basit satış/alış satırları, en yeni önce. */
    private static function candidates(array $partyIds, ?string $text, ?string $date, ?string $side, string $like): Collection
    {
        $since = now(self::TIMEZONE)->subDays(self::DAYS)->toDateString();
        $scope = function ($q, string $dateCol) use ($partyIds, $date, $since) {
            $q->where($dateCol, '>=', $since);
            if ($partyIds !== []) {
                $q->whereIn('party_id', $partyIds);
            }
            if ($date) {
                $q->whereDate($dateCol, $date);
            }

            return $q->orderByDesc($dateCol)->orderByDesc('id')->limit(10);
        };
        // Metin (mal adı / açıklama kelimesi): belgede kalem ürünü ya da not, satırda açıklama.
        $docText = fn ($q) => $text === null ? $q : $q->where(fn ($w) => $w->where('notes', $like, '%' . $text . '%')
            ->orWhereHas('lines.product', fn ($p) => $p->where('name', $like, '%' . $text . '%')));
        $day = fn ($d) => Carbon::parse($d)->format('d.m');
        $employees = Party::where('is_employee', true)->pluck('id')->all();

        $hits = collect();
        if ($side !== 'purchase') {
            foreach ($scope($docText(Sale::with(['party', 'lines.product.unit'])), 'sale_date')->get() as $s) {
                $hits->push(['side' => 'sale', 'source' => ['sale', $s->id], 'model' => $s, 'date' => $s->sale_date,
                    'line' => '🧾 Satış — ' . ($s->party?->name ?? 'peşin') . ' — ' . $day($s->sale_date) . ' — ' . self::docItems($s) . Money::format((float) $s->total_amount) . ' ₺']);
            }
        }
        if ($side !== 'sale') {
            foreach ($scope($docText(Purchase::with(['party', 'lines.product.unit'])), 'purchase_date')->get() as $p) {
                $hits->push(['side' => 'purchase', 'source' => ['purchase', $p->id], 'model' => $p, 'date' => $p->purchase_date,
                    'line' => '🛒 Alış — ' . ($p->party?->name ?? 'peşin') . ' — ' . $day($p->purchase_date) . ' — ' . self::docItems($p) . Money::format((float) $p->total_amount) . ' ₺']);
            }
        }
        $types = array_values(array_filter([
            $side !== 'purchase' ? PartyLedgerEntry::TYPE_SALE : null,
            $side !== 'sale' ? PartyLedgerEntry::TYPE_PURCHASE : null,
        ]));
        $ledger = $scope(PartyLedgerEntry::with('party')->whereIn('type', $types)
            ->whereNull('sale_id')->whereNull('purchase_id')->whereNotIn('party_id', $employees)
            ->where(fn ($q) => $q->whereNull('description')->orWhere('description', 'not like', 'Borç kaydı%'))
            ->when($text !== null, fn ($q) => $q->where('description', $like, '%' . $text . '%')), 'entry_date')->get();
        foreach ($ledger as $l) {
            $isSale = $l->type === PartyLedgerEntry::TYPE_SALE;
            $hits->push(['side' => $isSale ? 'sale' : 'purchase', 'source' => ['ledger', $l->id], 'model' => $l, 'date' => $l->entry_date,
                'line' => ($isSale ? '🧾 Satış' : '🛒 Alış') . ' — ' . ($l->party?->name ?? '—') . ' — ' . $day($l->entry_date) . ' — '
                    . ($l->description ? $l->description . ' — ' : '') . Money::format((float) $l->amount) . ' ₺']);
        }

        return $hits->sortByDesc(fn ($h) => Carbon::parse($h['date'])->format('Y-m-d') . sprintf('%012d', $h['model']->id))->values();
    }

    /** Ne, ne kadar geri geldi → kayda yazılacak plan; eksik / aşırı ise soru metni. */
    private static function plan(array $hit, ?string $text, array $r): array|string
    {
        $model = $hit['model'];
        $qty = isset($r['quantity']) && (float) $r['quantity'] > 0 ? (float) $r['quantity'] : null;
        $amount = isset($r['amount']) && (float) $r['amount'] > 0 ? (float) $r['amount'] : null;
        $base = [
            'side' => $hit['side'],
            'source' => $hit['source'],
            'party_id' => $model->party_id,
            'date' => now(self::TIMEZONE)->toDateString(),
            'money_back' => (bool) ($r['money_back'] ?? false),
        ];

        if ($hit['source'][0] === 'ledger') {
            if ($amount === null && $qty !== null) {
                return 'Bu kayıtta birim fiyat yok. Geri gelen ' . self::qty($qty) . ' kaç TL ediyor?';
            }
            $amount ??= (float) $model->amount;
            if ($amount > (float) $model->amount + 0.01) {
                return 'İade tutarı (' . Money::format($amount) . ' ₺) kaydın kendisinden (' . Money::format((float) $model->amount) . ' ₺) büyük. Tutarı kontrol eder misin?';
            }

            return $base + ['amount' => $amount, 'notes' => ($hit['side'] === 'sale' ? 'Satış iadesi' : 'Alış iadesi')
                . ($model->description ? ' — ' . $model->description : '') . ' (WhatsApp)'];
        }

        $lines = $model->lines->filter(fn ($l) => $l->product_id);
        $notes = ($hit['side'] === 'sale' ? 'Satış #' : 'Alış #') . $model->id . ' iadesi (WhatsApp)';
        $left = $model->returnableAmount();
        if ($lines->isEmpty()) {
            $amount ??= $qty === null ? $left : null;
            if ($amount === null) {
                return 'Bu kayıtta ürün/birim fiyat yok. Geri gelen ' . self::qty($qty) . ' kaç TL ediyor?';
            }
            if ($amount > $left + 0.01) {
                return 'Bu kayıttan en çok ' . Money::format($left) . ' ₺ iade edilebilir (önceki iadeler düşüldü). Tutarı kontrol eder misin?';
            }

            return $base + ['amount' => $amount, 'manual_amount' => $amount, 'lines' => [], 'notes' => $notes];
        }

        // Hangi kalem: metin ürüne uyuyorsa o, tek kalemse o; yoksa sor.
        $match = $text !== null ? $lines->filter(fn ($l) => mb_stripos((string) $l->product?->name, $text) !== false) : collect();
        $line = $match->first() ?? ($lines->unique('product_id')->count() === 1 ? $lines->first() : null);
        if (! $line) {
            return 'Bu kayıtta birden çok mal var: ' . $lines->map(fn ($l) => $l->product?->name)->filter()->unique()->implode(', ') . '. Hangisi geri geldi?';
        }
        $product = $line->product;
        $unit = $product?->unit?->code ?? $product?->unit?->name ?? '';
        $returnable = $model->returnableQtyForProduct((int) $line->product_id);
        if ($returnable <= 0) {
            return ($product?->name ?? 'Bu mal') . ' zaten tamamen iade edilmiş.';
        }
        $price = $line->unit_price !== null ? (float) $line->unit_price
            : ((float) $line->quantity > 0 && $line->amount !== null ? round((float) $line->amount / (float) $line->quantity, 2) : null);
        if ($qty === null && $amount !== null && $price) {
            $qty = round($amount / $price, 2);
        }
        $qty ??= $returnable; // miktar söylenmediyse kalanın tamamı (kartta açıkça görünür)
        if ($qty > $returnable + 0.001) {
            return ($product?->name ?? 'Bu mal') . ' için en çok ' . self::qty($returnable, $unit) . ' iade edilebilir. Miktarı kontrol eder misin?';
        }
        if ($amount !== null) {
            $price = round($amount / $qty, 2);
        }
        if ($price === null) {
            return 'Bu kayıtta birim fiyat yok. Geri gelen ' . self::qty($qty, $unit) . ' kaç TL ediyor?';
        }

        return $base + [
            'amount' => $amount ?? round($qty * $price, 2),
            'lines' => [['product_id' => $line->product_id, 'return_qty' => $qty, 'unit_price' => Money::format($price)]],
            'product' => $product?->name, 'quantity' => $qty, 'unit' => $unit,
            'notes' => $notes,
        ];
    }

    private static function summary(array $hit, array $plan): string
    {
        $sale = $plan['side'] === 'sale';
        $party = $plan['party_id'] ? Party::find($plan['party_id']) : null;
        $amount = (float) $plan['amount'];
        $out = [
            '↩️ *' . ($sale ? 'Satış iadesi' : 'Alış iadesi') . '* — ' . ($sale ? 'malı geri aldın' : 'malı geri verdin'),
            'Kayıt: ' . $hit['line'],
            'Geri ' . ($sale ? 'gelen' : 'giden') . ': ' . (isset($plan['product'])
                ? self::qty($plan['quantity'], $plan['unit']) . ' ' . $plan['product'] . ' — ' : '') . Money::format($amount) . ' ₺',
        ];
        if (isset($plan['product'])) {
            $out[] = '📦 Stok: ' . $plan['product'] . ' ' . ($sale ? '+' : '−') . self::qty($plan['quantity'], $plan['unit']);
        }
        if ($party) {
            if ($plan['money_back']) {
                $out[] = '💵 Parası ' . ($sale ? 'ona geri verildi' : 'senden geri alındı') . ' — bakiye değişmez.';
            } else {
                $balance = PartyStatement::build($party)['balance'] + ($sale ? -$amount : $amount);
                $out[] = '📒 Sonra ' . PartyBalances::line($party->name, $balance);
                $out[] = '_Parası geri ' . ($sale ? 'verildiyse' : 'alındıysa') . ' *parası ' . ($sale ? 'verildi' : 'alındı') . '* yaz._';
            }
        } else {
            $out[] = $sale ? '💵 Peşin satıştı — ciro iade kadar düşer.' : '💵 Peşin alıştı — alış iade kadar düşer.';
        }

        return implode("\n", $out);
    }

    private static function docItems($doc): string
    {
        $items = $doc->lines->filter(fn ($l) => $l->product)
            ->map(fn ($l) => self::qty((float) $l->quantity, $l->product->unit?->code ?? $l->product->unit?->name ?? '') . ' ' . $l->product->name)
            ->implode(', ');

        return $items !== '' ? $items . ' — ' : '';
    }

    private static function qty(?float $q, string $unit = ''): string
    {
        if ($q === null) {
            return 'mal';
        }
        $n = floor($q) == $q ? Money::format($q, 0) : Money::format($q);

        return trim($n . ' ' . $unit);
    }
}
