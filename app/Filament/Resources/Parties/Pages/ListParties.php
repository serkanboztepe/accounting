<?php

namespace App\Filament\Resources\Parties\Pages;

use App\Filament\Pages\CariUnlock;
use App\Filament\Resources\Parties\PartyResource;
use App\Support\CariLock;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListParties extends ListRecords
{
    protected static string $resource = PartyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('lockCari')
                ->label('Kilitle')
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('gray')
                ->visible(fn () => CariLock::enabled())
                ->action(function () {
                    CariLock::lock();
                    $this->redirect(CariUnlock::getUrl());
                }),
            CreateAction::make(),
        ];
    }
}
