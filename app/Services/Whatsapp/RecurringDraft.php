<?php

namespace App\Services\Whatsapp;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Reminder;
use App\Models\WhatsappPendingExpense;
use App\Support\Money;
use App\Support\Phone;
use Illuminate\Support\Carbon;

/**
 * Düzenli gider (Oktay 18.10: "çobanlar günde 2 ekmek, 17,30 TL — her gün gider olarak ekle"). Ayrı tablo değil: tutarı olan
 * hatırlatma (kullanıcı kararı 19.10). Günü gelince 08:00'de gider yazılır ve "… gider olarak eklendi, geri almak için *iptal*"
 * mesajı gider (reminders:send). Onaylı kart: ne, kaç TL, ne sıklıkla, ilk kayıt günü.
 *
 *   recurring_expense → "🔁 Düzenli gider — kontrol et" + onay
 *   recurring_list    → hemen cevap
 *   recurring_stop    → "🛑 Durdurayım mı?" + onay
 */
final class RecurringDraft
{
    public const KINDS = [ExpenseExtractor::KIND_RECURRING_EXPENSE, ExpenseExtractor::KIND_RECURRING_LIST, ExpenseExtractor::KIND_RECURRING_STOP];

    /** Gönderilen "gider eklendi" mesajının kaydı — "iptal" / "son kaydı sil" bu gideri geri alır. */
    public const POSTED = 'recurring_post';

    /** "İptal" ile geri alma penceresi (saat). */
    private const UNDO_HOURS = 12;

    private const FREQUENCY_REPEAT = ['daily' => 'daily', 'weekly' => 'weekly', 'monthly' => 'monthly'];

    public static function handles(array $d): bool
    {
        return in_array($d['kind'] ?? null, self::KINDS, true);
    }

    public static function isAnswer(array $d): bool
    {
        return ($d['kind'] ?? null) === ExpenseExtractor::KIND_RECURRING_LIST;
    }

    public static function active()
    {
        return Reminder::where('status', 'active')->where('amount', '>', 0);
    }

    public static function listText(): string
    {
        $rows = self::active()->orderBy('text')->get();
        if ($rows->isEmpty()) {
            return "🔁 Düzenli giderin yok.\nÖrnek: *her gün çobanlara 2 ekmek 34,60 gider yaz* ya da *her ayın 1'i kira 20 bin*.";
        }

        return "🔁 *Düzenli giderlerin:*\n" . $rows->map(fn (Reminder $r) => '• ' . self::line($r)
            . ($r->next_fire_at ? ' · sıradaki ' . $r->next_fire_at->setTimezone(Reminder::TIMEZONE)->format('d.m') : ''))->implode("\n")
            . "\n\nDurdurmak için *ekmek giderini durdur* gibi yaz.";
    }

    /** "Kira — her ayın 1'i 20.000,00 ₺ (Ergenekon)" */
    public static function line(Reminder $r): string
    {
        return $r->text . ' — ' . $r->scheduleText() . ' ' . Money::format((float) $r->amount) . ' ₺' . ($r->project ? ' (' . $r->project->name . ')' : '');
    }

    /** Durdurulacaklar: metninde aranan kelime geçen etkin düzenli giderler. */
    public static function matches(array $d)
    {
        $needle = trim((string) ($d['description'] ?? ''));
        if ($needle === '') {
            return collect();
        }
        $like = (new Reminder)->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return self::active()->where('text', $like, '%' . $needle . '%')->limit(5)->get();
    }

    public static function blocker(array $d): ?string
    {
        if (($d['kind'] ?? null) === ExpenseExtractor::KIND_RECURRING_STOP) {
            return self::matches($d)->isEmpty() ? 'Böyle bir düzenli gider bulamadım. *düzenli giderlerim* yazarsan listeyi gösteririm.' : null;
        }
        if (trim((string) ($d['description'] ?? '')) === '') {
            return 'Ne gideri? Kısaca yazar mısın (ör. "çobanlara ekmek")?';
        }
        if ((float) ($d['amount'] ?? 0) <= 0) {
            return trim((string) $d['description']) . ' her seferinde kaç TL?';
        }
        if (empty($d['recurring_frequency'])) {
            return 'Ne sıklıkla: her gün mü, her hafta mı, her ay mı?';
        }
        if ($d['recurring_frequency'] === 'monthly' && empty($d['recurring_day'])) {
            return 'Ayın kaçında yazayım?';
        }
        if ($d['recurring_frequency'] === 'weekly' && empty($d['recurring_day'])) {
            return 'Haftanın hangi günü yazayım?';
        }

        return null;
    }

