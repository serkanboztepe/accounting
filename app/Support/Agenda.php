<?php

namespace App\Support;

use App\Models\Reminder;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Takvim (WhatsApp "yarın ne var? / 20 Ekim boş mu?" + panel Takvim): bir aralıktaki hatırlatmalar
 * (tekrarlılar dahil), ödeme günleri (DueItems — ödenenler yok) ve çek vadeleri, gün gün.
 */
class Agenda
{
    /**
     * @return Collection<string, list<array{time:?string, text:string, type:string}>> tarih (Y-m-d) => kalemler
     */
    public static function between(CarbonInterface $from, CarbonInterface $to, ?string $phone = null): Collection
    {
        $items = collect();

        $reminders = Reminder::query()->where('status', 'active')
            ->when($phone, fn ($q) => $q->where('phone', Phone::normalize($phone)))
            ->get();
        foreach ($reminders as $r) {
            foreach ($r->occurrencesBetween($from, $to) as $day) {
                $items->push(['date' => $day->toDateString(), 'time' => $r->event_time ? substr($r->event_time, 0, 5) : null,
                    'text' => $r->text, 'type' => 'reminder']);
            }
        }

        foreach (DueItems::between($from, $to) as $due) {
            $items->push(['date' => $due['date'], 'time' => null, 'text' => DueItems::line($due), 'type' => $due['kind']]);
        }

        if (config('modules.checks')) {
            foreach (CheckReminders::pending()->with('party')->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])->get() as $c) {
                $items->push(['date' => $c->due_date->toDateString(), 'time' => null,
                    'text' => 'Çek: ' . ($c->party?->name ?? 'cari yok') . ' ' . Money::format((float) $c->amount) . ' ₺', 'type' => 'check']);
            }
        }

        return $items
            ->sortBy(fn ($i) => $i['date'] . ' ' . ($i['time'] ?? '00:00'))
            ->groupBy('date')
            ->map(fn ($day) => $day->map(fn ($i) => ['time' => $i['time'], 'text' => $i['text'], 'type' => $i['type']])->values()->all());
    }

    /** WhatsApp cevabı. Tek gün ve boşsa "boş görünüyor". */
    public static function text(?string $fromDate, ?string $toDate, ?string $phone = null): string
    {
        $tz = Reminder::TIMEZONE;
        $from = $fromDate ? Carbon::parse($fromDate, $tz) : Carbon::now($tz)->startOfDay();
        $to = $toDate ? Carbon::parse($toDate, $tz) : $from->copy()->addDays(6);
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        $to = $to->min($from->copy()->addDays(31)); // en fazla bir ay

        $days = self::between($from, $to, $phone);
        $single = $from->isSameDay($to);
        $label = fn (Carbon $d) => $d->locale('tr')->translatedFormat('j F l');

        if ($days->isEmpty()) {
            return $single
                ? '✅ ' . $label($from) . ' boş görünüyor (hatırlatma, ödeme ya da çek yok).'
                : '✅ ' . $from->locale('tr')->translatedFormat('j F') . ' – ' . $to->locale('tr')->translatedFormat('j F') . ' arası boş görünüyor.';
        }

        $lines = ['📅 *' . ($single ? $label($from) : $from->locale('tr')->translatedFormat('j F') . ' – ' . $to->locale('tr')->translatedFormat('j F')) . '*'];
        foreach ($days as $date => $items) {
            if (! $single) {
                $lines[] = '';
                $lines[] = '*' . $label(Carbon::parse($date, $tz)) . '*';
            }
            foreach ($items as $i) {
                $lines[] = '• ' . ($i['time'] ? $i['time'] . ' ' : '') . $i['text'];
            }
        }

        return implode("\n", $lines);
    }
}
