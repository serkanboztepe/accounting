<?php

namespace App\Support;

use App\Models\Check;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Vadesi gelen / yaklaşan çekler: verdiğimiz (henüz ödenmemiş = issued) ve müşteriden aldığımız (henüz tahsil
 * edilmemiş = portfolio). Aldığımız çek de hatırlatılır (Yıldız 23.10: bankaya vermeyi unutmasın).
 */
class CheckReminders
{
    public const TIMEZONE = 'Europe/Istanbul';

    public static function today(): CarbonInterface
    {
        return now(self::TIMEZONE)->startOfDay();
    }

    /** Verdiğimiz, henüz ödenmemiş çekler ("çek ödendi" eşleştirmesi bunlardan). */
    public static function pending(): Builder
    {
        return Check::query()
            ->where('status', 'issued')
            // Verdiğimiz çek: sözleşme/Çekler ekranından (bağsız) ya da WhatsApp'ta ödemeye bağlı (Faz F). Alınan müşteri
            // çeki tahsilata bağlıdır ve hatırlatılmaz.
            ->where(fn ($q) => $q->whereNull('party_ledger_entry_id')
                ->orWhereHas('ledgerEntry', fn ($l) => $l->where('type', \App\Models\PartyLedgerEntry::TYPE_PAYMENT)))
            ->whereNotNull('due_date');
    }

    /** Hatırlatılacak tüm çekler: verdiğimiz ödenmemiş + aldığımız tahsil edilmemiş. */
    public static function upcoming(): Builder
    {
        return Check::query()->whereIn('status', ['issued', 'portfolio'])->whereNotNull('due_date');
    }

    /** Aldığımız çek mi: elde bekleyen (portfolio) ya da tahsilat satırına bağlı. */
    public static function isReceived(Check $check): bool
    {
        return $check->status === 'portfolio'
            || $check->ledgerEntry?->type === \App\Models\PartyLedgerEntry::TYPE_COLLECTION;
    }

    /**
     * Kişisiz "çek ödendi" için bekleyen verilmiş çek: tutar tutan (±1 ₺), vadesi 45 gün öncesi ile 10 gün sonrası arasında;
     * birden çoksa vadesi bugüne en yakını. Yoksa null.
     */
    public static function matchDue(float $amount): ?Check
    {
        $today = self::today();

        return self::pending()->with('party')->whereBetween('due_date', [$today->copy()->subDays(45)->toDateString(), $today->copy()->addDays(10)->toDateString()])
            ->get()->filter(fn (Check $c) => abs((float) $c->amount - $amount) < 1)
            ->sortBy(fn (Check $c) => abs($c->due_date->diffInDays($today, false)))->first();
    }

    /** @return Collection<int, Check> */
    public static function dueOn(CarbonInterface $date): Collection
    {
        return self::upcoming()->whereDate('due_date', $date->toDateString())->get();
    }

    /** "çekler" cevabı: gecikmiş + önümüzdeki $days gün; verdiklerin ve aldıkların ayrı; en fazla $limit satır. */
    public static function listText(int $days = 14, int $limit = 15): string
    {
        $today = self::today();
        $checks = self::upcoming()
            ->whereDate('due_date', '<=', $today->copy()->addDays($days)->toDateString())
            ->with(['party', 'ledgerEntry'])
            ->orderBy('due_date')
            ->get();

        if ($checks->isEmpty()) {
            return "Önümüzdeki {$days} günde vadesi gelen çekin yok. ✅";
        }

        $line = function (Check $c) use ($today) {
            $late = $c->due_date->lt($today) ? ' ⚠️ gecikmiş' : '';
            $who = $c->party?->name ?? 'Kişi yok';
            $no = $c->check_number ? " (No {$c->check_number})" : '';

            return "• {$c->due_date->format('d.m')} — {$who} — " . Money::format($c->amount) . " ₺{$no}{$late}";
        };
        [$received, $given] = $checks->partition(fn (Check $c) => self::isReceived($c));
        $sections = [];
        $left = $limit;
        foreach ([[$given, '📤 *Verdiğin çekler*'], [$received, '📥 *Aldığın çekler* (tahsil edilecek)']] as [$group, $title]) {
            if ($group->isEmpty()) {
                continue;
            }
            $shown = $group->take(max(0, $left));
            $left -= $shown->count();
            $more = $group->count() > $shown->count() ? "\n…ve " . ($group->count() - $shown->count()) . ' çek daha' : '';
            $sections[] = ($received->isEmpty() || $given->isEmpty() ? '' : $title . "\n")
                . $shown->map($line)->implode("\n") . $more
                . ($received->isEmpty() || $given->isEmpty() ? "\n\n" : "\n") . 'Toplam: *' . Money::format($group->sum('amount')) . ' ₺*';
        }

        return "📅 *Vadesi yaklaşan çeklerin* (bugün + {$days} gün)\n"
            . ($received->isNotEmpty() && $given->isEmpty() ? "📥 Aldığın çekler (tahsil edilecek)\n" : '')
            . implode("\n\n", $sections);
    }
}
