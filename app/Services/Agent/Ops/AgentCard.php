<?php

namespace App\Services\Agent\Ops;

use App\Models\Party;
use App\Models\Project;
use App\Services\Whatsapp\DraftGaps;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Onay kartı — KOD yazar (plan v5 §2): tutarlar Money ile, adlar veritabanından, bakiye deneme kaydından. Ajanın kendi yazdığı
 * metin karta giremez; müşteri neyi onayladığını rakamıyla burada görür. Kısa: işlem başına bir satır + bakiye + onay satırı.
 */
final class AgentCard
{
    private const PAYMENT_LABELS = ['cash' => 'nakit', 'eft' => 'EFT', 'bank_transfer' => 'havale', 'check' => 'çek', 'promissory_note' => 'senet'];

    /** Kayıt işleminin kart satırı. */
    public static function recordLine(array $d): string
    {
        $kind = $d['kind'] ?? ExpenseExtractor::KIND_EXPENSE;
        $word = match (true) {
            $kind === ExpenseExtractor::KIND_DEBT_NOTE => ($d['debt_side'] ?? null) === 'payable' ? 'Eski borç (sen borçlusun)' : 'Eski alacak (o sana borçlu)',
            $kind === ExpenseExtractor::KIND_PAYMENT && ($d['payment_purpose'] ?? null) === 'loan' => 'Borç verdin',
            $kind === ExpenseExtractor::KIND_PAYMENT && ($d['payment_purpose'] ?? null) === 'on_behalf' => 'Onun adına ödedin',
            $kind === ExpenseExtractor::KIND_PAYMENT && ! empty($d['advance']) => 'Ödeme (avans)',
            $kind === ExpenseExtractor::KIND_PAYMENT => 'Ödeme',
            $kind === ExpenseExtractor::KIND_COLLECTION => ($d['payment_purpose'] ?? null) === 'advance' ? 'Peşin para aldın (avans)' : 'Tahsilat',
            in_array($kind, [ExpenseExtractor::KIND_SALE, ExpenseExtractor::KIND_TRADE_SALE], true) => 'Satış' . (($d['paid'] ?? null) ? ' (peşin)' : ' (veresiye)'),
            in_array($kind, [ExpenseExtractor::KIND_PURCHASE, ExpenseExtractor::KIND_TRADE_PURCHASE], true) => 'Alış' . (($d['paid'] ?? null) ? ' (ödendi)' : ' (veresiye)'),
            $kind === ExpenseExtractor::KIND_EXPENSE => 'Gider' . (($d['paid'] ?? true) ? '' : ' (ödenmedi)'),
            default => 'Kayıt',
        };
        $party = DraftGaps::party($d)?->name ?? (! empty($d['party_name']) ? $d['party_name'] . ' (yeni kişi)' : null);
        $parts = [$word . ($party ? ' — ' . $party : '') . ' *' . Money::format((float) ($d['amount'] ?? 0)) . ' ₺*'];
        $desc = trim((string) ($d['description'] ?? ''));
        if ($desc !== '') {
            $parts[] = mb_strimwidth($desc, 0, 50, '…');
        }
        if (isset(self::PAYMENT_LABELS[$d['payment_type'] ?? '']) && in_array($kind, [ExpenseExtractor::KIND_PAYMENT, ExpenseExtractor::KIND_COLLECTION], true)) {
            $parts[] = self::PAYMENT_LABELS[$d['payment_type']];
        }
        $place = ! empty($d['project_id']) ? Project::whereKey($d['project_id'])->value('name')
            : (! empty($d['project_name']) ? $d['project_name'] . ' (yeni)' : null);
        if ($place) {
            $parts[] = $place;
        }
        if (! empty($d['date']) && $d['date'] !== now('Europe/Istanbul')->toDateString()) {
            $parts[] = Carbon::parse($d['date'])->format('d.m.Y');
        }

        return implode(' · ', $parts);
    }

    /** "borcun 742.000,00 → 622.000,00 ₺" / "hesap kapalı → sana borcu 5.000,00 ₺". */
    public static function balanceLine(string $name, float $before, float $after): string
    {
        $side = fn (float $b) => abs($b) < 0.01 ? 'kapalı' : ($b < 0 ? 'borcun' : 'sana borcu');
        $money = fn (float $b) => Money::format(abs($b));
        if (abs($before - $after) < 0.01) {
            return '📒 ' . $name . ': ' . self::phrase($after) . ' (değişmez)';
        }
        if ($side($before) === $side($after) && $side($after) !== 'kapalı') {
            return '📒 ' . $name . ': ' . $side($after) . ' ' . $money($before) . ' → *' . $money($after) . ' ₺*';
        }

        return '📒 ' . $name . ': ' . self::phrase($before) . ' → *' . self::phrase($after) . '*';
    }

    public static function phrase(float $balance): string
    {
        return abs($balance) < 0.01 ? 'hesap kapalı' : ($balance < 0 ? 'borcun ' . Money::format(abs($balance)) . ' ₺' : 'sana borcu ' . Money::format($balance) . ' ₺');
    }

    /**
     * @param  list<string>  $lines  işlem satırları
     * @param  list<array{party_id:int, name:string, before:float, after:float}>  $balances
     */
    public static function build(array $lines, array $balances, int $deletes, ?float $largeAmount, array $warnings = [], array $rawLines = []): string
    {
        // Ham satır: eski taslak ailesinin kendi kartı (çok satırlı, kendi simgesiyle) — önüne numara/simge konmaz.
        $out = count($lines) === 1
            ? [in_array(0, $rawLines, true) ? $lines[0] : '📝 ' . $lines[0]]
            : array_map(fn ($l, $i) => in_array($i, $rawLines, true) ? $l : ($i + 1) . '. ' . $l, $lines, array_keys($lines));
        $shown = implode("\n", $out);
        foreach ($balances as $b) {
            // Eski kart bakiyeyi zaten gösteriyorsa ("Bakiye: …") ikinci kez yazılmaz.
            if ($rawLines !== [] && (mb_stripos($shown, 'bakiye') !== false || mb_stripos($shown, 'borçlu') !== false
                || abs($b['before'] - $b['after']) < 0.01)) {
                continue;
            }
            $out[] = self::balanceLine($b['name'], $b['before'], $b['after']);
        }
        foreach (array_slice($warnings, 0, 2) as $w) {
            $out[] = '⚠️ ' . $w;
        }
        $out[] = match (true) {
            $deletes >= (int) config('agent.delete_word_from') => $deletes . ' kayıt silinecek. Onay için *sil* yaz · vazgeçmek için *iptal*',
            $largeAmount !== null => 'Tutar büyük — onay için tutarı yaz (*' . number_format($largeAmount, 0, '', '') . '*) · vazgeçmek için *iptal*',
            default => 'Kaydedeyim mi? *evet* / *iptal*',
        };

        return implode("\n", $out);
    }

    public static function partyName(int $id): string
    {
        return (string) (Party::whereKey($id)->value('name') ?? 'Kişi');
    }
}
