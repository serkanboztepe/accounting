<?php

namespace App\Services\Whatsapp;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Models\Reminder;
use App\Support\Phone;
use App\Support\Reminders;
use App\Support\Money;
use App\Support\PartyStatement;
use Illuminate\Support\Carbon;

/**
 * Personel (Akkuş, 15.10): ayrı modül yok — cari + işaret (is_employee, salary_amount, salary_day).
 *  - "employee_setting": "Abdullah personelim, maaşı 50 bin, ayın 15'i" → kart güncellenir (onayla).
 *  - "salary": "Abdullah'ın maaşını verdim" → carinin ekstresine "Maaş — Ekim 2026" (hak ediş, alış türü) +
 *    açık avans düşülür, kalan ödenen kısım ödeme olarak. Ayrı gider kaydı AÇILMAZ; ay sonucunda "Personel" satırı.
 *  - Avans ("Abdullah 20 bin avans aldı") mevcut ödeme (advance) yolundan gider — burada değil.
 */
final class EmployeeDraft
{
    public const KINDS = [ExpenseExtractor::KIND_EMPLOYEE_SETTING, ExpenseExtractor::KIND_SALARY];

    public static function handles(array $d): bool
    {
        return in_array($d['kind'] ?? null, self::KINDS, true);
    }

    public static function party(array $d): ?Party
    {
        return ! empty($d['party_id']) ? Party::find($d['party_id']) : null;
    }

    private static function name(array $d): ?string
    {
        return self::party($d)?->name ?? ($d['party_name'] ?? null);
    }

    /** Maaş tutarı: mesajda yazdıysa o, yoksa karttaki. */
    public static function salary(array $d): ?float
    {
        $amount = (float) ($d['amount'] ?? 0);
        if ($amount > 0) {
            return $amount;
        }
        $card = self::party($d)?->salary_amount;

        return $card > 0 ? (float) $card : null;
    }

    /**
     * Maaş tutarı emniyeti (Akkuş 19.10: "Afgan Apo maaşı ödendi" → AI hatırlatmadaki NET 49.500'ü maaş sandı, avans ikinci kez
     * düşüldü: maaş 49.500 / ödenen 44.000 yazıldı; doğrusu 55.000 / 49.500). Kartında maaşı olan çalışanda:
     *  - mesajda hiç rakam yoksa tutar KARTTAKİ maaştır (AI'ın bağlamdan aldığı sayı değil);
     *  - AI'ın tutarı "maaş − açık avans"a eşitse (net) tam maaşa çevrilir.
     * Yazılan farklı tutar ("Afgan'a 30 bin verdim") olduğu gibi kalır (kısmi ödeme); yevmiyeye dokunulmaz.
     */
    public static function guardSalaryAmount(array $d, string $text): array
    {
        if (($d['kind'] ?? null) !== ExpenseExtractor::KIND_SALARY || self::isDaily($d) || ! ($party = self::party($d))
            || (float) $party->salary_amount <= 0) {
            return $d;
        }
        $card = (float) $party->salary_amount;
        $amount = (float) ($d['amount'] ?? 0);
        if (! preg_match('/\d/u', $text)) {
            return ['amount' => $card] + $d;
        }
        $advance = min($card, AdvanceOffset::open($party, ExpenseExtractor::KIND_PURCHASE));
        if ($advance > 0.01 && abs($amount - ($card - $advance)) < 0.01) {
            return ['amount' => $card] + $d;
        }

        return $d;
    }

    /** Açık avans (maaştan düşülecek) — yalnız kayıtlı cari. */
    public static function advance(array $d): float
    {
        $party = self::party($d);
        $salary = self::salary($d);
        if (! $party || ! $salary) {
            return 0.0;
        }

        return min($salary, AdvanceOffset::open($party, ExpenseExtractor::KIND_PURCHASE));
    }

    public static function blocker(array $d): ?string
    {
        if (! self::name($d)) {
            return 'Kimin? Adını yazar mısın?';
        }
        if ($d['kind'] === ExpenseExtractor::KIND_EMPLOYEE_SETTING) {
            return empty($d['salary_amount']) && empty($d['salary_day'])
                ? 'Aylık maaşı ne kadar, ayın kaçında ödüyorsun?' : null;
        }

        return self::salary($d) ? null : 'Maaşı ne kadar?';
    }

