<?php

namespace App\Filament\Resources\Quotes\Pages;

use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\Quote;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditQuote extends EditRecord
{
    protected static string $resource = QuoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('convert')
                ->label('Sözleşmeye Dönüştür')
                ->icon(Heroicon::OutlinedArrowRightCircle)
                ->color('success')
                ->visible(fn () => ! $this->record->isConverted())
                ->requiresConfirmation()
                ->modalHeading('Sözleşmeye Dönüştür')
                ->modalDescription('Bu teklif bir satış sözleşmesine dönüştürülecek; kalemler ve tutar otomatik aktarılacak. Teklif "Kabul Edildi" olarak işaretlenir.')
                ->modalSubmitActionLabel('Dönüştür')
                ->action(function () {
                    /** @var Quote $quote */
                    $quote    = $this->record;
                    $contract = $quote->convertToContract();

                    Notification::make()
                        ->success()
                        ->title('Sözleşme oluşturuldu')
                        ->body('Teklif satış sözleşmesine dönüştürüldü.')
                        ->send();

                    return redirect(ContractResource::getUrl('edit', ['record' => $contract]));
                }),

            Action::make('print')
                ->label('Yazdır')
                ->icon(Heroicon::OutlinedPrinter)
                ->url(fn () => route('quote.print', $this->record))
                ->extraAttributes(fn () => \App\Support\Printing::iframeAttributes(route('quote.print', $this->record))),

            Action::make('viewContract')
                ->label('Sözleşmeyi Aç')
                ->icon(Heroicon::OutlinedDocumentText)
                ->visible(fn () => $this->record->isConverted())
                ->url(fn () => ContractResource::getUrl('edit', ['record' => $this->record->converted_contract_id]))
                ->openUrlInNewTab(),

            DeleteAction::make(),
        ];
    }
}
