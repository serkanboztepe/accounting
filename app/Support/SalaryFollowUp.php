<?php

namespace App\Support;

use App\Models\Party;
use App\Models\Reminder;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\EmployeeDraft;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\SalaryChanged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Ödeme görünmüyor" takibi (plan v4 madde 10, kullanıcı 07.11 "ara ara soralım kapanana kadar"): maaş yazıldı ama 2 gün geçti, ödeme
 * yok → sorulur. *1* Ödedim → ödeme kartı (onayla) · *2* Henüz ödemedim → 1 hafta sonra · *3* Bunu bana sorma → o kişi için durur.
 * Cevapsız → haftada bir; 3 kez üst üste cevapsızsa durur, sabah dökümünde görünür. Ödeme yazılınca kendiliğinden biter.
 * Durum kişi kartında (parties.salary_followup) — yeni tablo yok.
 */
final class SalaryFollowUp
{
    public const KIND = 'salary_followup';   // soru taslağı
    public const BATCH = 'salary_batch';     // birden çok kişi "hepsini ödedim" kartı
    public const DELAY_DAYS = 2;
    public const EVERY_DAYS = 7;
    public const MAX_MISSES = 3;

    /**
     * Bugün sorulacaklar, numaraya göre.
     *
     * @return Collection<string, list<array{party: Party, period: string, owed: float}>>
     */
    public static function due(?Carbon $today = null): Collection
    {
        $today = ($today ?? now(Reminder::TIMEZONE))->copy()->startOfDay();
        $out = collect();
        foreach (SalaryLedger::eligibleEmployees() as $party) {
            $st = SalaryLedger::state($party);
            $open = array_filter($st['periods'], fn ($v) => $v['remaining'] >= 0.01);
            $fu = (array) ($party->salary_followup ?? []);
            if ($open === []) {
                if ($fu !== []) {
                    $party->update(['salary_followup' => null]); // ödendi — takip biter
                }

                continue;
            }
            $period = array_key_first($open);
            $first = Carbon::parse($period . '-01', Reminder::TIMEZONE);
            $askFrom = $first->copy()->day(min((int) $party->salary_day, $first->daysInMonth))->addDays(self::DELAY_DAYS);
            if ($today->lt($askFrom) || ! empty($fu['off'])) {
                continue;
            }
            if (($fu['period'] ?? null) !== $period) {
                $fu = ['period' => $period]; // yeni ay: sayaç sıfırdan
            }
            if ((int) ($fu['misses'] ?? 0) >= self::MAX_MISSES || (! empty($fu['next']) && $today->toDateString() < $fu['next'])) {
                continue;
            }
            $phone = self::phoneFor($party);
            if ($phone === null) {
                continue;
            }
            $out[$phone] = [...($out[$phone] ?? []), ['party' => $party, 'period' => $period, 'owed' => $st['owed']]];
        }

        return $out;
    }

    /** Maaş hatırlatmasının gittiği numara (yoksa firmanın hatırlatma alan ilk numarası). */
    public static function phoneFor(Party $party): ?string
    {
        $phone = EmployeeDraft::salaryReminder($party)?->phone ?? (\App\Tenancy\Tenancy::current()?->reminderPhones()[0] ?? null);

        return $phone ? Phone::normalize($phone) : null;
    }

    /** Şablon değişkeni tek satır (Twilio'da satır sonu yok); düz metin satırlı. */
    public static function text(array $items, bool $oneLine = false): string
    {
        $who = array_map(fn ($i) => $i['party']->name . ' — ' . SalaryLedger::label($i['period'], false) . ' maaşı '
            . Money::format($i['owed']) . ' ₺', $items);
        $one = count($items) === 1;
        $options = $one
            ? ['*1* Ödedim (' . Money::format($items[0]['owed']) . ' ₺)', '*2* Henüz ödemedim', '*3* Bunu bana sorma']
            : ['*1* Hepsini ödedim', '*2* Henüz ödemedim', '*3* Bunu bana sorma'];

        return $oneLine
            ? '👷 Maaş ödemesi görünmüyor: ' . implode(' · ', $who) . '. ' . implode(' · ', $options)
                . ($one ? '' : ' (biri ödendiyse adını yaz: "Erkan\'ı ödedim")')
            : "👷 *Maaş ödemesi görünmüyor*\n• " . implode("\n• ", $who) . "\n" . implode("\n", $options)
                . ($one ? '' : "\nBiri ödendiyse adını yaz (ör. \"" . $items[0]['party']->name . "'ın maaşını ödedim\").");
    }

    /** Soru gönderildi: sayaç +1, sonraki soru 1 hafta sonra; soru taslağı açılır (cevap 24 saat bağlanır). */
    public static function markAsked(string $phone, array $items, string $text, ?Carbon $today = null): void
    {
        $today = ($today ?? now(Reminder::TIMEZONE))->copy()->startOfDay();
        foreach ($items as $i) {
            $fu = (array) ($i['party']->salary_followup ?? []);
            $fu = ($fu['period'] ?? null) === $i['period'] ? $fu : ['period' => $i['period']];
            $i['party']->update(['salary_followup' => ['period' => $i['period'], 'misses' => (int) ($fu['misses'] ?? 0) + 1,
                'next' => $today->copy()->addDays(self::EVERY_DAYS)->toDateString(), 'asked' => $today->toDateString()]]);
        }
        $address = Phone::whatsappAddress($phone);
        WhatsappPendingExpense::where('phone', $address)->where('status', 'awaiting_confirmation')
            ->where('extracted', 'like', '%"kind":"' . self::KIND . '"%')->update(['status' => 'superseded']);
        WhatsappPendingExpense::create(['phone' => $address, 'status' => 'awaiting_confirmation', 'summary' => $text,
            'followed_up_at' => now(), // yarım kayıt gibi 24 saat etkin: öğlen gelen "1" buna bağlanır
            'extracted' => ['kind' => self::KIND, 'is_new_entry' => true,
                'items' => array_map(fn ($i) => ['party_id' => $i['party']->id, 'period' => $i['period']], $items)]]);
    }