    public static function summary(array $d): string
    {
        $name = self::name($d) ?? '—';
        $new = self::party($d) ? '' : ' 🆕';
        if ($d['kind'] === ExpenseExtractor::KIND_EMPLOYEE_SETTING) {
            $lines = ['👷 *' . $name . '*' . $new . ' — personel'];
            if (! empty($d['salary_amount'])) {
                $lines[] = '• Aylık maaş: ' . Money::format((float) $d['salary_amount']) . ' ₺';
            }
            if (! empty($d['salary_day'])) {
                $lines[] = '• Maaş günü: her ayın ' . (int) $d['salary_day'] . "'i — o sabah 08:00'de hatırlatırım";
            }

            return implode("\n", $lines);
        }

        $salary = self::salary($d) ?? 0.0;
        $advance = self::advance($d);
        $lines = ['👷 *' . $name . '*' . $new . (self::isDaily($d) ? ' — yevmiye ' : ' — maaş ') . self::monthLabel($d) . ': ' . Money::format($salary) . ' ₺'];
        if ($advance > 0) {
            $lines[] = '• Avanstan düşülen: ' . Money::format($advance) . ' ₺';
        }
        $lines[] = ! empty($d['paid'])
            ? '• Ödenen: ' . Money::format($salary - $advance) . ' ₺' . ($advance > 0 ? ' (maaş − avans)' : '')
            : '• Ödenmedi — ' . Money::format($salary - $advance) . ' ₺ borç olarak yazılır';

        return implode("\n", $lines);
    }

    /** @return array{0:string,1:?int} [cevap, dokunulan cari id] */
    public static function commit(array $d, ?string $phone = null): array
    {
        $party = self::party($d) ?? Party::firstOrCreate(['name' => $d['party_name']]);

        if ($d['kind'] === ExpenseExtractor::KIND_EMPLOYEE_SETTING) {
            $party->update(array_filter([
                'is_employee' => true,
                'salary_amount' => ! empty($d['salary_amount']) ? Money::store((float) $d['salary_amount']) : null,
                'salary_day' => ! empty($d['salary_day']) ? (int) $d['salary_day'] : null,
            ], fn ($v) => $v !== null));

            $reminder = $party->salary_day && $phone ? self::syncReminder($party, $phone) : null;

            return ['✅ ' . $party->name . ' personel olarak kaydedildi.'
                . ($reminder ? "\n⏰ Maaş günü hatırlatması: her ayın " . $party->salary_day . "'i (bir gün önce ve o sabah 08:00). İlk: "
                    . Reminders::when($reminder->next_fire_at, false) : ''), $party->id];
        }

        $d['party_id'] = $party->id;
        $salary = self::salary($d);
        $advance = self::advance($d);
        $date = $d['date'] ?? now()->toDateString();
        $projectId = $d['project_id'] ?? null;
        if ($projectId === null && ! empty($d['project_name'])) {
            $projectId = Project::firstOrCreate(['name' => $d['project_name']], ['status' => 'active'])->id;
        }

        // Yevmiye aylık maaş değildir — karttaki maaşa yazılmaz.
        $party->update(['is_employee' => true] + ($party->salary_amount || self::isDaily($d) ? [] : ['salary_amount' => Money::store($salary)]));
        PartyLedgerEntry::create([
            'party_id' => $party->id, 'project_id' => $projectId, 'entry_date' => $date,
            'type' => PartyLedgerEntry::TYPE_PURCHASE, 'description' => (self::isDaily($d) ? 'Yevmiye — ' : 'Maaş — ') . self::monthLabel($d),
            'amount' => Money::store($salary), 'notes' => 'WhatsApp üzerinden girildi.',
        ]);
        $paid = $salary - $advance;
        if (! empty($d['paid']) && $paid >= 0.01) {
            PartyLedgerEntry::create([
                'party_id' => $party->id, 'entry_date' => $date, 'type' => PartyLedgerEntry::TYPE_PAYMENT,
                'payment_type' => $d['payment_type'] ?? null, 'description' => (self::isDaily($d) ? 'Yevmiye ödemesi — ' : 'Maaş ödemesi — ') . self::monthLabel($d),
                'amount' => Money::store($paid), 'notes' => 'WhatsApp üzerinden girildi.',
            ]);
        }
        $balance = PartyStatement::build($party)['balance'];

        return ['✅ Kaydedildi. ' . $party->name . ': ' . match (true) {
            abs($balance) < 0.01 => 'hesap kapalı.',
            $balance > 0 => 'sana ' . Money::format($balance) . ' ₺ borçlu (avans fazlası).',
            default => 'ona ' . Money::format(abs($balance)) . ' ₺ borçlusun.',
        }, $party->id];
    }

