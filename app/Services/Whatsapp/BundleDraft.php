<?php

namespace App\Services\Whatsapp;

use App\Models\AssistantMemory;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Sale;
use App\Models\WhatsappPendingExpense;
use App\Support\Money;
use App\Support\Phone;

/**
 * Düzeltme soruları (kullanıcı 19.10, Oktay yenilik mesajı: "tek tek soralım"). Bizim hazırladığımız düzeltmeler müşteriye SIRAYLA
 * sorulur: "evet" o maddeyi yapar, "hayır" atlar, sonra sıradaki sorulur. Başka her mesaj normal işlenir, sıra bekler — yanlış
 * anladıysak hiçbir şey kendiliğinden olmaz ("evet" de boşa düşüp 👍 ile kalmaz; ekmekte "tamam" dendi, hiçbir şey olmadı).
 * Yalnız güvenli işler: düzenli gider kur, gider yaz, ürünün otomatik düşüşünü ayarla, satış sil (iadesizse), hafıza notunu sil.
 *
 *   BundleDraft::offer($phone, $actions, $questions) → ilk soru metni (onay bekleyen kayıt; 24 saat, "yarım kalan" hatırlatması yok)
 */
final class BundleDraft
{
    public const KIND = 'bundle';

    /** @param  list<string>  $questions  her işin sorusu ("Çoban ekmeğini her gün 34,60 ₺ gider olarak kurayım mı?") */
    public static function offer(string $phone, array $actions, array $questions): string
    {
        $pending = WhatsappPendingExpense::create([
            'phone' => Phone::whatsappAddress($phone),
            'extracted' => ['kind' => self::KIND, 'actions' => array_values($actions), 'questions' => array_values($questions), 'step' => 0, 'amount' => 0],
            'summary' => '',
            'status' => 'awaiting_confirmation',
            // Cevap geç gelebilir: 24 saat etkin (followed_up_at), "yarım kalan" hatırlatması gönderilmez (follow_ups = MAX).
            'followed_up_at' => now(),
            'follow_ups' => DraftFollowUp::MAX,
        ]);

        return self::ask($pending);
    }

    /** Sıradaki soru (özet de bu — "kaydedeyim mi?" hatırlatmaları aynı soruyu gösterir). */
    private static function ask(WhatsappPendingExpense $pending): string
    {
        $d = $pending->extracted;
        $total = count($d['questions']);
        $text = '🛠️ *' . ($d['step'] + 1) . '/' . $total . '* ' . $d['questions'][$d['step']] . "\n*evet* / *hayır*";
        $pending->update(['summary' => $text, 'followed_up_at' => now()]);

        return $text;
    }

    /** Cevap: "evet" → bu madde yapılır, "hayır" → atlanır; ikisinde de sıradaki sorulur. Başka mesaj → null (normal işlenir, sıra bekler). */
    public static function answer(WhatsappPendingExpense $pending, string $body, bool $assent, bool $cancel): ?string
    {
        if (! $assent && ! $cancel) {
            return null;
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($pending, $assent) {
            $locked = WhatsappPendingExpense::whereKey($pending->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'awaiting_confirmation') {
                return 'Bu soru artık açık değil.';
            }
            $d = $locked->extracted;
            $line = $assent ? (self::run($d['actions'][$d['step']] ?? [], $locked->phone) ?? 'Yapılacak bir şey kalmamış (zaten düzeltilmiş olabilir).')
                : 'Tamam, buna dokunmadım.';
            $d['step']++;
            if ($d['step'] >= count($d['actions'])) {
                $locked->update(['extracted' => $d, 'status' => 'confirmed']);

                return ($assent ? '✅ ' : '') . $line . "\n\nHepsi bu kadar, teşekkürler 🙏";
            }
            $locked->update(['extracted' => $d]);

            return ($assent ? '✅ ' : '') . $line . "\n\n" . self::ask($locked);
        });
    }

    /** Onay yolundan gelirse (commitDraft) yalnız sıradaki madde — "evet" ile aynı. */
    public static function commit(WhatsappPendingExpense $pending): string
    {
        return self::answer($pending, 'evet', true, false) ?? '';
    }

    private static function run(array $a, string $phone): ?string
    {
        return match ($a['op'] ?? null) {
            'recurring' => self::recurring($a, $phone),
            'expense' => self::expense($a),
            'product_usage' => self::productUsage($a),
            'delete_sale' => self::deleteSale($a),
            'forget_memory' => self::forget($a),
            default => null,
        };
    }

    private static function recurring(array $a, string $phone): string
    {
        [$text] = RecurringDraft::commit($a['data'], $phone);

        return trim(strtok(str_replace('✅ ', '', $text), "\n")) . (str_contains($text, 'Bugünkü yazıldı') ? ' (bugünkü yazıldı)' : '');
    }

    private static function expense(array $a): string
    {
        Expense::create([
            'expense_category_id' => $a['category_id'] ?? null,
            'expense_date' => $a['date'],
            'amount' => $a['amount'],
            'payment_status' => 'paid',
            'description' => $a['description'],
            'notes' => 'Toplu düzeltme (WhatsApp onayı)',
        ]);

        return \Illuminate\Support\Carbon::parse($a['date'])->locale('tr')->translatedFormat('j F') . ' ' . $a['description'] . ': ' . Money::format((float) $a['amount']) . ' ₺ gider yazıldı';
    }

    private static function productUsage(array $a): ?string
    {
        $p = Product::find($a['product_id'] ?? 0);
        if (! $p) {
            return null;
        }
        $p->update(['auto_usage' => true, 'daily_usage' => $a['amount'], 'usage_every_days' => ($a['every_days'] ?? 1) > 1 ? $a['every_days'] : null]);

        return $p->name . ': artık ' . (($a['every_days'] ?? 1) > 1 ? 'her ' . $a['every_days'] . ' günde ' : 'günde ') . rtrim(rtrim(Money::format((float) $a['amount']), '0'), ',') . ' düşülür';
    }

    private static function deleteSale(array $a): ?string
    {
        $sale = Sale::with('party')->find($a['id'] ?? 0);
        if (! $sale || $sale->saleReturns()->exists()) {
            return null;
        }
        $line = ($sale->party?->name ?? 'Peşin') . "'a " . $sale->sale_date->locale('tr')->translatedFormat('j F') . ' satışı (' . Money::format((float) $sale->total_amount) . ' ₺) iptal edildi';
        $sale->delete(); // stok ve cari satırı birlikte gider (cascade)

        return $line;
    }

    private static function forget(array $a): ?string
    {
        $m = AssistantMemory::where('status', 'active')->find($a['id'] ?? 0);
        if (! $m) {
            return null;
        }
        $m->update(['status' => 'deleted']);

        return 'Hafızadaki eski not silindi: "' . mb_strimwidth($m->fact, 0, 60, '…') . '"';
    }
}
