<?php

namespace App\Filament\Hub\HubFirms;

use App\Models\HubFirm;
use App\Models\HubLedgerEntry;
use App\Support\FirmPlan;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * Firma sayfası "Abonelik" menüsü (29.10): plan · ödedi · ödeme yok, durdur / aç. Durdurma ELLE (kullanıcı kararı: kendiliğinden
 * kapatma yok — ödemeyi elden alıp girmeyi unutmuş olabilir). Durdurulan firma: WhatsApp ve giriş FirmPlan::SUSPENDED_REPLY;
 * veri silinmez, kendiliğinden mesajlar (pasif firma gibi) gitmez.
 */
final class PlanActions
{
    public static function group(HubFirm $firm): ActionGroup
    {
        return ActionGroup::make([self::plan($firm), self::paid($firm), self::suspend($firm), self::resume($firm)])
            ->label('Abonelik · ' . FirmPlan::status($firm)[1])
            ->icon(Heroicon::OutlinedCreditCard)
            ->color(FirmPlan::status($firm)[0])
            ->button();
    }

    private static function plan(HubFirm $firm): Action
    {
        return Action::make('plan')
            ->label('Plan')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->modalHeading('Abonelik planı')
            ->fillForm(fn () => ['plan' => $firm->plan, 'plan_amount' => $firm->plan_amount, 'plan_day' => $firm->plan_day,
                'plan_started_on' => $firm->plan_started_on?->toDateString(), 'trial_ends_on' => $firm->trial_ends_on?->toDateString()])
            ->schema([
                Select::make('plan')->label('Plan')->options(FirmPlan::PLANS)->required()->live()->native(false),
                TextInput::make('plan_amount')->label(fn (Get $get) => $get('plan') === 'yearly' ? 'Yıllık ücret (₺)' : 'Aylık ücret (₺)')
                    ->numeric()->minValue(0)->required()->visible(fn (Get $get) => in_array($get('plan'), ['monthly', 'yearly'], true)),
                DatePicker::make('plan_started_on')->label(fn (Get $get) => $get('plan') === 'yearly' ? 'Başlangıç (her yıl bu gün yenilenir)' : 'İlk ücretli ay')
                    ->required()->visible(fn (Get $get) => in_array($get('plan'), ['monthly', 'yearly'], true)),
                TextInput::make('plan_day')->label('Ayın kaçında ödenir')->numeric()->minValue(1)->maxValue(31)
                    ->helperText('Boş = başlangıç günü')->visible(fn (Get $get) => $get('plan') === 'monthly'),
                DatePicker::make('trial_ends_on')->label('Deneme bitişi')->required()->visible(fn (Get $get) => $get('plan') === 'trial'),
            ])
            ->action(function (array $data) use ($firm) {
                $paid = in_array($data['plan'], ['monthly', 'yearly'], true);
                $firm->update([
                    'plan' => $data['plan'],
                    'plan_amount' => $paid ? (float) $data['plan_amount'] : null,
                    'plan_started_on' => $paid ? $data['plan_started_on'] : null,
                    'plan_day' => $data['plan'] === 'monthly' && ! empty($data['plan_day']) ? (int) $data['plan_day'] : null,
                    'trial_ends_on' => $data['plan'] === 'trial' ? $data['trial_ends_on'] : null,
                ]);
                Notification::make()->success()->title('Plan kaydedildi')->send();
            });
    }

    private static function paid(HubFirm $firm): Action
    {
        return Action::make('paid')
            ->label('Ödedi')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn () => FirmPlan::isPaidPlan($firm) && FirmPlan::nextUnpaid($firm) !== null)
            ->modalHeading(fn () => 'Ödeme: ' . FirmPlan::periodLabel($firm, FirmPlan::nextUnpaid($firm)))
            ->modalDescription('İlk ödenmemiş dönem kapanır (gecikmiş olan önce).')
            ->fillForm(fn () => ['amount' => $firm->plan_amount, 'paid_on' => now('Europe/Istanbul')->toDateString()])
            ->schema([
                TextInput::make('amount')->label('Tutar (₺)')->numeric()->minValue(0.01)->required(),
                DatePicker::make('paid_on')->label('Ödendiği gün')->required(),
                TextInput::make('note')->label('Not')->placeholder('ör. havale, elden'),
            ])
            ->action(function (array $data) use ($firm) {
                $p = FirmPlan::nextUnpaid($firm);
                if (! $p) {
                    return;
                }
                HubLedgerEntry::create(['kind' => HubLedgerEntry::PAYMENT, 'hub_firm_id' => $firm->id, 'amount' => (float) $data['amount'],
                    'currency' => 'TRY', 'starts_on' => $p[0]->toDateString(), 'ends_on' => $p[1]->toDateString(),
                    'paid_on' => $data['paid_on'], 'note' => $data['note'] ?? null]);
                Notification::make()->success()->title(FirmPlan::periodLabel($firm, $p) . ' ödendi — ' . Money::format((float) $data['amount']) . ' ₺')->send();
            });
    }

    private static function suspend(HubFirm $firm): Action
    {
        return Action::make('suspend')
            ->label('Ödeme yok — durdur')
            ->icon(Heroicon::OutlinedPauseCircle)
            ->color('danger')
            ->visible(fn () => $firm->is_active && $firm->suspended_at === null)
            ->requiresConfirmation()
            ->modalHeading('Firmayı durdur')
            ->modalDescription("WhatsApp'tan yazınca ve panele girince \"ödeme bekliyor\" mesajı görürler; kendiliğinden giden mesajlar durur. Veri SİLİNMEZ — 'Aç' ile kaldığı yerden devam eder.")
            ->modalSubmitActionLabel('Durdur')
            ->action(function () use ($firm) {
                $firm->update(['is_active' => false, 'suspended_at' => now()]);
                Notification::make()->warning()->title($firm->name . ' durduruldu')->send();
            });
    }

    private static function resume(HubFirm $firm): Action
    {
        return Action::make('resume')
            ->label('Aç')
            ->icon(Heroicon::OutlinedPlayCircle)
            ->color('success')
            ->visible(fn () => $firm->suspended_at !== null)
            ->requiresConfirmation()
            ->modalHeading('Firmayı aç')
            ->modalDescription(fn () => 'Durdurulma: ' . Carbon::parse($firm->suspended_at)->timezone('Europe/Istanbul')->format('d.m.Y H:i') . '. Açılınca her şey kaldığı yerden devam eder.')
            ->action(function () use ($firm) {
                $firm->update(['is_active' => true, 'suspended_at' => null]);
                Notification::make()->success()->title($firm->name . ' açıldı')->send();
            });
    }
}