    public static function summary(array $d): string
    {
        if (($d['kind'] ?? null) === ExpenseExtractor::KIND_RECURRING_STOP) {
            return "🛑 *Bunları durdurayım mı?* (yazılmış eski giderler silinmez)\n"
                . self::matches($d)->map(fn (Reminder $r) => '• ' . self::line($r))->implode("\n");
        }
        $draft = self::model($d, '');
        $category = self::categoryName($d);

        return "🔁 *Düzenli gider — kontrol et:*\n"
            . '• ' . self::line($draft) . "\n"
            . ($category ? '• Kategori: ' . $category . "\n" : '')
            . '• İlk kayıt: ' . $draft->event_date->format('d.m.Y') . ', sonra ' . $draft->scheduleText() . ' ' . self::at($draft) . ' kendiliğinden yazılır, sana haber veririm';
    }

    /** @return array{0:string,1:?int} */
    public static function commit(array $d, string $phone): array
    {
        if (($d['kind'] ?? null) === ExpenseExtractor::KIND_RECURRING_STOP) {
            $rows = self::matches($d);
            \App\Models\ActivityLog::updateQuery(Reminder::whereKey($rows->pluck('id')), ['status' => 'cancelled', 'next_fire_at' => null]);

            return ['🛑 Durdurdum: ' . $rows->pluck('text')->implode(', ') . '. Bundan sonra yazılmaz; eskileri yerinde.', null];
        }
        $r = self::model($d, $phone);
        $r->expense_category_id = self::categoryId($d);
        $today = Carbon::now(Reminder::TIMEZONE)->startOfDay();
        // "Bugünden" denirse bugünkü hemen yazılır (saati söylendiyse ve henüz gelmediyse o saatte); sıradaki yarından itibaren.
        $written = $r->event_date->toDateString() === $today->toDateString() && (empty($d['recurring_time']) || $r->eventAt()->lte(now()));
        $r->scheduleNext($written ? $today->copy()->endOfDay() : now());
        $r->save();
        if ($written) {
            $r->postExpense($today);
        }

        return ['✅ Düzenli gider kuruldu: ' . self::line($r)
            . ($written ? "\n📝 Bugünkü yazıldı: " . Money::format((float) $r->amount) . ' ₺' : '')
            . ($r->next_fire_at ? "\nSıradaki: " . $r->next_fire_at->setTimezone(Reminder::TIMEZONE)->format('d.m.Y') . ' ' . self::at($r) . ' kendiliğinden yazılır, sana haber veririm.' : '')
            . "\nDurdurmak için *" . mb_strtolower(trim($r->text)) . ' giderini durdur* yaz.', null];
    }

    /**
     * reminders:send gideri yazınca: mesaj metni + "iptal" / "son kaydı sil" için kayıt (onaylanmış taslak gibi).
     */
    public static function posted(Reminder $r, Expense $expense): string
    {
        $text = '🔁 ' . ($r->project ? $r->project->name . ' için ' : '') . Money::format((float) $r->amount) . ' ₺ ' . $r->text
            . ' gider olarak eklendi (' . $r->scheduleText() . '). Geri almak için *iptal* yaz.';
        WhatsappPendingExpense::create([
            'phone' => Phone::whatsappAddress($r->phone),
            'extracted' => ['kind' => self::POSTED, 'amount' => (float) $r->amount, 'reminder_id' => $r->id,
                'created_records' => [['expense', $expense->id]]],
            'summary' => '🔁 ' . $r->text . ' — ' . $expense->expense_date->format('d.m') . ' — ' . Money::format((float) $r->amount) . ' ₺ (düzenli gider)',
            'status' => 'confirmed',
        ]);

        return $text;
    }

