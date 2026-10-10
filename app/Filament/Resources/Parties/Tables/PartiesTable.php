<?php

namespace App\Filament\Resources\Parties\Tables;

use Filament\Actions\DeleteAction;
use App\Models\Party;
use App\Support\PartyDeletion;
use Filament\Notifications\Notification;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PartiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('İsim')->searchable()->sortable(),
                TextColumn::make('phone')->label('Telefon')->searchable(),
                TextColumn::make('notes')->label('Notlar')->limit(50),
                TextColumn::make('created_at')->label('Oluşturulma')->dateTime()->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(fn (Party $record) => PartyDeletion::description($record))
                    ->before(function (Party $record, DeleteAction $action) {
                        if (PartyDeletion::blockers($record)) {
                            Notification::make()->danger()->title('Cari silinemedi')->body(PartyDeletion::description($record))->send();
                            $action->halt();
                        }
                    }),
            ]);
    }
}