    /** Numaraya yazacak açık taslağı var mı (sorumuz onun üstüne binmesin). */
    public static function busy(string $phone): bool
    {
        return WhatsappPendingExpense::where('phone', Phone::whatsappAddress($phone))->where('status', 'awaiting_confirmation')
            ->where('updated_at', '>=', now()->subHours(24))
            ->where('extracted', 'not like', '%"kind":"' . self::KIND . '"%')->exists();
    }

    /** @return list<Party> */
    private static function parties(array $extracted): array
    {
        return array_values(array_filter(array_map(fn ($i) => Party::find($i['party_id'] ?? 0), (array) ($extracted['items'] ?? []))));
    }

    /**
     * Soruya numaralı cevap. @return array{text?: string, draft?: array}|null (null = listede yok)
     */
    public static function answer(WhatsappPendingExpense $pending, int $n): ?array
    {
        $parties = self::parties($pending->extracted);
        if ($parties === [] || ! in_array($n, [1, 2, 3], true)) {
            return null;
        }
        $names = implode(', ', array_map(fn (Party $p) => $p->name, $parties));
        if ($n === 1) {
            if (count($parties) === 1) {
                return ['draft' => ['kind' => ExpenseExtractor::KIND_SALARY, 'party_id' => $parties[0]->id, 'party_name' => $parties[0]->name,
                    'paid' => true, 'amount' => 0.0, 'amount_source' => 'default', 'date' => now(Reminder::TIMEZONE)->toDateString(),
                    'description' => 'Maaş ödemesi', 'items' => [], 'is_new_entry' => true, 'confidence' => 'high', 'question' => null]];
            }
            [$card, $items] = self::batchCard($parties);
            $pending->update(['extracted' => ['kind' => self::BATCH, 'items' => $items], 'summary' => $card]);

            return ['text' => $card . "\n\nKaydedeyim mi? *evet*"];
        }
        $pending->update(['status' => 'cancelled']);
        foreach ($parties as $p) {
            $fu = (array) ($p->salary_followup ?? []);
            $p->update(['salary_followup' => $n === 2
                ? ['period' => $fu['period'] ?? null, 'misses' => 0, 'next' => now(Reminder::TIMEZONE)->addDays(self::EVERY_DAYS)->toDateString()]
                : ['period' => $fu['period'] ?? null, 'off' => true, 'stopped' => 'user']]);
        }

        return ['text' => $n === 2
            ? '👍 Tamam, ' . $names . ' için 1 hafta sonra tekrar soracağım. Ödeyince yazman yeterli (ör. "' . $parties[0]->name . ' maaşı ödendi").'
            : '👍 Tamam, ' . $names . ' için maaş ödemesini bir daha sormayacağım.'];
    }

    /** @return array{0: string, 1: list<array{party_id: int, amount: float, fp: string}>} */
    private static function batchCard(array $parties): array
    {
        $lines = [];
        $items = [];
        $date = now(Reminder::TIMEZONE)->toDateString();
        foreach ($parties as $p) {
            $amount = SalaryLedger::defaultAmount($p);
            if ($amount < 0.01) {
                continue;
            }
            $pv = SalaryLedger::preview($p, $amount, null, $date);
            $lines[] = '👷 *' . $p->name . '* — ' . Money::format($amount) . " ₺ ödeme\n" . implode("\n", $pv['lines']);
            $items[] = ['party_id' => $p->id, 'amount' => $amount, 'fp' => $pv['fingerprint']];
        }

        return [implode("\n\n", $lines), $items];
    }

    /** "Hepsini ödedim" kartının onayı: her kişi kendi kartı gibi yazılır (kart = kayıt; değiştiyse o kişi yazılmaz, söylenir). */
    public static function commitBatch(WhatsappPendingExpense $pending): string
    {
        $out = [];
        foreach ((array) ($pending->extracted['items'] ?? []) as $i) {
            $party = Party::find($i['party_id'] ?? 0);
            if (! $party) {
                continue;
            }
            $d = ['kind' => ExpenseExtractor::KIND_SALARY, 'party_id' => $party->id, 'paid' => true, 'amount' => (float) $i['amount'],
                'date' => now(Reminder::TIMEZONE)->toDateString(), 'description' => 'Maaş ödemesi', 'salary_fp' => $i['fp']];
            try {
                [$text] = EmployeeDraft::commit($d);
                $out[] = $text;
            } catch (SalaryChanged) {
                $out[] = '⚠️ ' . $party->name . ': bu arada hesap değişti, yazmadım — "' . $party->name . ' maaşı ödendi" yazarsan güncel kartı gösteririm.';
            }
        }
        $pending->update(['status' => 'confirmed']);

        return implode("\n", $out);
    }

    /** Sabah dökümü: 3 kez cevapsız kaldığı için sorulmayanlar. @return list<string> */
    public static function stoppedLines(): array
    {
        return SalaryLedger::eligibleEmployees()->filter(fn (Party $p) => (int) (($p->salary_followup ?? [])['misses'] ?? 0) >= self::MAX_MISSES
            && empty(($p->salary_followup ?? [])['off']))
            ->map(fn (Party $p) => '👷 ' . $p->name . ': ' . SalaryLedger::label((string) $p->salary_followup['period'], false)
                . ' maaşı ödemesi 3 kez soruldu, cevap yok — sormayı bıraktım')->values()->all();
    }
}
