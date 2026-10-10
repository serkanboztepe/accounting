<?php

namespace App\Filament\Pages;

use App\Models\HubPhone;
use App\Models\Reminder;
use App\Support\Agenda;
use App\Support\Phone;
use App\Tenancy\Tenancy;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * Takvim: firmanın hatırlatmaları (kim kurduysa adıyla — firma aracı, hatırlatmalar ortak), ödeme
 * günleri (ödenenler yok) ve çek vadeleri, gün gün. Salt görüntü; ekleme WhatsApp'tan.
 * WhatsApp'taki "bu hafta neler var?" ile aynı kaynak (Agenda) — ikisi hep aynı şeyi söyler.
 */
class Calendar extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|\UnitEnum|null $navigationGroup = 'Genel';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Takvim';

    protected static ?string $navigationLabel = 'Takvim';

    protected string $view = 'filament.pages.calendar';

    /** week | month | next_month */
    public string $range = 'week';

    /** @return array{0: Carbon, 1: Carbon} */
    public function period(): array
    {
        $today = Carbon::now(Reminder::TIMEZONE)->startOfDay();

        return match ($this->range) {
            'month' => [$today, $today->copy()->endOfMonth()],
            'next_month' => [$today->copy()->startOfMonth()->addMonth(), $today->copy()->startOfMonth()->addMonth()->endOfMonth()],
            default => [$today, $today->copy()->addDays(6)],
        };
    }

    /** @return array<string, list<array{time:?string, text:string, type:string, who:?string}>> */
    public function getDays(): array
    {
        [$from, $to] = $this->period();
        $names = Tenancy::current()
            ? HubPhone::where('hub_firm_id', Tenancy::current()->id)->pluck('name', 'phone')
            : collect();

        return Agenda::between($from, $to)
            ->map(fn (array $items) => array_map(fn (array $i) => $i + [
                'who' => $i['phone'] ? (trim((string) ($names[$i['phone']] ?? '')) ?: Phone::display($i['phone'])) : null,
            ], $items))
            ->all();
    }
}
