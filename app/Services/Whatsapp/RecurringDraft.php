<?php

namespace App\Services\Whatsapp;

use App\Models\ExpenseCategory;
use App\Models\RecurringExpense;
use App\Models\Reminder;
use App\Support\Money;
use App\Support\Phone;
use Illuminate\Support\Carbon;

/**
 * Düzenli gider (Oktay 18.10: "çobanlar günde 2 ekmek, 17,30 TL — her gün gider olarak ekle" hafızaya söz olarak yazılmıştı,
 * hiç girilmedi). Onaylı kart: ne, ne kadar, ne sıklıkla, ilk kayıt günü → sonra her sabah kendiliğinden yazılır.
 *
 *   recurring_expense → "🔁 Düzenli gider — kontrol et" + onay
 *   recurring_list    → hemen cevap
 *   recurring_stop    → "🛑 Durdurayım mı?" + onay
 */
final class RecurringDraft
{
    public const KINDS = [ExpenseExtractor::KIND_RECURRING_EXPENSE, ExpenseExtractor::KIND_RECURRING_LIST, ExpenseExtractor::KIND_RECURRING_STOP];

    public static function handles(array $d): bool
    {
        return in_array($d['kind'] ?? null, self::KINDS, true);
    }

    public static function isAnswer(array $d): bool
    {
        return ($d['kind'] ?? null) === ExpenseExtractor::KIND_RECURRING_LIST;
    }

    public static function listText(): string
    {
        $rows = RecurringExpense::where('is_active', true)->orderBy('description')->get();
        if ($rows->isEmpty()) {
            return "🔁 Düzenli giderin yok.\nÖrnek: *her gün çobanlara 2 ekmek 34,60 gider yaz* ya da *her ayın 1'i kira 20 bin*.";
        }

        return "🔁 *Düzenli giderlerin:*\n" . $rows->map(fn (RecurringExpense $r) => '• ' . $r->description . ' — ' . $r->label()
            . ' · sıradaki ' . $r->nextDate(Carbon::now(Reminder::TIMEZONE)->startOfDay())->format('d.m'))->implode("\n")
            . "\n\nDurdurmak için *ekmek giderini durdur* gibi yaz.";
    }

    /** Durdurulacaklar: açıklamasında aranan kelime geçen etkin düzenli giderler. */
    public static function matches(array $d)
    {
        $needle = trim((string) ($d['description'] ?? ''));
        if ($needle === '') {
            return collect();
        }
        $like = (new RecurringExpense)->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return RecurringExpense::where('is_active', true)->where('description', $like, '%' . $needle . '%')->limit(5)->get();
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
        if ($d['recurring_frequency'] === RecurringExpense::MONTHLY && empty($d['recurring_day'])) {
            return 'Ayın kaçında yazayım?';
        }
        if ($d['recurring_frequency'] === RecurringExpense::WEEKLY && empty($d['recurring_day'])) {
            return 'Haftanın hangi günü yazayım?';
        }

        return null;
    }

    public static function summary(array $d): string
    {
        if (($d['kind'] ?? null) === ExpenseExtractor::KIND_RECURRING_STOP) {
            return "🛑 *Bunları durdurayım mı?* (yazılmış eski giderler silinmez)\n"
                . self::matches($d)->map(fn (RecurringExpense $r) => '• ' . $r->description . ' — ' . $r->label())->implode("\n");
        }
        $draft = self::model($d);
        $first = $draft->nextDate(Carbon::now(Reminder::TIMEZONE)->startOfDay());
        $category = self::categoryName($d);

        return "🔁 *Düzenli gider — kontrol et:*\n"
            . '• ' . trim((string) $d['description']) . ' — ' . $draft->label() . "\n"
            . ($category ? '• Kategori: ' . $category . "\n" : '')
            . '• İlk kayıt: ' . $first->format('d.m.Y') . ', sonra ' . RecurringExpense::scheduleText($draft->frequency, $draft->weekday, $draft->day_of_month)
            . ' kendiliğinden yazılır (' . ($draft->paid ? 'ödendi' : 'ödenmedi') . ' olarak)';
    }

    /** @return array{0:string,1:?int} */
    public static function commit(array $d, string $phone): array
    {
        if (($d['kind'] ?? null) === ExpenseExtractor::KIND_RECURRING_STOP) {
            $rows = self::matches($d);
            RecurringExpense::whereKey($rows->pluck('id'))->update(['is_active' => false]);

            return ['🛑 Durdurdum: ' . $rows->pluck('description')->implode(', ') . '. Bundan sonra yazılmaz; eskileri yerinde.', null];
        }
        $r = self::model($d);
        $r->fill([
            'expense_category_id' => self::categoryId($d),
            'created_by_phone' => Phone::normalize($phone),
            'source' => mb_substr((string) ($d['recurring_source'] ?? ''), 0, 1000) ?: null,
        ])->save();
        $today = Carbon::now(Reminder::TIMEZONE)->startOfDay();
        $written = $r->start_date->toDateString() <= $today->toDateString() ? $r->post($today) : 0; // takvim günü (sunucu UTC)
        $next = $r->fresh()->nextDate($today->copy()->addDay());

        return ['✅ Düzenli gider kuruldu: ' . $r->description . ' — ' . $r->label()
            . ($written > 0 ? "\n📝 Bugünkü yazıldı: " . Money::format((float) $r->amount) . ' ₺' : '')
            . "\nSıradaki: " . ($written > 0 ? $next : $r->fresh()->nextDate($today))->format('d.m.Y') . ' sabah kendiliğinden yazılır.'
            . "\nDurdurmak için *" . mb_strtolower(trim($r->description)) . ' giderini durdur* yaz.', null];
    }

    /** Kaydedilmemiş model (özet ve kayıt aynı hesabı kullansın). */
    private static function model(array $d): RecurringExpense
    {
        $frequency = in_array($d['recurring_frequency'] ?? null, array_keys(RecurringExpense::FREQUENCIES), true) ? $d['recurring_frequency'] : RecurringExpense::DAILY;
        $day = (int) ($d['recurring_day'] ?? 0);
        $start = ! empty($d['recurring_start']) ? Carbon::parse($d['recurring_start'], Reminder::TIMEZONE)->startOfDay()
            : Carbon::now(Reminder::TIMEZONE)->startOfDay()->addDay(); // söylenmediyse yarından (bugünkü elle yazılmış olabilir)

        return new RecurringExpense([
            'description' => mb_substr(trim((string) $d['description']), 0, 255),
            'amount' => round((float) $d['amount'], 2),
            'project_id' => $d['project_id'] ?? null,
            'frequency' => $frequency,
            'weekday' => $frequency === RecurringExpense::WEEKLY && $day >= 1 && $day <= 7 ? $day : null,
            'day_of_month' => $frequency === RecurringExpense::MONTHLY && $day >= 1 && $day <= 31 ? $day : null,
            'start_date' => $start->toDateString(),
            'paid' => ($d['paid'] ?? true) !== false,
            'is_active' => true,
        ]);
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
