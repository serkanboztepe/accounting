<?php

namespace App\Filament\Resources\Parties\Tables;

use Filament\Actions\DeleteAction;
use App\Models\Party;
use App\Support\Money;
use App\Support\PartyDeletion;
use App\Support\PartyStatement;
use Filament\Notifications\Notification;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use WeakMap;

class PartiesTable
{
    /** Satır başına ekstre bakiyesi bir kez hesaplansın (iki sütun aynı kaydı okur). */
    private static ?WeakMap $balances = null;

    private static function balance(Party $party): float
    {
        self::$balances ??= new WeakMap();

        return self::$balances[$party] ??= (float) PartyStatement::build($party)['balance'];
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('İsim')->searchable()->sortable()
                    ->description(fn (Party $record) => $record->is_employee
                        ? '👷 Personel' . ($record->salary_amount ? ' · maaş ' . Money::format((float) $record->salary_amount) . ' ₺' : '')
                            . ($record->salary_day ? ' · ayın ' . $record->salary_day . "'i" : '')
                        : null),
                TextColumn::make('phone')->label('Telefon')->searchable(),
                // Ekstre bakiyesi (PartyStatement): > 0 cari bize borçlu, < 0 biz cariye borçluyuz.
                TextColumn::make('balance_status')->label('Durum')->badge()
                    ->state(fn (Party $record) => match (true) {
                        self::balance($record) >= 0.01 => 'Borçlu',
                        self::balance($record) <= -0.01 => 'Alacaklı',
                        default => 'Kapalı',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'Borçlu' => 'success',
                        'Alacaklı' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('balance_amount')->label('Tutar')
                    ->state(fn (Party $record) => abs(self::balance($record)) >= 0.01 ? abs(self::balance($record)) : null)
                    ->formatStateUsing(fn ($state) => Money::format($state) . ' ₺')
                    ->placeholder('—')->weight('medium')->alignEnd(),
                TextColumn::make('notes')->label('Notlar')->limit(50),
                TextColumn::make('created_at')->label('Oluşturulma')->dateTime()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_employee')->label('Personel'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(fn (Party $record) => PartyDeletion::description($record))
                    // Tek tıkla carinin tüm hareketleri gitmesin (kullanıcı 21.10): "Eminim Sil" yazılmadan silinmez.
                    ->schema(fn (Party $record) => PartyDeletion::blockers($record) ? [] : [PartyDeletion::confirmField()])
                    ->modalSubmitActionLabel('Cariyi sil')
                    // Veritabanı reddederse 500 değil, nedeni söylenir (PartyDeletion::delete).
                    ->using(fn (Party $record): bool => PartyDeletion::delete($record))
                    ->before(function (Party $record, DeleteAction $action) {
                        if (PartyDeletion::blockers($record)) {
                            Notification::make()->danger()->title('Cari silinemedi')->body(PartyDeletion::description($record))->send();
                            $action->halt();
                        }
                    }),
            ]);
    }
}
