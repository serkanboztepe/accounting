<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Contracts\Schemas\ContractForm;
use App\Filament\Resources\Contracts\Widgets\ContractSummary;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class EditContract extends EditRecord
{
    protected static string $resource = ContractResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Yazdır')
                ->icon(Heroicon::OutlinedPrinter)
                ->url(fn () => route('contract.print', $this->record))
                ->extraAttributes(fn () => \App\Support\Printing::iframeAttributes(route('contract.print', $this->record))),

            // Sözleşme bilgileri (cari/proje/başlık/durum/tutar/tarih/not) — cari deseni gibi
            // ⚙ Ayarlar modalından düzenlenir; sayfa gövdesi ilişki yöneticilerine ayrıldı.
            // 'direction' modalda yok → kayıttaki yön korunur.
            Action::make('settings')
                ->label('Ayarlar')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->modalHeading('Sözleşme Bilgileri')
                ->modalSubmitActionLabel('Kaydet')
                ->fillForm(fn (): array => [
                    'party_id'      => $this->record->party_id,
                    'project_id'    => $this->record->project_id,
                    'title'         => $this->record->title,
                    'contract_type' => $this->record->contract_type,
                    'total_amount'  => $this->record->total_amount,
                    'notes'         => $this->record->notes,
                ])
                ->schema(ContractForm::fields())
                ->action(fn (array $data) => $this->record->update($data)),

            DeleteAction::make(),
        ];
    }

    /**
     * Gövdeden inline form + alttaki Kaydet bar'ı kaldır — düzenleme ⚙ Ayarlar modalından.
     * Gövde ilişki yöneticilerine (kalemler/ödemeler/teslimatlar/faturalar) kalır.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getRelationManagersContentComponent(),
        ]);
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ContractSummary::class,
        ];
    }
}
