<?php

namespace App\Filament\Resources\Agreements\Pages;

use App\Filament\Resources\Agreements\AgreementResource;
use App\Models\Party;
use App\Support\AgreementDesk;
use App\Support\Forms\AgreementForm;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;

class ListAgreements extends ListRecords
{
    protected static string $resource = AgreementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Cari sayfasındaki "🤝 Anlaşma yap" ile aynı pencere; ilk alan cari.
            Action::make('newAgreement')
                ->label('Yeni anlaşma')
                ->icon('heroicon-o-plus')
                ->modalHeading('Yeni anlaşma')
                ->modalSubmitActionLabel('Kaydet')
                ->slideOver()
                ->modalWidth('3xl')
                ->fillForm(fn (): array => [
                    'direction'            => AgreementForm::lastDirection(null),
                    'agreement_date'       => now()->toDateString(),
                    'advance_payment_type' => 'cash',
                    'lines'                => [[]],
                ])
                ->schema(AgreementForm::createSchema(withParty: true))
                ->action(function (array $data, Action $action): void {
                    try {
                        $agreement = AgreementDesk::create(Party::findOrFail($data['party_id']), $data);
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                        $action->halt();
                    }
                    Notification::make()->success()->title('Anlaşma kaydedildi: ' . $agreement->title)->send();
                }),
        ];
    }
}