    /** Aylık maaş günü hatırlatması (kişiye bağlı; aynı kişinin önceki maaş hatırlatması güncellenir, çift olmaz). */
    public static function syncReminder(Party $party, string $phone): Reminder
    {
        $today = Carbon::now(Reminder::TIMEZONE)->startOfDay();
        $day = (int) $party->salary_day;
        $event = $today->copy()->day(min($day, $today->daysInMonth));
        if ($event->lt($today)) {
            $next = $today->copy()->startOfMonth()->addMonthNoOverflow();
            $event = $next->day(min($day, $next->daysInMonth));
        }
        $reminder = self::salaryReminder($party) ?? new Reminder(['phone' => Phone::normalize($phone)]);
        $reminder->fill(['text' => $party->name . ' maaş günü', 'party_id' => $party->id, 'event_date' => $event->toDateString(),
            'event_time' => null, 'lead_minutes' => null, 'is_alarm' => false, 'repeat' => 'monthly', 'anchor_day' => $day]);
        $reminder->scheduleNext();
        $reminder->save();

        return $reminder;
    }

    /**
     * Panelde kart kaydedilince (kullanıcı 19.10): maaş günü yalnız kartta tutulur, hatırlatma ondan beslenir. Personel + gün varsa
     * hatırlatma kurulur / günü güncellenir (numara: varsa eski hatırlatmanınki, yoksa firmanın sabah hatırlatması alan ilk numarası);
     * personellikten çıkınca ya da gün silinince kapanır.
     */
    public static function syncFromCard(Party $party): void
    {
        $existing = self::salaryReminder($party);
        if (! $party->is_employee || ! $party->salary_day) {
            $existing?->update(['status' => 'cancelled', 'next_fire_at' => null]);

            return;
        }
        if ($existing && (int) $existing->anchor_day === (int) $party->salary_day) {
            return;
        }
        $phone = $existing?->phone ?? (\App\Tenancy\Tenancy::current()?->reminderPhones()[0] ?? null);
        if ($phone) {
            self::syncReminder($party, $phone);
        }
    }

    public static function salaryReminder(Party $party): ?Reminder
    {
        return Reminder::where('party_id', $party->id)->where('repeat', 'monthly')->where('status', 'active')
            ->where('text', 'like', '%maaş%')->first();
    }

    /**
     * WhatsApp "Hikmet'in maaş hatırlatmasını kur / her ayın 17'si maaş hatırlat": personelin maaşı için düz hatırlatma açılmaz —
     * kartın maaş günü ayarı olur (Akkuş: cariye bağsız "Abdullah Günhan maaş ödemesi 50.000 TL" ikinci hatırlatma olmuştu).
     */
    public static function salaryReminderParty(array $d): ?Party
    {
        $text = (string) ($d['description'] ?? '');
        if (! preg_match('/ma[aâ][şs]/iu', $text)) {
            return null;
        }
        $party = ! empty($d['party_id']) ? Party::find($d['party_id']) : null;
        $party ??= Party::where('is_employee', true)->get()->first(fn (Party $p) => mb_stripos($text, $p->name) !== false);

        return $party?->is_employee ? $party : null;
    }

    /** Hatırlatma notu: "maaş 50.000 − avans 20.000 = 30.000 ₺ ödeyeceksin" (Reminders::fireText). */
    public static function reminderNote(Party $party): ?string
    {
        if (! $party->is_employee || ! $party->salary_amount) {
            return null;
        }
        $salary = (float) $party->salary_amount;
        $advance = min($salary, AdvanceOffset::open($party, ExpenseExtractor::KIND_PURCHASE));

        return 'maaş ' . Money::format($salary) . ' ₺'
            . ($advance > 0 ? ' − avans ' . Money::format($advance) . ' = ' . Money::format($salary - $advance) . ' ₺' : '')
            . " ödeyeceksin. Ödeyince *{$party->name} maaşı ödendi* yaz";
    }

    /** Hangi ayın maaşı: mesajda ay söylendiyse (`month`, "eylül maaşı"), yoksa kaydın tarihi. */
    public static function monthLabel(array $d): string
    {
        // Yevmiye: ay değil gün ("Yevmiye — 15 Ekim").
        if (self::isDaily($d)) {
            return Carbon::parse($d['date'] ?? now()->toDateString())->locale('tr')->translatedFormat('j F');
        }
        $date = Carbon::parse($d['date'] ?? now()->toDateString());
        $month = ! empty($d['month']) ? Carbon::parse($d['month'] . '-01') : $date;

        return $month->locale('tr')->translatedFormat('F Y');
    }

    private static function isDaily(array $d): bool
    {
        return (bool) preg_match('/yevmiye|g[üu]nl[üu]k|haftal[ıi]k/iu', (string) ($d['description'] ?? ''));
    }
}
