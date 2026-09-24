<?php

namespace App\Filament\Resources\PropertyTaxProjects\Pages;

use App\Filament\Resources\PropertyTaxProjects\PropertyTaxProjectResource;
use App\Models\PropertyTaxUnit;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditPropertyTaxProject extends EditRecord
{
    protected static string $resource = PropertyTaxProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('distributeShares')
                ->label('Hisseleri Eşit Böl')
                ->icon(Heroicon::OutlinedScale)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Hisseleri Eşit Böl')
                ->modalDescription('Her dairenin hissesi, o daireye atanmış mükellefler arasında EŞİT bölünür (N mükellef → her biri 1/N; tek mükellef → 1/1 TAM). Mevcut hisse değerleri güncellenir.')
                ->modalSubmitActionLabel('Eşit Böl')
                ->action(fn () => $this->distributeSharesEqually()),

            Action::make('allFormatliPdf')
                ->label('Tümü — Formatlı PDF')
                ->icon(Heroicon::OutlinedDocumentCheck)
                ->color('danger')
                ->url(fn () => route('property-tax.project.formatli-pdf', $this->record), shouldOpenInNewTab: true),

            Action::make('allPdf')
                ->label('Tümü — PDF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('info')
                ->url(fn () => route('property-tax.project.pdf', $this->record), shouldOpenInNewTab: true),

            DeleteAction::make(),
        ];
    }

    private function distributeSharesEqually(): void
    {
        $units = PropertyTaxUnit::whereHas('block', fn ($q) => $q->where('property_tax_project_id', $this->record->id))
            ->with('allocations')
            ->get();

        $count = 0;
        foreach ($units as $unit) {
            $n = $unit->allocations->count();
            if ($n === 0) {
                continue;
            }
            foreach ($unit->allocations as $alloc) {
                $alloc->update(['pay' => 1, 'payda' => $n]);
            }
            $count++;
        }

        Notification::make()
            ->title($count.' dairenin hissesi eşit bölündü')
            ->success()
            ->send();
    }
}
