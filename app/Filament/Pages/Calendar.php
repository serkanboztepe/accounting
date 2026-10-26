<?php

namespace App\Filament\Pages;

use App\Models\ExpenseCategory;
use App\Models\HubPhone;
use App\Models\Project;
use App\Models\Reminder;
use App\Support\Agenda;
use App\Support\Forms\MoneyInput;
use App\Support\Labels;
use App\Support\Money;
use App\Support\Phone;
use App\Tenancy\Tenancy;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Takvim: firmanın hatırlatmaları (kim kurduysa adıyla — firma aracı, hatırlatmalar ortak), ödeme
 * günleri (ödenenler yok) ve çek vadeleri, gün gün. WhatsApp'taki "bu hafta neler var?" ile aynı kaynak (Agenda).
 * Hatırlatma buradan da eklenir / düzeltilir / silinir; "Düzenli gider" işaretliyse günü gelince gider yazılır
 * (ayrı tablo/menü yok — kullanıcı kararı 19.10).
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

    private const REPEATS = ['' => 'Tekrar yok', 'daily' => 'Her gün', 'weekly' => 'Her hafta', 'monthly' => 'Her ay', 'yearly' => 'Her yıl'];

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
        $names = $this->phoneNames();

        return Agenda::between($from, $to)
            ->map(fn (array $items) => array_map(fn (array $i) => $i + [
                'who' => $i['phone'] ? (trim((string) ($names[$i['phone']] ?? '')) ?: Phone::display($i['phone'])) : null,
            ], $items))
            ->all();
    }

    /** Bekleyen hatırlatmalar (düzenli giderler dahil) — düzelt / sil listesi. */
    public function getReminders(): Collection
    {
        return Reminder::with(['category', 'project'])->where('status', 'active')->orderBy('next_fire_at')->orderBy('id')->get();
    }

    public function whoName(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        return trim((string) ($this->phoneNames()[$phone] ?? '')) ?: Phone::display($phone);
    }

    private function phoneNames(): Collection
    {
        return Tenancy::current()
            ? HubPhone::where('hub_firm_id', Tenancy::current()->id)->pluck('name', 'phone')
            : collect();
    }

    protected function getHeaderActions(): array
    {
        $fill = fn () => ['event_date' => Carbon::now(Reminder::TIMEZONE)->toDateString(), 'phone' => $this->phoneOptions()->keys()->first()];

        return array_values(array_filter([
            \App\Support\Features::expenses() ? Action::make('addExpense')
                ->label('Düzenli gider ekle')
                ->icon(Heroicon::OutlinedArrowPath)
                ->modalHeading('Düzenli gider ekle')
                ->modalDescription('Günü gelince sabah 08:00\'de gider olarak yazılır ve WhatsApp\'tan haber verilir ("iptal" yazınca o günkü silinir).')
                ->schema(fn () => $this->reminderForm(true))
                ->fillForm(fn () => $fill() + ['repeat' => 'monthly'])
                ->action(function (array $data) {
                    $this->saveReminder(new Reminder(['status' => 'active']), $data, true);
                    Notification::make()->title('Düzenli gider eklendi')->success()->send();
                }) : null,
            Action::make('addReminder')
                ->label('Hatırlatma ekle')
                ->icon(Heroicon::OutlinedBell)
                ->color('gray')
                ->modalHeading('Hatırlatma ekle')
                ->schema(fn () => $this->reminderForm(false))
                ->fillForm($fill)
                ->action(function (array $data) {
                    $this->saveReminder(new Reminder(['status' => 'active']), $data, false);
                    Notification::make()->title('Hatırlatma eklendi')->success()->send();
                }),
        ]));
    }

    public function editReminderAction(): Action
    {
        $expense = fn (array $arguments) => (bool) Reminder::find($arguments['id'] ?? 0)?->isExpense();

        return Action::make('editReminder')
            ->label('Düzelt')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->iconButton()
            ->color('gray')
            ->modalHeading(fn (array $arguments) => $expense($arguments) ? 'Düzenli gideri düzelt' : 'Hatırlatmayı düzelt')
            ->schema(fn (array $arguments) => $this->reminderForm($expense($arguments)))
            ->fillForm(function (array $arguments) {
                $r = Reminder::findOrFail($arguments['id']);

                return [
                    'text' => $r->text,
                    'amount' => $r->amount,
                    'expense_category_id' => $r->expense_category_id,
                    'project_id' => $r->project_id,
                    'event_date' => $r->event_date?->toDateString(),
                    'event_time' => $r->isExpense() ? null : $r->event_time,
                    'repeat' => $r->repeat ?? '',
                    'phone' => $r->phone,
                ];
            })
            ->action(function (array $data, array $arguments) use ($expense) {
                $this->saveReminder(Reminder::findOrFail($arguments['id']), $data, $expense($arguments));
                Notification::make()->title('Kaydedildi')->success()->send();
            });
    }

    public function deleteReminderAction(): Action
    {
        return Action::make('deleteReminder')
            ->label('Sil')
            ->icon(Heroicon::OutlinedTrash)
            ->iconButton()
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Sil')
            ->modalDescription('Bundan sonra hatırlatılmaz / gider yazılmaz. Daha önce yazılmış giderler silinmez.')
            ->action(function (array $arguments) {
                \App\Models\ActivityLog::updateQuery(Reminder::whereKey($arguments['id']), ['status' => 'cancelled', 'next_fire_at' => null]);
                Notification::make()->title('Silindi')->success()->send();
            });
    }

    /** Düzenli gider: ne, tutar, kategori, şube, ilk gün, tekrar. Hatırlatma: ne, tarih, saat, tekrar. İkisinde de kime gideceği. */
    private function reminderForm(bool $expense): array
    {
        $phones = $this->phoneOptions();

        return array_values(array_filter([
            TextInput::make('text')->label($expense ? 'Ne gideri?' : 'Ne?')
                ->placeholder($expense ? 'Kira, çobanlara ekmek…' : 'Kredi kartı ödemesi, teslimat…')->required()->maxLength(255),
            $expense ? MoneyInput::make('amount', 'Her seferinde tutar') : null,
            $expense ? Select::make('expense_category_id')->label('Kategori')
                ->options(fn () => ExpenseCategory::orderBy('name')->pluck('name', 'id'))->searchable() : null,
            $expense && \App\Support\Features::places()
                ? Select::make('project_id')->label(Labels::project())->options(fn () => Project::orderBy('name')->pluck('name', 'id'))->searchable()
                : null,
            DatePicker::make('event_date')->label($expense ? 'İlk gün' : 'Tarih')->required(),
            $expense ? null : TimePicker::make('event_time')->label('Saat')->seconds(false)
                ->helperText('Boşsa bir gün önce ve o gün 08:00\'de hatırlatılır.'),
            Select::make('repeat')->label($expense ? 'Ne sıklıkla?' : 'Tekrar')
                ->options($expense ? array_slice(self::REPEATS, 1, null, true) : self::REPEATS)
                ->default($expense ? 'monthly' : '')->selectablePlaceholder(false)->required($expense),
            $phones->isNotEmpty()
                ? Select::make('phone')->label($expense ? 'Haber kime gitsin (WhatsApp)' : 'Kime gitsin (WhatsApp)')->options($phones)->required()
                : TextInput::make('phone')->label('WhatsApp numarası')->tel()->required(),
        ]));
    }

    /** Firmanın etkin WhatsApp numaraları: normalize numara → ad. */
    private function phoneOptions(): Collection
    {
        if (! Tenancy::current()) {
            return collect();
        }

        return HubPhone::where('hub_firm_id', Tenancy::current()->id)->where('is_active', true)->orderBy('name')->get()
            ->mapWithKeys(fn (HubPhone $p) => [Phone::normalize($p->phone) => trim((string) $p->name) ?: Phone::display($p->phone)]);
    }

    private function saveReminder(Reminder $r, array $data, bool $expense): void
    {
        $repeat = ($data['repeat'] ?? '') ?: null;
        $r->fill([
            'phone' => Phone::normalize((string) $data['phone']),
            'text' => trim((string) $data['text']),
            'event_date' => $data['event_date'],
            // Düzenli gider sabah 08:00'de tek sefer; saatli hatırlatma tam saatinde; saatsiz: bir gün önce + o gün 08:00.
            'event_time' => $expense ? sprintf('%02d:00', Reminder::DEFAULT_HOUR) : (($data['event_time'] ?? null) ? substr($data['event_time'], 0, 5) : null),
            'is_alarm' => $expense || ! empty($data['event_time']),
            'lead_minutes' => null,
            'repeat' => $repeat,
            'anchor_day' => in_array($repeat, ['monthly', 'yearly'], true) ? Carbon::parse($data['event_date'])->day : null,
            'amount' => $expense ? Money::parse($data['amount']) : null,
            'expense_category_id' => $expense ? ($data['expense_category_id'] ?? null) : null,
            'project_id' => $expense ? ($data['project_id'] ?? null) : null,
            'status' => 'active',
        ]);
        $today = Carbon::now(Reminder::TIMEZONE)->startOfDay();
        // Düzenli gider bugünden başlıyor ve 08:00 geçtiyse bugünkü hemen yazılır (WhatsApp'taki "bugünden" gibi).
        $postToday = $expense && ! $r->exists && Carbon::parse($data['event_date'])->toDateString() === $today->toDateString()
            && Carbon::now(Reminder::TIMEZONE)->hour >= Reminder::DEFAULT_HOUR;
        $r->scheduleNext($postToday ? $today->copy()->endOfDay() : now());
        $r->save();
        if ($postToday) {
            $r->postExpense($today);
        }
    }
}
