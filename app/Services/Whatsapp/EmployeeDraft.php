<?php

namespace App\Services\Whatsapp;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Models\Reminder;
use App\Support\Phone;
use App\Support\SalaryLedger;
use App\Support\Reminders;
use App\Support\Money;
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
    public static function guardSalaryAmount(array $d, string $text, ?array $prev = null): array
    {
        if (self::isPayroll($d)) {
            return self::guardPayrollAmount($d, $text, $prev);
        }
        if (($d['kind'] ?? null) !== ExpenseExtractor::KIND_SALARY || ! empty($d['salary_deduction']) || self::isDaily($d) || ! ($party = self::party($d))
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

        return min($salary, self::deductions($party));
    }

    /**
     * Maaştan düşülecek: personelin bize borcu (ekstre bakiyesi > 0) = açık avans + kesinti (27.10 "maaştan kes" — kesinti avans gibi
     * maaştan düşer, para çıkmaz). Personel değilse avans açıklamalı ödemeler (AdvanceOffset).
     */
    public static function deductions(Party $party): float
    {
        if (! $party->is_employee) {
            return AdvanceOffset::open($party, ExpenseExtractor::KIND_PURCHASE);
        }

        return round(max(0.0, (float) TurnTrace::statement('EmployeeDraft::deductions', $party)['balance']), 2);
    }

    public const DEDUCTION_PREFIX = 'Kesinti — ';

    /** O ayın maaş satırı ("Maaş — Ekim 2026") — kendiliğinden işlendiyse ya da elle yazıldıysa (çift yazma koruması). */
    public static function accrued(Party $party, Carbon $month): ?PartyLedgerEntry
    {
        // Harf duyarsız (07.11: panelde "Maaş — KASIM 2026" yazılınca eşleşmiyor, 15.11'de ikinci maaş yazılacaktı).
        return SalaryLedger::accrualRow($party, $month->format('Y-m'));
    }

    /**
     * Maaş günü kendiliğinden işleme (27.10, kartta "Maaşı her ay maaş gününde kendiliğinden işle"): o ayın maaş satırı yoksa yazılır.
     * Ödeme yazılmaz — avans / kesinti bakiyeden kendiliğinden düşer, kalan ödeme kullanıcıda.
     */
    public static function accrue(Party $party, Carbon $day): ?PartyLedgerEntry
    {
        if (! $party->is_employee || (float) $party->salary_amount <= 0) {
            return null;
        }
        $period = $day->format('Y-m');

        // Kilit + kilitten sonra yeniden bak: aynı anda iki iş (sabah işi + WhatsApp) maaşı iki kez yazmasın.
        return SalaryLedger::locked($party, function () use ($party, $day, $period) {
            $amount = SalaryLedger::salaryFor($party, $period); // ilk ay seçimi: 0 = o ay yazılmaz, N günlük tutar
            if ($amount < 0.01 || ($party->salary_first_month && $period < $party->salary_first_month) || self::accrued($party, $day)) {
                return null;
            }

            return PartyLedgerEntry::create([
                'party_id' => $party->id, 'entry_date' => $day->toDateString(), 'type' => PartyLedgerEntry::TYPE_PURCHASE,
                'salary_period' => $period,
                'description' => 'Maaş — ' . SalaryLedger::label($period)
                    . ($party->salary_first_month === $period && $amount < (float) $party->salary_amount - 0.009 ? ' (ilk ay)' : ''),
                'amount' => Money::store($amount), 'notes' => 'Maaş günü kendiliğinden işlendi.',
            ]);
        });
    }

    /** Maaştan kes (27.10): ücretsiz izin, kırılan eşya… — borcumuz azalır, para çıkmaz; maaşta avans gibi düşülür. */
    public static function deduct(Party $party, float $amount, string $reason, string $date, string $note = 'WhatsApp üzerinden girildi.'): PartyLedgerEntry
    {
        return PartyLedgerEntry::create([
            'party_id' => $party->id, 'entry_date' => $date, 'type' => PartyLedgerEntry::TYPE_PURCHASE_RETURN,
            'description' => self::DEDUCTION_PREFIX . (trim($reason) !== '' ? trim($reason) : 'maaştan kesinti'),
            'amount' => Money::store($amount), 'notes' => $note,
        ]);
    }

    public static function blocker(array $d): ?string
    {
        if (! self::name($d)) {
            return 'Kimin? Adını yazar mısın?';
        }
        if ($d['kind'] === ExpenseExtractor::KIND_EMPLOYEE_SETTING) {
            if (empty($d['salary_amount']) && empty($d['salary_day'])) {
                return 'Aylık maaşı ne kadar, ayın kaçında ödüyorsun?';
            }

            return self::firstChoiceNeeded($d) ? self::firstChoiceQuestion($d) : null;
        }
        if (self::isPayroll($d)) {
            return ! empty($d['paid']) && self::payrollAmount($d) < 0.01 ? 'Ne kadar verdin?' : null;
        }
        if (! empty($d['salary_deduction'])) {
            return (float) ($d['amount'] ?? 0) > 0 ? null : 'Maaşından ne kadar keseyim?';
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
                $lines[] = '• Maaş günü: her ayın ' . (int) $d['salary_day'] . "'i — bir gün önce ve o sabah 08:00'de hatırlatırım";
            }
            if (($first = self::firstChoiceLine($d)) !== null) {
                $lines[] = $first;
            }

            return implode("\n", $lines);
        }
        if (self::isPayroll($d)) {
            return self::payrollSummary($d);
        }

        if (! empty($d['salary_deduction'])) {
            $reason = trim((string) ($d['description'] ?? ''));

            return '✂️ *' . $name . '*' . $new . ' — maaştan kesinti: ' . Money::format((float) $d['amount']) . ' ₺' . ($reason !== '' ? ' (' . $reason . ')' : '')
                . "\n• Para çıkmaz; maaşından düşülür";
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
            $first = self::firstChoiceValues($d, $party);
            $party->update(array_filter([
                'is_employee' => true,
                'salary_amount' => ! empty($d['salary_amount']) ? Money::store((float) $d['salary_amount']) : null,
                'salary_day' => ! empty($d['salary_day']) ? (int) $d['salary_day'] : null,
            ], fn ($v) => $v !== null) + $first);

            $reminder = $party->salary_day && $phone ? self::syncReminder($party, $phone) : null;

            return ['✅ ' . $party->name . ' personel olarak kaydedildi.'
                . ($reminder ? "\n⏰ Maaş günü hatırlatması: her ayın " . $party->salary_day . "'i (bir gün önce ve o sabah 08:00). İlk: "
                    . Reminders::when($reminder->next_fire_at, false) : ''), $party->id];
        }

        $d['party_id'] = $party->id;
        if (self::isPayroll($d)) {
            return self::commitPayroll($d, $party);
        }
        if (! empty($d['salary_deduction'])) {
            $party->is_employee || $party->update(['is_employee' => true]);
            SalaryLedger::locked($party, fn () => self::deduct($party, (float) $d['amount'], (string) ($d['description'] ?? ''),
                $d['date'] ?? now()->toDateString()));
            $balance = TurnTrace::statement('EmployeeDraft::commit.deduction', $party)['balance'];

            return ['✅ Kesinti yazıldı. ' . $party->name . ': ' . match (true) {
                abs($balance) < 0.01 => 'hesap kapalı.',
                $balance > 0 => 'maaşından düşülecek ' . Money::format($balance) . ' ₺ (avans + kesinti).',
                default => 'ona ' . Money::format(abs($balance)) . ' ₺ borçlusun.',
            }, $party->id];
        }
        $salary = self::salary($d);
        $advance = self::advance($d);
        $date = $d['date'] ?? now()->toDateString();
        $projectId = $d['project_id'] ?? null;
        if ($projectId === null && ! empty($d['project_name'])) {
            $projectId = Project::firstOrCreate(['name' => $d['project_name']], ['status' => 'active'])->id;
        }

        $note = $d['source_note'] ?? 'WhatsApp üzerinden girildi.';
        // Yevmiye aylık maaş değildir — karttaki maaşa yazılmaz.
        $party->update(['is_employee' => true] + ($party->salary_amount || self::isDaily($d) ? [] : ['salary_amount' => Money::store($salary)]));
        // O ayın maaşı kendiliğinden işlendiyse (27.10) tekrar yazılmaz; ödenen = şu an ona borcumuz.
        $already = ! self::isDaily($d) ? self::accrued($party, self::month($d)) : null;
        if ($already) {
            $paid = max(0.0, -(float) TurnTrace::statement('EmployeeDraft::commit.accrued', $party)['balance']);
        } else {
            PartyLedgerEntry::create([
                'party_id' => $party->id, 'project_id' => $projectId, 'entry_date' => $date,
                'type' => PartyLedgerEntry::TYPE_PURCHASE, 'description' => (self::isDaily($d) ? 'Yevmiye — ' : 'Maaş — ') . self::monthLabel($d),
                'amount' => Money::store($salary), 'notes' => $note,
            ]);
            $paid = $salary - $advance;
        }
        if (! empty($d['paid']) && $paid >= 0.01) {
            PartyLedgerEntry::create([
                'party_id' => $party->id, 'entry_date' => $date, 'type' => PartyLedgerEntry::TYPE_PAYMENT,
                'payment_type' => $d['payment_type'] ?? null, 'description' => (self::isDaily($d) ? 'Yevmiye ödemesi — ' : 'Maaş ödemesi — ') . self::monthLabel($d),
                'amount' => Money::store($paid), 'notes' => $note,
            ]);
        }
        $balance = TurnTrace::statement('EmployeeDraft::commit', $party)['balance'];

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
    public static function reminderNote(Party $party, ?Carbon $event = null): ?string
    {
        if (! $party->is_employee || ! $party->salary_amount) {
            return null;
        }
        if (SalaryLedger::eligible($party)) {
            return self::payrollReminderNote($party, $event ?? now(Reminder::TIMEZONE));
        }
        $salary = (float) $party->salary_amount;
        // Bugün kendiliğinden işlendiyse (27.10) maaş bakiyede: düşülen = maaş − şimdiki borcumuz.
        $auto = $party->salary_auto && ($row = self::accrued($party, now(Reminder::TIMEZONE))) && $row->entry_date?->isToday();
        $off = $auto ? $salary + (float) TurnTrace::statement('EmployeeDraft::reminderNote', $party)['balance'] : self::deductions($party);
        $kesinti = min($off, (float) PartyLedgerEntry::where('party_id', $party->id)->where('type', PartyLedgerEntry::TYPE_PURCHASE_RETURN)
            ->where('description', 'like', self::DEDUCTION_PREFIX . '%')->sum('amount'));
        $avans = max(0.0, $off - $kesinti);
        $parts = ($avans >= 0.01 ? ' − avans ' . Money::format($avans) : '') . ($kesinti >= 0.01 ? ' − kesinti ' . Money::format($kesinti) : '');
        $head = $auto ? now(Reminder::TIMEZONE)->locale('tr')->translatedFormat('F') . ' maaşı işlendi: ' : '';
        // Avans / kesinti maaşı karşılıyorsa "0 ₺ ödeyeceksin" yazılmaz (19.10 Yeni Yapı "50.000 − 50.000 = 0").
        if ($off >= $salary - 0.009) {
            $extra = round($off - $salary, 2);

            $given = implode(' + ', array_filter([$avans >= 0.01 ? 'avans ' . Money::format($avans) . ' ₺' : null,
                $kesinti >= 0.01 ? 'kesinti ' . Money::format($kesinti) . ' ₺' : null]));

            return $head . 'maaş ' . Money::format($salary) . ' ₺ — verilen ' . $given . ' maaşı karşılıyor, bu ay ödeme yok'
                . ($extra > 0 ? ' (' . Money::format($extra) . ' ₺ sonraki aya kalır)' : '')
                . ($auto ? '' : ". Avanstan düşmek için *{$party->name} maaşı ödendi* yaz");
        }

        return $head . 'maaş ' . Money::format($salary) . ' ₺' . ($parts !== '' ? $parts . ' = ' . Money::format($salary - $off) . ' ₺' : '')
            . ($auto ? ' ödemen gerekiyor' : ' ödeyeceksin') . ". Ödeyince *{$party->name} maaşı ödendi* yaz";
    }

    // ── Yeni maaş kuralı (plan v4, 07.11): kartında maaş + gün + "kendiliğinden işle" açık personel ───────────────────────────

    /** Maaş ödemesi yeni kuralla mı? (en eski borçtan kapatır, maaş satırı yazmaz — maaşı sabah işi yazar) */
    public static function isPayroll(array $d): bool
    {
        return ($d['kind'] ?? null) === ExpenseExtractor::KIND_SALARY && empty($d['salary_deduction']) && ! self::isDaily($d)
            && SalaryLedger::eligible(self::party($d));
    }

    /**
     * Yeni kuraldaki personele para (WhatsApp "ödeme"): maaş ödemesi olarak işlenir — "maaş mı, avans mı?" sorulmaz, en eski borçtan
     * kapatılır, borç yoksa avans (kullanıcı 07.11). Borç verme, adına ödeme ve anlaşma ödemesi eskisi gibi kalır.
     */
    public static function fromPayment(array $d): array
    {
        if (($d['kind'] ?? null) !== ExpenseExtractor::KIND_PAYMENT || ($d['payment_purpose'] ?? null) === 'loan'
            || OnBehalfChoice::isOnBehalf($d) || ! empty($d['agreement_id']) || ! SalaryLedger::eligible(self::party($d))) {
            return $d;
        }

        return array_merge($d, ['kind' => ExpenseExtractor::KIND_SALARY, 'paid' => true, 'salary_deduction' => false,
            'from_payment' => true, 'question' => null, 'work_status_known' => null]);
    }

    /**
     * Ödeme tutarının kaynağı korunur (Yıldız 30.10: 19.000 dekont "Maaş" cevabıyla 40.000 oldu): mesajda rakam varsa o; rakamsız
     * cevapta önceki mesajın / dekontun tutarı kalır; hiç yoksa 0 = kalan (maaş − avans − kesinti) hesaplanır. AI'ın bağlamdan aldığı
     * sayı (hatırlatmadaki net) kullanılmaz.
     */
    private static function guardPayrollAmount(array $d, string $text, ?array $prev): array
    {
        if (! empty($d['from_document'])) {
            return ['amount_source' => 'receipt'] + $d;
        }
        if (preg_match('/\d/u', $text)) {
            return ['amount_source' => 'message'] + $d;
        }
        $kept = in_array($prev['amount_source'] ?? null, ['message', 'receipt'], true) && (float) ($prev['amount'] ?? 0) > 0
            && ($prev['party_id'] ?? null) == ($d['party_id'] ?? null);

        return array_merge($d, $kept
            ? ['amount' => (float) $prev['amount'], 'amount_source' => $prev['amount_source']]
            : ['amount' => 0.0, 'amount_source' => 'default']);
    }

    /** Ödenecek tutar: yazılan / dekonttaki; yoksa en eski açık maaşın kalanı (ya da sıradaki maaşın neti). */
    public static function payrollAmount(array $d): float
    {
        $amount = round((float) ($d['amount'] ?? 0), 2);
        if ($amount > 0) {
            return $amount;
        }
        // "Erkan'ın Ekim maaşını ödedim" (Kasım öncesi ay, tutarsız): o eski borcun kalanı — sıradaki maaş değil.
        $target = self::payrollTarget($d);
        if ($target !== null && $target < SalaryLedger::BOUNDARY && ($open = SalaryLedger::state(self::party($d))['legacy_open']) >= 0.01) {
            return $open;
        }

        return SalaryLedger::defaultAmount(self::party($d));
    }

    private static function payrollTarget(array $d): ?string
    {
        return ! empty($d['month']) && preg_match('/^\d{4}-\d{2}$/', (string) $d['month']) ? (string) $d['month'] : null;
    }

    private static function payrollDate(array $d): string
    {
        return $d['date'] ?? now(Reminder::TIMEZONE)->toDateString();
    }

    /** Kartın parmak izi — onayda yeniden hesaplanır; arada hesap değiştiyse yazılmaz (kart = kayıt). */
    public static function withFingerprint(array $d): array
    {
        if (! self::isPayroll($d)) {
            return $d;
        }
        if (empty($d['paid'])) {
            return ['salary_fp' => md5('accrue|' . self::accrualPlan($d)['text'])] + $d;
        }
        $amount = self::payrollAmount($d);

        return array_merge($d, ['salary_fp' => $amount >= 0.01
            ? SalaryLedger::preview(self::party($d), $amount, self::payrollTarget($d), self::payrollDate($d))['fingerprint'] : null]);
    }

    private static function payrollSummary(array $d): string
    {
        $party = self::party($d);
        if (empty($d['paid'])) {
            return '👷 *' . $party->name . '* — ' . self::accrualPlan($d)['text'];
        }
        $amount = self::payrollAmount($d);
        if ($amount < 0.01) {
            return '👷 *' . $party->name . '* — avans ve kesintiler maaşı karşılıyor, ödenecek bir şey görünmüyor.';
        }
        $pv = SalaryLedger::preview($party, $amount, self::payrollTarget($d), self::payrollDate($d));

        return implode("\n", ['👷 *' . $party->name . '* — ' . Money::format($amount) . ' ₺ ödeme', ...$pv['lines']]);
    }

    /** "Maaşını yaz, henüz vermedim": o ayın maaşı (yoksa) yazılır; varsa yazılmaz. */
    private static function accrualPlan(array $d): array
    {
        $party = self::party($d);
        $period = self::payrollTarget($d) ?? Carbon::parse(self::payrollDate($d))->format('Y-m');
        $row = SalaryLedger::accrualRow($party, $period);
        $amount = (float) ($d['amount'] ?? 0) > 0 ? (float) $d['amount'] : SalaryLedger::salaryFor($party, $period);

        return ['period' => $period, 'amount' => $amount, 'exists' => $row !== null, 'text' => $row
            ? SalaryLedger::label($period) . ' maaşı zaten yazılı (' . Money::format((float) $row->amount) . ' ₺)'
            : SalaryLedger::label($period) . ' maaşı: ' . Money::format($amount) . ' ₺ • Ödenmedi — borç olarak yazılır'];
    }

    /** @return array{0:string,1:?int} */
    private static function commitPayroll(array $d, Party $party): array
    {
        return SalaryLedger::locked($party, function () use ($d, $party) {
            $fresh = self::withFingerprint($d);
            if (! empty($d['salary_fp']) && ($fresh['salary_fp'] ?? null) !== $d['salary_fp']) {
                throw new SalaryChanged($fresh);
            }
            $note = $d['source_note'] ?? 'WhatsApp üzerinden girildi.';
            if (empty($d['paid'])) {
                $plan = self::accrualPlan($d);
                if (! $plan['exists'] && $plan['amount'] >= 0.01) {
                    PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => self::payrollDate($d),
                        'type' => PartyLedgerEntry::TYPE_PURCHASE, 'salary_period' => $plan['period'],
                        'description' => 'Maaş — ' . SalaryLedger::label($plan['period']), 'amount' => Money::store($plan['amount']), 'notes' => $note]);
                }

                return ['✅ ' . ($plan['exists'] ? $plan['text'] . ', yeniden yazmadım.' : 'Kaydedildi.') . ' ' . self::balanceText($party), $party->id];
            }
            $amount = self::payrollAmount($d);
            $pv = SalaryLedger::preview($party, $amount, self::payrollTarget($d), self::payrollDate($d));
            PartyLedgerEntry::create([
                'party_id' => $party->id, 'entry_date' => self::payrollDate($d), 'type' => PartyLedgerEntry::TYPE_PAYMENT,
                'payment_type' => $d['payment_type'] ?? null, 'salary_period' => self::payrollTarget($d),
                'description' => $pv['description'], 'amount' => Money::store($amount), 'notes' => $note,
            ]);
            IssuedChecks::store($d, $party); // çekle ödenen maaş: çek bu satıra bağlanır

            return ['✅ Kaydedildi. ' . self::balanceText($party), $party->id];
        });
    }

    /** "Ali: Kasım'dan 20.000 ₺ kaldı" / "10.000 ₺ avans (Aralık maaşından düşer)" / "hesap kapalı" + Kasım öncesi açık satırı. */
    public static function balanceText(Party $party): string
    {
        $st = SalaryLedger::state($party);
        $open = array_filter($st['periods'], fn ($v) => $v['remaining'] >= 0.01);
        $text = $party->name . ': ' . match (true) {
            $open !== [] => implode(', ', array_map(fn ($p, $v) => SalaryLedger::label($p, false) . " maaşından " . Money::format($v['remaining']) . ' ₺',
                array_keys($open), $open)) . ' kaldı.',
            $st['advance'] >= 0.01 => Money::format($st['advance']) . ' ₺ avans (' . SalaryLedger::label(SalaryLedger::nextDue($party)->format('Y-m'), false)
                . ' maaşından düşer).',
            default => 'maaş hesabı kapalı.',
        };

        return $text . ($st['legacy_open'] >= 0.01 ? "\nℹ️ " . SalaryLedger::legacyFrom($st) . ' ' . Money::format($st['legacy_open'])
            . ' ₺ açık görünüyor.' : '');
    }

    // ── İlk kayıt sorusu (kullanıcı 07.11): maaş gününe gün varsa "bu ay ne yapayım?" ─────────────────────────────────────

    /** Kayıt günü maaş günü değilse ve kişinin maaş geçmişi yoksa sorulur; mesajda söylendiyse (first_salary) sorulmaz. */
    public static function firstChoiceNeeded(array $d): bool
    {
        if (($d['kind'] ?? null) !== ExpenseExtractor::KIND_EMPLOYEE_SETTING || ! empty($d['first_salary'])) {
            return false;
        }
        $party = self::party($d);
        $day = (int) ($d['salary_day'] ?? $party?->salary_day);
        $salary = (float) ($d['salary_amount'] ?? $party?->salary_amount);
        // Yalnız ilk kez personel olan (zaten personel olanın günü/maaşı değişince sorulmaz — Hikmet "her ayın 20'si").
        if ($day < 1 || $salary <= 0 || ($party && ($party->is_employee || $party->salary_first_month || SalaryLedger::state($party)['periods'] !== []))) {
            return false;
        }
        $today = now(Reminder::TIMEZONE)->startOfDay();

        return $today->day !== min($day, $today->daysInMonth);
    }

    /** @return array{due: Carbon, days: int, partial: float, salary: float} */
    private static function firstChoicePlan(array $d): array
    {
        $party = self::party($d);
        $day = (int) ($d['salary_day'] ?? $party?->salary_day);
        $salary = (float) ($d['salary_amount'] ?? $party?->salary_amount);
        $today = now(Reminder::TIMEZONE)->startOfDay();
        $due = $today->copy()->day(min($day, $today->daysInMonth));
        if ($due->lte($today)) {
            $next = $today->copy()->startOfMonth()->addMonthNoOverflow();
            $due = $next->day(min($day, $next->daysInMonth));
        }
        $days = (int) $today->diffInDays($due);

        return ['due' => $due, 'days' => $days, 'salary' => $salary,
            'partial' => round($salary / $due->daysInMonth * $days, 2)];
    }

    private static function firstChoiceQuestion(array $d): string
    {
        $plan = self::firstChoicePlan($d);
        $date = $plan['due']->locale('tr')->translatedFormat('j F');

        return 'Maaş gününe ' . $plan['days'] . " gün var, bu ay için ne yapayım?\n"
            . "*1* Bu ay maaş yazma (ödedim ya da yeni başladı)\n"
            . '*2* ' . $plan['days'] . ' günlük maaş yaz (' . Money::format($plan['partial']) . " ₺), ödemedim\n"
            . '*3* ' . SalaryLedger::suffix($date) . ' tam maaş yaz (' . Money::format($plan['salary']) . ' ₺)';
    }

    public static function firstChoiceShown(?array $d): bool
    {
        return is_array($d) && self::firstChoiceNeeded($d);
    }

    /** Numaralı cevap: 1 yazma · 2 N günlük · 3 tam. */
    public static function chooseFirst(array $d, int $n): ?array
    {
        return match ($n) {
            1 => ['first_salary' => 'skip'] + $d,
            2 => ['first_salary' => 'partial'] + $d,
            3 => ['first_salary' => 'full'] + $d,
            default => null,
        };
    }

    private static function firstChoiceLine(array $d): ?string
    {
        if (empty($d['first_salary']) || $d['first_salary'] === 'full' && empty($d['first_salary_amount'])) {
            return empty($d['first_salary']) ? null : '• İlk maaş: '
                . SalaryLedger::suffix(self::firstChoicePlan($d)['due']->locale('tr')->translatedFormat('j F')) . ' tam maaş';
        }
        $plan = self::firstChoicePlan($d);
        $date = $plan['due']->locale('tr')->translatedFormat('j F');

        return $d['first_salary'] === 'skip'
            ? '• Bu ay maaş yazılmaz — ilk maaş ' . SalaryLedger::suffix(self::firstChoiceAfter($plan['due']))
            : '• İlk maaş: ' . SalaryLedger::suffix($date) . ' ' . Money::format(self::firstAmount($d, $plan)) . ' ₺ (' . $plan['days'] . ' gün), sonra tam maaş';
    }

    private static function firstChoiceAfter(Carbon $due): string
    {
        $next = $due->copy()->startOfMonth()->addMonthNoOverflow();

        return $next->day(min($due->day, $next->daysInMonth))->locale('tr')->translatedFormat('j F');
    }

    private static function firstAmount(array $d, array $plan): float
    {
        return (float) ($d['first_salary_amount'] ?? 0) > 0 ? round((float) $d['first_salary_amount'], 2) : $plan['partial'];
    }

    /** Panel: "bu ay ne yapayım?" seçenekleri (WhatsApp sorusuyla aynı metin). @return array<string, string> */
    public static function firstChoiceOptions(?int $day, ?float $salary): array
    {
        if ((int) $day < 1 || (float) $salary <= 0) {
            return [];
        }
        $plan = self::firstChoicePlan(['salary_day' => (int) $day, 'salary_amount' => (float) $salary]);

        return ['skip' => 'Bu ay maaş yazma (ödedim ya da yeni başladı)',
            'partial' => $plan['days'] . ' günlük maaş yaz (' . Money::format($plan['partial']) . ' ₺), ödemedim',
            'full' => SalaryLedger::suffix($plan['due']->locale('tr')->translatedFormat('j F')) . ' tam maaş yaz (' . Money::format($plan['salary']) . ' ₺)'];
    }

    public static function firstChoiceTitle(?int $day, ?float $salary): string
    {
        $plan = (int) $day >= 1 && (float) $salary > 0 ? self::firstChoicePlan(['salary_day' => (int) $day, 'salary_amount' => (float) $salary]) : null;

        return $plan ? 'Maaş gününe ' . $plan['days'] . ' gün var, bu ay için ne yapayım?' : 'Bu ay için ne yapayım?';
    }

    /**
     * Panelde personel kaydedilince (yeni ya da "kendiliğinden işle" yeni açıldı): ilk maaş ayı + seçim karta yazılır — WhatsApp ile aynı
     * kural (maaş günü kayıt → o ay yazılmaz; günü geçtiyse sıradaki ay). Maaş geçmişi olan / ilk ayı yazılı personele dokunulmaz.
     */
    public static function applyFirstChoice(Party $party, ?string $choice): void
    {
        $party->refresh();
        if (! SalaryLedger::eligible($party) || $party->salary_first_month || SalaryLedger::state($party)['periods'] !== []) {
            return;
        }
        $d = ['salary_day' => $party->salary_day, 'salary_amount' => $party->salary_amount, 'first_salary' => $choice];
        $plan = self::firstChoicePlan($d);
        $party->update(['salary_first_month' => $plan['due']->format('Y-m'), 'salary_first_amount' => match ($choice) {
            'skip' => 0, 'partial' => Money::store($plan['partial']), default => null,
        }]);
    }

    /** Karta yazılacak: "kendiliğinden işle" açılır (Tok 05.11: WhatsApp'tan açılan personelde kapalı kalıyordu) + ilk ay seçimi. */
    private static function firstChoiceValues(array $d, Party $party): array
    {
        if ($party->is_employee && $party->salary_auto) {
            return [];
        }
        $values = ['salary_auto' => true];
        $day = (int) ($d['salary_day'] ?? $party->salary_day);
        if ($day >= 1 && (float) ($d['salary_amount'] ?? $party->salary_amount) > 0) {
            // İlk maaşın ayı her zaman yazılır: sabah işi bundan önceki ayı yazmaz (maaş günü geçtikten sonra kayıt → o ay yazılmaz;
            // maaş günü kayıt → o ay yazılmaz, kullanıcı 07.11 "yazılan kayıt tamam").
            $plan = self::firstChoicePlan($d);
            $values['salary_first_month'] = $plan['due']->format('Y-m');
            $values['salary_first_amount'] = match ($d['first_salary'] ?? null) {
                'skip' => 0,
                'partial' => Money::store(self::firstAmount($d, $plan)),
                default => null,
            };
        }

        return $values;
    }

    /**
     * Yeni kuraldaki personelin hatırlatması (plan v4): hatırlatmanın günündeki ayın maaşı yazıldıysa kalan (önceden kısmen ödendiyse
     * yalnız kalan — Yıldız 14.11 "40.000" yerine 20.000); yazılmadıysa tahmini net. Kasım öncesi açık ayrı satır.
     */
    private static function payrollReminderNote(Party $party, Carbon $event): string
    {
        $st = SalaryLedger::state($party);
        $period = $event->format('Y-m');
        $row = SalaryLedger::accrualRow($party, $period);
        $pay = ". Ödeyince *{$party->name} maaşı ödendi* yaz";
        $legacy = $st['legacy_open'] >= 0.01 ? ' ℹ️ ' . SalaryLedger::legacyFrom($st) . ' ' . Money::format($st['legacy_open'])
            . ' ₺ açık görünüyor.' : '';
        $offWord = $st['deductions_left'] ? 'avans/kesinti' : 'avans';
        if ($row) {
            $v = $st['periods'][$period] ?? ['accrued' => (float) $row->amount, 'paid' => 0.0, 'remaining' => (float) $row->amount];
            $head = $row->entry_date?->isToday() ? SalaryLedger::label($period, false) . ' maaşı işlendi: ' : SalaryLedger::label($period, false) . ' maaşı: ';
            $older = array_sum(array_map(fn ($x) => $x['remaining'], array_filter($st['periods'], fn ($x, $p) => $p < $period, ARRAY_FILTER_USE_BOTH)));
            $olderText = $older >= 0.01 ? ' Önceki aylardan da ' . Money::format($older) . ' ₺ açık.' : '';
            if ($v['remaining'] < 0.01) {
                return $head . 'maaş ' . Money::format($v['accrued']) . ' ₺ — verilenler maaşı karşılıyor, bu ay ödeme yok' . '.' . $olderText . $legacy;
            }

            return $head . 'maaş ' . Money::format($v['accrued']) . ' ₺'
                . ($v['paid'] >= 0.01 ? ' − ' . $offWord . ' ' . Money::format($v['paid']) . ' = ' . Money::format($v['remaining']) . ' ₺' : '')
                . ' ödemen gerekiyor' . $pay . '.' . $olderText . $legacy;
        }
        $f = SalaryLedger::forecast($party, $st, $event->copy()->startOfDay());
        if ($f['salary'] < 0.01) {
            return 'bu ay maaş yazılmayacak (ilk ay)' . $legacy;
        }
        if ($f['net'] < 0.01) {
            return 'maaş ' . Money::format($f['salary']) . ' ₺ — verilen ' . $offWord . ' ' . Money::format($f['off']) . ' ₺ maaşı karşılıyor, bu ay ödeme yok'
                . ($f['extra'] >= 0.01 ? ' (' . Money::format($f['extra']) . ' ₺ sonraki aya kalır)' : '') . '.' . $legacy;
        }

        return 'maaş ' . Money::format($f['salary']) . ' ₺' . ($f['off'] >= 0.01 ? ' − ' . $offWord . ' ' . Money::format($f['off'])
            . ' = ' . Money::format($f['net']) . ' ₺' : '') . ' ödeyeceksin (tahmini)' . $pay . '.' . $legacy;
    }

    /** Maaşın ayı (ayın 1'i): mesajda ay söylendiyse o, yoksa kaydın tarihi. */
    public static function month(array $d): Carbon
    {
        $date = Carbon::parse($d['date'] ?? now()->toDateString());

        return (! empty($d['month']) ? Carbon::parse($d['month'] . '-01') : $date)->startOfMonth();
    }

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
