<?php

namespace App\Filament\Resources\Checks\Tables;

use App\Filament\Resources\Checks\Schemas\CheckForm;
use App\Models\Check;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Çekler listesi (Faz 2.2): yön (verdiğim/aldığım), ne için, vadeye kaç gün kaldı; "yaklaşan / geçmiş" süzgeçleri;
 * satırda tek tık "Ödendi" / "Tahsil edildi" (sözleşmeye bağlı çekte sözleşme ödemesi de — ikisi senkron kalır); karşılıksız /
 * iptal durumu yok → "Çeki sil" bağlı ödeme/tahsilatla birlikte geri alır (CheckRemoval).
 */
class ChecksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('due_date')
            ->columns([
                TextColumn::make('direction')->label('Yön')->badge()
                    ->state(fn (Check $r) => CheckForm::directionOf($r->status) === 'received' ? 'Aldığım' : 'Verdiğim')
                    ->color(fn (string $state) => $state === 'Aldığım' ? 'success' : 'warning'),
                TextColumn::make('party.name')->label('Kişi / Firma')->placeholder('—')->searchable()->sortable(),
                TextColumn::make('purpose')->label('Ne için')->wrap()->placeholder('—')
                    ->state(fn (Check $r) => $r->description
                        ?: ($r->contractPayment ? $r->contractPayment->contract()->withoutGlobalScopes()->value('title') : null)
                        ?: $r->ledgerEntry?->description),
                TextColumn::make('amount')->label('Tutar')->money('TRY')->sortable(),
                TextColumn::make('due_date')->label('Vade')->date('d.m.Y')->sortable()
                    ->description(fn (Check $r) => self::daysLeft($r)),
                TextColumn::make('status')->label('Durum')->badge()
                    ->formatStateUsing(fn (string $state, Check $r) => CheckForm::STATES[CheckForm::directionOf($state)][$state]
                        ?? ['bounced' => 'Karşılıksız (eski kayıt)', 'cancelled' => 'İptal (eski kayıt)'][$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'paid', 'collected' => 'success', 'bounced' => 'danger', 'cancelled' => 'gray', default => 'warning'}),
                TextColumn::make('check_number')->label('Çek No')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('bank_name')->label('Banka')->toggleable(isToggledHiddenByDefault: true),
            ])
            // Süzgeç sorgusunun parametresi `$query` OLMALI (Filament adla verir): eskiden `$q` yazılıydı → dört süzgeç de boş sorguya
            // uygulanıyor, liste hiç süzülmüyordu (kullanıcı 26.10: Muhasebe'de ödenmiş 16 çek de geliyordu).
            ->filters([
                SelectFilter::make('state')->label('Durum')
                    ->options(['open' => 'Bekleyen (verildi / alındı)', 'done' => 'Ödenen / tahsil edilen', 'all' => 'Hepsi'])
                    ->default('open')
                    ->selectablePlaceholder(false)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? 'open') {
                        'done' => $query->whereIn('status', ['paid', 'collected']),
                        'all' => $query,
                        default => $query->whereIn('status', ['issued', 'portfolio']),
                    }),
                SelectFilter::make('dir')->label('Yön')
                    ->options(['given' => 'Verdiğim', 'received' => 'Aldığım'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'received' => $query->whereIn('status', ['portfolio', 'collected']),
                        'given' => $query->whereNotIn('status', ['portfolio', 'collected']),
                        default => $query,
                    }),
                Filter::make('upcoming')->label('Vadesi 7 gün içinde')
                    ->query(fn (Builder $query) => $query->whereIn('status', ['issued', 'portfolio'])
                        ->whereBetween('due_date', [Carbon::today(), Carbon::today()->addDays(7)])),
                Filter::make('overdue')->label('Vadesi geçmiş, bekleyen')
                    ->query(fn (Builder $query) => $query->whereIn('status', ['issued', 'portfolio'])->whereDate('due_date', '<', Carbon::today())),
            ])
            ->recordActions([
                Action::make('settle')
                    ->label(fn (Check $r) => $r->status === 'portfolio' ? 'Tahsil edildi' : 'Ödendi')
                    ->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (Check $r) => in_array($r->status, ['issued', 'portfolio'], true))
                    ->requiresConfirmation()
                    ->modalDescription(fn (Check $r) => ($r->party?->name ?? 'Çek') . ' · ' . \App\Support\Money::format((float) $r->amount) . ' ₺'
                        . ' — para çek verildiği/alındığı gün hesaba yazılmıştı; burada yalnız çekin durumu değişir.')
                    ->action(function (Check $r) {
                        self::settle($r);
                        Notification::make()->success()->title('Çek işaretlendi')->send();
                    }),
                EditAction::make(),
                Action::make('remove')->label('Çeki sil')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Çeki sil (karşılıksız / iptal)')
                    ->modalDescription(fn (Check $r) => \App\Support\CheckRemoval::describe($r))
                    ->action(function (Check $r) {
                        \App\Support\CheckRemoval::remove($r);
                        Notification::make()->success()->title('Çek silindi')->send();
                    }),
            ]);
    }

    /** Ödendi / tahsil edildi. Sözleşmeye bağlı çekte durum sözleşme ödemesinden gelir → önce o, çek senkron olur. */
    public static function settle(Check $check): void
    {
        $target = $check->status === 'portfolio' ? 'collected' : 'paid';
        if ($target === 'paid' && $check->contractPayment) {
            $check->contractPayment->update(['status' => 'paid']);
        }
        if ($check->fresh()->status !== $target) {
            $check->update(['status' => $target]);
        }
    }

    private static function daysLeft(Check $r): ?string
    {
        if (! $r->due_date || ! in_array($r->status, ['issued', 'portfolio'], true)) {
            return null;
        }
        $days = (int) Carbon::today()->diffInDays($r->due_date->copy()->startOfDay(), false);

        return match (true) {
            $days > 0 => $days . ' gün kaldı',
            $days === 0 => 'bugün',
            default => abs($days) . ' gün geçti',
        };
    }
}