    /** Taslak yokken "iptal": son iş az önce yazılan düzenli gider ise onu siler. Değilse null (eski davranış). */
    public static function undoPosted(string $phone): ?string
    {
        $last = WhatsappPendingExpense::where('phone', $phone)->where('status', 'confirmed')
            ->where('updated_at', '>=', now()->subHours(self::UNDO_HOURS))->latest('id')->first();
        if (($last?->extracted['kind'] ?? null) !== self::POSTED) {
            return null;
        }
        $deleted = 0;
        foreach ((array) ($last->extracted['created_records'] ?? []) as [$type, $id]) {
            if ($type === 'expense' && ($e = Expense::find($id))) {
                $e->delete();
                $deleted++;
            }
        }
        $last->update(['status' => 'undone']);
        $r = Reminder::find($last->extracted['reminder_id'] ?? 0);

        return ($deleted ? '🗑️ Geri aldım: ' . preg_replace('/^🔁\s*/u', '', (string) $last->summary) . ' silindi.' : 'O gider zaten silinmiş.')
            . ($r && $r->status === 'active' ? "\nDüzenli gider devam ediyor; tamamen durdurmak için *" . mb_strtolower(trim($r->text)) . ' giderini durdur* yaz.' : '');
    }

    /** Kaydedilmemiş hatırlatma (özet ve kayıt aynı hesabı kullansın). */
    private static function model(array $d, string $phone): Reminder
    {
        $repeat = self::FREQUENCY_REPEAT[$d['recurring_frequency'] ?? ''] ?? 'daily';
        $day = (int) ($d['recurring_day'] ?? 0);
        $start = ! empty($d['recurring_start']) ? Carbon::parse($d['recurring_start'], Reminder::TIMEZONE)->startOfDay()
            : Carbon::now(Reminder::TIMEZONE)->startOfDay()->addDay(); // söylenmediyse yarından (bugünkü elle yazılmış olabilir)
        // İlk gün düzene uysun: haftalıkta o haftanın günü, aylıkta ayın günü (ay kısaysa son gün).
        for ($i = 0; $i < 400; $i++) {
            $fits = match ($repeat) {
                'weekly' => $day < 1 || $day > 7 || $start->dayOfWeekIso === $day,
                'monthly' => $day < 1 || $start->day === min($day, $start->daysInMonth),
                default => true,
            };
            if ($fits) {
                break;
            }
            $start->addDay();
        }

        return new Reminder([
            'phone' => $phone !== '' ? Phone::normalize($phone) : '',
            'text' => mb_substr(trim((string) $d['description']), 0, 255),
            'amount' => round((float) $d['amount'], 2),
            'project_id' => $d['project_id'] ?? null,
            'event_date' => $start->toDateString(),
            // Saat söylendiyse gider o saatte yazılır ve mesaj o saatte gider (Çiftlik 26.10 "her gün 16:00"), yoksa 08:00.
            'event_time' => $d['recurring_time'] ?? sprintf('%02d:00', Reminder::DEFAULT_HOUR),
            'is_alarm' => true,
            'repeat' => $repeat,
            'anchor_day' => $repeat === 'monthly' && $day >= 1 && $day <= 31 ? $day : null,
            'status' => 'active',
        ]);
    }

    /** "sabah" (08:00) ya da "saat 16:00". */
    private static function at(Reminder $r): string
    {
        $time = substr((string) $r->event_time, 0, 5);

        return $time === '' || $time === sprintf('%02d:00', Reminder::DEFAULT_HOUR) ? 'sabah' : 'saat ' . $time;
    }

    private static function categoryName(array $d): ?string
    {
        return ! empty($d['category_id']) ? ExpenseCategory::find($d['category_id'])?->name : (trim((string) ($d['category_name'] ?? '')) ?: null);
    }

    private static function categoryId(array $d): ?int
    {
        if (! empty($d['category_id']) && ExpenseCategory::whereKey($d['category_id'])->exists()) {
            return (int) $d['category_id'];
        }
        $name = trim((string) ($d['category_name'] ?? ''));

        return $name !== '' ? ExpenseCategory::firstOrCreate(['name' => $name])->id : null;
    }
}
