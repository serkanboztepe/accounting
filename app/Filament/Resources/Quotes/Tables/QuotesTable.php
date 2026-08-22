<?php

namespace App\Filament\Resources\Quotes\Tables;

use App\Filament\Resources\Contracts\ContractResource;
use App\Models\Quote;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class QuotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Başlık')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('party.name')
                    ->label('Müşteri / Cari')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('project.name')
                    ->label('Proje')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label('Tutar')
                    ->getStateUsing(fn (Quote $record) => $record->reportableTotal())
                    ->money('TRY')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Durum')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Quote::STATUSES[$state] ?? $state)
                    ->color(fn (?string $state) => match ($state) {
                        Quote::STATUS_ACCEPTED => 'success',
                        Quote::STATUS_SENT     => 'info',
                        Quote::STATUS_REJECTED, Quote::STATUS_EXPIRED => 'danger',
                        default                => 'gray',
                    }),
                TextColumn::make('quote_date')
                    ->label('Tarih')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('valid_until')
                    ->label('Geçerlilik')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('convert')
                    ->label('Sözleşmeye Dönüştür')
                    ->icon(Heroicon::OutlinedArrowRightCircle)
                    ->color('success')
                    ->visible(fn (Quote $record) => ! $record->isConverted())
                    ->requiresConfirmation()
                    ->modalHeading('Sözleşmeye Dönüştür')
                    ->modalDescription('Bu teklif bir satış sözleşmesine dönüştürülecek; kalemler ve tutar otomatik aktarılacak. Teklif "Kabul Edildi" olarak işaretlenir.')
                    ->modalSubmitActionLabel('Dönüştür')
                    ->action(function (Quote $record) {
                        $contract = $record->convertToContract();

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
                    ->url(fn (Quote $record) => route('quote.print', $record))
                    ->extraAttributes(fn (Quote $record) => \App\Support\Printing::iframeAttributes(route('quote.print', $record))),

                Action::make('viewContract')
                    ->label('Sözleşmeyi Aç')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->visible(fn (Quote $record) => $record->isConverted())
                    ->url(fn (Quote $record) => ContractResource::getUrl('edit', ['record' => $record->converted_contract_id]))
                    ->openUrlInNewTab(),

                EditAction::make(),
            ]);
    }
}
