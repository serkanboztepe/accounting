<?php

namespace App\Filament\Hub\HubFirms\Pages;

use App\Filament\Hub\HubFirms\HubFirmResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** Firma sayfası: gövde = numaralar; firma bilgileri ⚙ Ayarlar modalında. */
class EditHubFirm extends EditRecord
{
    protected static string $resource = HubFirmResource::class;

    public function getTitle(): string
    {
        return $this->record->name;
    }

    public function getBreadcrumbs(): array
    {
        return [HubFirmResource::getUrl('index') => 'Firmalar', $this->record->name];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('copySecret')
                ->label('Gizli anahtar')
                ->icon(Heroicon::OutlinedKey)
                ->color('gray')
                ->modalHeading('Gizli anahtar (HUB_SECRET)')
                ->modalDescription('Bu anahtar firmanın kurulumundaki .env dosyasında HUB_SECRET satırında olmalı.')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Kapat')
                ->schema([
                    \Filament\Forms\Components\TextInput::make('secret')
                        ->label('Anahtar')
                        ->default(fn () => $this->record->secret)
                        ->readOnly()
                        ->copyable(copyMessage: 'Kopyalandı'),
                ]),
            Action::make('settings')
                ->label('Ayarlar')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->modalHeading('Firma Bilgileri')
                ->modalSubmitActionLabel('Kaydet')
                ->fillForm(fn (): array => $this->record->only(['name', 'url', 'is_active']))
                ->schema(HubFirmResource::components())
                ->action(function (array $data) {
                    $this->record->update($data);
                    Notification::make()->success()->title('Kaydedildi')->send();
                }),
            DeleteAction::make(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getRelationManagersContentComponent(),
        ]);
    }
}
