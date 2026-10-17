<?php

namespace App\Filament\Resources\Checks\Pages;

use App\Filament\Resources\Checks\CheckResource;
use App\Support\CheckRemoval;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditCheck extends EditRecord
{
    protected static string $resource = CheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Silme bağlı ödeme/tahsilatı da geri alır (yalnız çeki silmek bakiyeyi yanlış bırakırdı).
            Action::make('remove')->label('Çeki sil')->icon(Heroicon::OutlinedTrash)->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Çeki sil (karşılıksız / iptal)')
                ->modalDescription(fn () => CheckRemoval::describe($this->record))
                ->action(function () {
                    CheckRemoval::remove($this->record);
                    Notification::make()->success()->title('Çek silindi')->send();
                    $this->redirect(CheckResource::getUrl('index'));
                }),
        ];
    }
}
