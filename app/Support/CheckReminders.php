<?php

namespace App\Support;

use App\Models\Check;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Vadesi gelen / yaklaşan çeklerimiz (bizim verdiğimiz, henüz ödenmemiş = issued).
 * Müşteriden alınan çekler (tahsilat satırına bağlı) hariç.
 */
class CheckReminders
{
    public const TIMEZONE = 'Europe/Istanbul';

    public static function today(): CarbonInterface
    {
        return now(self::TIMEZONE)->startOfDay();
    }

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
        return self::pending()->whereDate('due_date', $date->toDateString())->get();
    }

    /** "çekler" cevabı: gecikmiş + önümüzdeki $days gün; en fazla $limit satır. */
    public static function listText(int $days = 14, int $limit = 15): string
    {
        $today = self::today();
        $checks = self::pending()
            ->whereDate('due_date', '<=', $today->copy()->addDays($days)->toDateString())
            ->with('party')
            ->orderBy('due_date')
            ->get();

        if ($checks->isEmpty()) {
            return "Önümüzdeki {$days} günde vadesi gelen çekin yok. ✅";
        }

        $lines = $checks->take($limit)->map(function (Check $c) use ($today) {
            $late = $c->due_date->lt($today) ? ' ⚠️ gecikmiş' : '';
            $who = $c->party?->name ?? 'Cari yok';
            $no = $c->check_number ? " (No {$c->check_number})" : '';

            return "• {$c->due_date->format('d.m')} — {$who} — " . Money::format($c->amount) . " ₺{$no}{$late}";
        });

        $more = $checks->count() > $limit ? "\n…ve " . ($checks->count() - $limit) . ' çek daha' : '';

        return "📅 *Vadesi yaklaşan çeklerin* (bugün + {$days} gün)\n"
            . $lines->implode("\n") . $more
            . "\n\nToplam: *" . Money::format($checks->sum('amount')) . ' ₺*';
    }
}
